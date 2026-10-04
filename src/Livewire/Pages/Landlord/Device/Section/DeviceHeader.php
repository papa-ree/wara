<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device\Section;

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeviceHeader extends Component
{
    /**
     * Berapa lama sesi yang habis masih menolak percobaan baru, dalam menit.
     *
     * 🔴 Ditembak dari sini, bukan `config()` di dalam Blade. Template hanya
     *-boleh tahu bentuk data, bukan cara membacanya — kalau angka ini diubah
     * bentuk sumbernya, banner ikut berubah tanpa ada yang menyadarinya.
     *
     * Dipakai banner sesi habis untuk memberitahu admin kapan sesi akan
     * terbuka lagi, supaya "menunggu" itu punya akhir yang jelas.
     */
    public function getSessionWindowMinutesProperty(): int
    {
        return (int) round((int) config('wara.session_window_seconds', 900) / 60);
    }

    public function syncNow(WaraManager $wara): void
    {
        try {
            $devices = $wara->listDevices();
        } catch (WaraException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', type: 'success', message: __(
            'Sinkronisasi selesai. :count device dilaporkan gateway.',
            ['count' => $devices->count()],
        ));

        $this->dispatch('refresh-device-table');
    }

    /**
     * Device yang perlu perhatian admin (§8.3): status buruk yang bertahan
     * melewati ambang `offline_alert_after_minutes`.
     */
    #[Computed]
    public function needsReattention()
    {
        return WaraSession::query()
            ->where('is_present', true)
            ->get()
            ->filter(fn (WaraSession $session) => $session->needsReattention())
            ->values();
    }

    /**
     * Sesi login yang baru saja menyentuh batas percobaan kirim (§8.2).
     *
     * 🔴 INI BUKAN masalah device. Sesi habis tidak ada hubungannya dengan
     * pairing atau koneksi — nomor masih aktif, device masih online, tapi satu
     * sesi login sudah menghabiskan jatah percobaannya dan percobaan berikutnya
     * akan ditolak sampai jendela `session_window_seconds` lewat.
     *
     * Yang ditampilkan hanya sesi dalam `exhausted_alert_after_minutes`
     * terakhir, karena itulah yang masih perlu tindakan admin. Sesi lama sudah
     * auto-unblock dan tidak ada yang bisa dilakukan padanya.
     *
     * 🔴 PUTUSAN PRODUK (4 Okt 2026): ini PERINGATAN, bukan stop condition.
     * TOPIK §8.1 meminta "menunggu campur tangan manusia", tapi yang
     * disepakati adalah auto-unblock setelah jendela 900 detik. Konsekuensinya
     * nyata: admin yang belum sempat bereaksi dalam 15 menit akan mendapat
     * pesan masuk dari nomor yang sama. Itu konsekuensi yang diterima, dan
     * dicatat di docs/wara/TODO.md supaya tidak dibaca sebagai item terlewat.
     */
    #[Computed]
    public function recentlyExhaustedSessions()
    {
        $minutes = (int) config('wara.exhausted_alert_after_minutes', 15);

        if ($minutes <= 0) {
            return collect();
        }

        return WaraLog::query()
            ->exhausted($minutes)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('session_key')
            ->map(fn ($rows) => $rows->first())
            ->values();
    }

    /**
     * Device tidak siap tapi belum melewati ambang — masih dalam masa
     * tenggang, jadi ditampilkan sebagai informasi, bukan peringatan.
     */
    #[Computed]
    public function recentlyUnhealthy()
    {
        $threshold = (int) config('wara.offline_alert_after_minutes', 10);

        return WaraSession::query()
            ->where('is_present', true)
            ->where('is_logged_in', false)
            ->get()
            ->filter(fn (WaraSession $session) => ! $session->needsReattention())
            ->filter(function (WaraSession $session) use ($threshold) {
                // `last_seen_at` null = belum pernah ter-poll, bukan alarming.
                return $session->last_seen_at === null
                    || $session->last_seen_at->gte(now()->subMinutes($threshold));
            })
            ->values();
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.section.device-header');
    }
}
