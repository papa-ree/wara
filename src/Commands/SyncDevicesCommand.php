<?php

namespace Bale\Wara\Commands;

use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\WaraManager;
use Illuminate\Console\Command;

/**
 * Sinkronkan katalog device dari gateway (PRD §7.1).
 *
 * Logika sinkron SEBENARNYA ada di {@see WaraManager::syncDevices()}; command
 * ini hanya membungkusnya untuk konsol. Tombol "Sinkronkan Sekarang" di halaman
 * Device memakai method yang sama — kalau ada satu cabang yang diperbaiki,
 * keduanya ikut terperbaiki.
 */
class SyncDevicesCommand extends Command
{
    protected $signature = 'wara:sync-devices
                            {--dry-run : Tampilkan hasil tanpa menulis ke database}';

    protected $description = 'Sinkronkan katalog device dari gateway WhatsApp ke wara_sessions';

    public function handle(WaraManager $wara): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $wara->syncDevices($dryRun);
        } catch (GatewayException $e) {
            $this->error('Gagal menghubungi gateway: '.$e->getMessage());
            $this->warn('Data lama dibiarkan apa adanya — lebih baik menampilkan data basi daripada menghapus penugasan admin.');

            return self::FAILURE;
        }

        if ($result['total'] === 0) {
            $this->warn('Gateway tidak melaporkan device apa pun. Baris lama tidak diubah.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Gateway melaporkan %d device.', $result['total']));

        foreach ($result['saved'] as $saved) {
            $label = $saved['display_name'] ?? '(tanpa nama)';

            if ($dryRun) {
                $this->line(sprintf('  - Akan disimpan: %s [%s] state=%s', $label, $saved['device_id'], $saved['state']->value));

                continue;
            }

            $this->line(sprintf('  + Tersimpan: %s [%s] state=%s', $label, $saved['device_id'], $saved['state']->value));
        }

        if ($result['skipped'] > 0) {
            $this->warn(sprintf('  - Lewati: %d baris device tanpa device_id', $result['skipped']));
        }

        foreach ($result['orphaned'] as $orphaned) {
            $label = $orphaned['display_name'] ?? '(tanpa nama)';

            if ($dryRun) {
                $this->line(sprintf('  - Akan ditandai offline: %s [%s]', $label, $orphaned['device_id']));

                continue;
            }

            $this->warn(sprintf('  ! Ditandai offline: %s [%s]', $label, $orphaned['device_id']));
        }

        foreach ($result['deleted'] as $deleted) {
            $label = $deleted['display_name'] ?? '(tanpa nama)';

            if ($dryRun) {
                $this->line(sprintf('  - Akan dihapus (tanpa route): %s [%s]', $label, $deleted['device_id']));

                continue;
            }

            $this->warn(sprintf('  x Dihapus (tanpa route): %s [%s]', $label, $deleted['device_id']));
        }

        $this->newLine();
        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }
}
