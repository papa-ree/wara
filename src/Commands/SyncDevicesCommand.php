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

            $session->fill([
                'jid' => $device->jid,
                'display_name' => $device->displayName,
                'is_present' => true,
                'is_connected' => $device->state !== DeviceState::Disconnected,
                'is_logged_in' => $device->state->isUsable(),
                'last_seen_at' => now(),
            ]);

            $label = $device->displayName ?? '(tanpa nama)';
            $purpose = $session->purpose ? ' purpose='.$session->purpose : ' purpose=(belum ditugaskan)';

            if ($dryRun) {
                $this->line(sprintf('  - Akan disimpan: %s [%s] state=%s%s', $label, $device->sessionId, $device->state->value, $purpose));

                continue;
            }

            // purpose TIDAK pernah ditulis di sini — itu keputusan admin (PRD §7).
            $session->save();

            $this->line(sprintf('  + Tersimpan: %s [%s] state=%s%s', $label, $device->sessionId, $device->state->value, $purpose));
        }

        $orphaned = WaraSession::query()
            ->whereNotIn('device_id', $seen)
            ->where('is_present', true)
            ->get();

        foreach ($orphaned as $session) {
            $purpose = $session->purpose ? ' purpose='.$session->purpose.' (dippertahankan)' : '';

            if ($dryRun) {
                $this->line(sprintf('  - Akan ditandai offline: %s [%s]%s', $session->display_name ?? '(tanpa nama)', $session->device_id, $purpose));

                continue;
            }

            $session->forceFill([
                'is_present' => false,
                'is_connected' => false,
                'is_logged_in' => false,
            ])->save();

            $this->warn(sprintf('  ! Ditandai offline: %s [%s]%s', $session->display_name ?? '(tanpa nama)', $session->device_id, $purpose));
        }

        $this->newLine();
        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
