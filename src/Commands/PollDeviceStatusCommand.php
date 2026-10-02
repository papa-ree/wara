<?php

namespace Bale\Wara\Commands;

use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Illuminate\Console\Command;

/**
 * Deteksi logged-out / banned (§8.3).
 *
 * GOWA tidak memberi event pembatasan akun, jadi status device harus dipoll
 * berkala — `wara:sync-devices` (5 menit) terlalu lambat untuk mendeteksi
 * device yang logout di tengah interval.
 *
 * Catatan penting: ini **bukan** deteksi ban yang andal. GOWA tidak melaporkan
 * alasan disconnect (TOS block, kualitas akun turun, dan sejenisnya), jadi Bale
 * tidak bisa membedakan "seseorang logout dari HP" dengan "akun dibatasi".
 * Yang dilakukan di sini adalah menandai kondisi dan membuatnya terlihat di
 * dashboard, bukan menyimpulkan penyebabnya.
 */
class PollDeviceStatusCommand extends Command
{
    protected $signature = 'wara:poll-status
                            {--device= : Batasi polling ke satu device_id}
                            {--json : Keluarkan hasil dalam format JSON}';

    protected $description = 'Periksa status device gateway dan tandai yang perlu pairing ulang';

    public function handle(WaraManager $wara): int
    {
        $query = WaraSession::query()->where('is_present', true);

        if ($deviceId = $this->option('device')) {
            $query->where('device_id', $deviceId);
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $this->info('Tidak ada device untuk dipoll.');

            return self::SUCCESS;
        }

        $report = [];
        $unhealthy = 0;

        foreach ($sessions as $session) {
            $entry = [
                'device_id' => $session->device_id,
                'display_name' => $session->display_name,
                'before' => $session->state_label,
            ];

            try {
                $status = $wara->pollDeviceStatus($session);
                $entry['after'] = $status->state->value;
                $entry['needs_reattention'] = $status->state->needsAttention();
            } catch (GatewayException $e) {
                // Biarkan baris lama apa adanya — lebih baik menampilkan data
                // basi daripada menandai device salah karena gateway sempat
                // tidak merespons.
                $entry['error'] = $e->getMessage();
                $report[] = $entry;

                $this->warn(sprintf('  ! Gagal poll %s: %s', $session->device_id, $e->getMessage()));

                continue;
            } catch (Throwable $e) {
                $entry['error'] = $e->getMessage();
                $report[] = $entry;

                $this->warn(sprintf('  ! Gagal poll %s: %s', $session->device_id, $e->getMessage()));

                continue;
            }

            $report[] = $entry;

            if ($entry['needs_reattention']) {
                $unhealthy++;
                $this->warn(sprintf(
                    '  ! PERLU PASANG ULANG: %s [%s] %s -> %s',
                    $session->display_name ?? '(tanpa nama)',
                    $session->device_id,
                    $entry['before'],
                    $entry['after']
                ));

                continue;
            }

            $this->line(sprintf(
                '  . %s [%s] %s -> %s',
                $session->display_name ?? '(tanpa nama)',
                $session->device_id,
                $entry['before'],
                $entry['after']
            ));
        }

        if ($this->option('json')) {
            $this->newLine();
            $this->line(json_encode([
                'total' => $sessions->count(),
                'unhealthy' => $unhealthy,
                'devices' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();

        if ($unhealthy > 0) {
            $this->error(sprintf(
                '%d device perlu pairing ulang. GOWA tidak melaporkan alasan disconnect, jadi ini belum berarti akun diblokir.',
                $unhealthy
            ));

            return self::FAILURE;
        }

        $this->info('Semua device dalam keadaan baik.');

        return self::SUCCESS;
    }
}
