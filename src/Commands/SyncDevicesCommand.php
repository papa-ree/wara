<?php

namespace Bale\Wara\Commands;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Illuminate\Console\Command;

/**
 * Sinkronkan katalog device dari gateway (PRD §7.1).
 *
 * Prinsip penting: device yang hilang dari gateway TIDAK dihapus, hanya
 * ditandai `is_present = false`. Menghapus baris itu akan diam-diam membuang
 * keputusan admin yang tersimpan di kolom `purpose`.
 */
class SyncDevicesCommand extends Command
{
    protected $signature = 'wara:sync-devices
                            {--dry-run : Tampilkan hasil tanpa menulis ke database}';

    protected $description = 'Sinkronkan katalog device dari gateway WhatsApp ke wara_sessions';

    public function handle(WaraManager $wara): int
    {
        try {
            $devices = $wara->listDevices();
        } catch (GatewayException $e) {
            $this->error('Gagal menghubungi gateway: '.$e->getMessage());
            $this->warn('Data lama dibiarkan apa adanya — lebih baik menampilkan data basi daripada menghapus penugasan admin.');

            return self::FAILURE;
        }

        if ($devices->count() === 0) {
            $this->warn('Gateway tidak melaporkan device apa pun. Baris lama tidak diubah.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $seen = [];

        $this->line(sprintf('Gateway melaporkan %d device.', $devices->count()));

        foreach ($devices as $device) {
            if ($device->sessionId === '') {
                $this->warn('  - Lewati: baris device tanpa device_id');

                continue;
            }

            $seen[] = $device->sessionId;

            $session = WaraSession::query()->firstOrNew(['device_id' => $device->sessionId]);

            $previous = $session->currentState();

            $session->fill([
                'jid' => $device->jid,
                'display_name' => $device->displayName,
                'is_present' => true,
                'is_connected' => $device->state !== DeviceState::Disconnected,
                'is_logged_in' => $device->state->isUsable(),
                'last_seen_at' => now(),
            ]);

            $label = $device->displayName ?? '(tanpa nama)';

            if ($dryRun) {
                $this->line(sprintf('  - Akan disimpan: %s [%s] state=%s', $label, $device->sessionId, $device->state->value));

                continue;
            }

            // 🔴 Penugasan tujuan layanan TIDAK ada di tabel ini lagi.
            //
            // `purpose` pindah ke `wara_routes`, yang unik per CLIENT. Perintah ini
            // hanya menyusun katalog device: gateway tidak tahu client mana yang
            // memakai device, dan mengarangnya di sini akan mencangkok
            // keputusan admin.
            $session->save();

            // Transisi `logged_in -> tidak siap` memancarkan WaraDeviceDisconnected
            // (alert Slack). Ditulis setelah `save()` supaya keadaan baru sudah
            // tersimpan saat listener membaca session.
            $wara->notifyWhenDeviceBecomesUnusable($session, $previous, $device->state);

            $this->line(sprintf('  + Tersimpan: %s [%s] state=%s', $label, $device->sessionId, $device->state->value));
        }

        $orphaned = WaraSession::query()
            ->whereNotIn('device_id', $seen)
            ->where('is_present', true)
            ->get();

        foreach ($orphaned as $session) {
            if ($dryRun) {
                $this->line(sprintf('  - Akan ditandai offline: %s [%s]', $session->display_name ?? '(tanpa nama)', $session->device_id));

                continue;
            }

            $previous = $session->currentState();

            $session->forceFill([
                'is_present' => false,
                'is_connected' => false,
                'is_logged_in' => false,
            ])->save();

            // Device hilang dari katalog = tidak tersedia, sama seperti
            // disconnected. Tetap lewat jalur yang sama supaya alert Slack
            // (dan consumer lain) mendengar satu kontrak yang konsisten.
            $wara->notifyWhenDeviceBecomesUnusable($session, $previous, DeviceState::Disconnected);

            $this->warn(sprintf('  ! Ditandai offline: %s [%s]', $session->display_name ?? '(tanpa nama)', $session->device_id));
        }

        $this->newLine();
        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
