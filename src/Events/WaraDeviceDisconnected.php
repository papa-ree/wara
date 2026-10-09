<?php

namespace Bale\Wara\Events;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Models\WaraSession;
use Carbon\Carbon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipancarkan saat device yang tadinya siap berubah menjadi tidak siap (§8.3).
 *
 * Ini bukan "event gateway" — gateway tidak mengirim event disconnect sama
 * sekali. Pemicunya adalah polling `wara:poll-status` dan sinkronisasi
 * `wara:sync-devices`: keduanya mendeteksi transisi `logged_in -> tidak siap`,
 * lalu memancarkan event ini **setelah** baris `wara_sessions` ditulis.
 *
 * Urut-tulis inilah yang menjadi penangkal duplikat: begitu `is_logged_in`
 * bernilai false di database, poll/sync berikutnya membaca keadaan lama yang
 * sudah tidak *usable*, jadi event tidak dipancarkan dua kali. Beberapa jalur
 * deteksi (poll, sync, orphan) aman berjalan paralel.
 *
 * 🔴 `session` membawa **JID mentah** (nomor telepon device). Jangan pernah
 * menyebarkannya ke Slack/log tanpa penyamaran — `bale/srikandi` dan log
 * activity sengaja merahasiakannya.
 */
final class WaraDeviceDisconnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Session yang baru saja ditulis ke database (keadaan BARU). */
        public readonly WaraSession $session,
        /** Keadaan BARU: `disconnected`, `connecting`, atau `connected` (bukan `logged_in`). */
        public readonly DeviceState $state,
        /** Keadaan LAMA (harus `logged_in` alias usable) saat event dipancarkan. */
        public readonly ?DeviceState $previous = null,
        /** Waktu deteksi transisi. Default `now()`. */
        public readonly ?Carbon $detectedAt = null,
    ) {
        $this->detectedAt ??= now();
    }
}
