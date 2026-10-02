<?php

namespace Bale\Wara\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Pacing & anti-ban (PRD §8.2).
 *
 * GOWA tidak punya rem pengiriman sama sekali (§8.1) — tidak ada pacing, tidak
 * ada pembatas laju, tidak ada konfirmasi sinkron. Jadi seluruh pengaman harus
 * ada di Bale. Tanpa kelas ini, pola "captcha salah → kirim ulang" berubah
 * jadi burst pesan dari nomor yang sama, dan itu penyebab paling cepat
 * restriksi akun WhatsApp.
 *
 * Keputusan desain penting: cooldown **MENAHAN**, bukan mengembalikan error.
 * Memanggil kode dengan nomor yang sedang dalam cooldown harus memblokir dan
 * menunggu, karena pemanggil (scraper login) tidak punya mekanisme backoff
 * sendiri. Menolak dengan exception hanya memindahkan masalah ke pemanggil.
 */
class SendPacer
{
    /**
     * Kunci cache untuk cooldown per nomor.
     */
    public function phoneKey(string $phone): string
    {
        return 'wara:pacer:phone:'.$phone;
    }

    /**
     * Kunci cache untuk batas percobaan per sesi login.
     */
    public function sessionKey(string $sessionKey): string
    {
        return 'wara:pacer:session:'.$sessionKey;
    }

    /**
     * Kunci cache untuk backoff device.
     */
    public function deviceKey(string $deviceId): string
    {
        return 'wara:pacer:device:'.$deviceId;
    }

    /**
     * Sisa detik cooldown untuk satu nomor. 0 = boleh kirim.
     */
    public function phoneCooldownRemaining(string $phone): int
    {
        $seconds = (int) config('wara.cooldown_seconds', 60);

        if ($seconds <= 0) {
            return 0;
        }

        return max(0, $seconds - $this->elapsedSinceLastSend($phone));
    }

    /**
     * Berapa detik sejak pesan terakhir ke nomor ini dikirim.
     */
    public function elapsedSinceLastSend(string $phone): int
    {
        $payload = Cache::get($this->phoneKey($phone));

        if (! is_array($payload)) {
            return PHP_INT_MAX;
        }

        return time() - (int) ($payload['at'] ?? 0);
    }

    /**
     * Catat bahwa pesan ke nomor ini baru saja dikirim.
     */
    public function recordSend(string $phone, ?int $attempt = null): void
    {
        $seconds = (int) config('wara.cooldown_seconds', 60);

        // TTL minimal 1 detik supaya kunci selalu ada walau cooldown dimatikan,
        // sehingga `elapsedSinceLastSend()` tetap punya titik waktu acuan.
        Cache::put($this->phoneKey($phone), [
            'at' => time(),
            'attempt' => $attempt,
        ], max(1, $seconds) * 2);
    }

    /**
     * Berapa percobaan kirim yang sudah terjadi pada sesi login ini.
     */
    public function attemptsInSession(string $sessionKey): int
    {
        return (int) Cache::get($this->sessionKey($sessionKey), 0);
    }

    /**
     * Tambah penghitung percobaan untuk satu sesi login.
     */
    public function recordAttempt(string $sessionKey): int
    {
        $seconds = $this->sessionWindowSeconds();
        $key = $this->sessionKey($sessionKey);

        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, max(60, $seconds * 2));

        return $attempts;
    }

    /**
     * Sisa percobaan yang boleh dipakai di sesi ini.
     */
    public function sessionAttemptsRemaining(string $sessionKey): int
    {
        $max = (int) config('wara.max_attempts_per_session', 3);

        if ($max <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $max - $this->attemptsInSession($sessionKey));
    }

    /**
     * Jendela waktu untuk menghitung percobaan.
     *
     * Dipakai `CAPTCHA_MAX_ATTEMPTS` di sisi scraper sebagai patokan, supaya
     * dua sisi punya pemahaman yang sama tentang satu "sesi login".
     */
    public function sessionWindowSeconds(): int
    {
        return (int) config('wara.session_window_seconds', 900);
    }

    /**
     * Tahan pengiriman sampai cooldown nomor habis.
     *
     * Mengembalikan jumlah detik yang benar-benar ditunggu.
     */
    public function waitForPhoneCooldown(string $phone): int
    {
        $remaining = $this->phoneCooldownRemaining($phone);

        if ($remaining <= 0) {
            return 0;
        }

        // `usleep` menerima mikrodetik. Tambahan 1 detik supaya jam dinding
        // tidak membuat kita hesitasi sebelum cooldown benar-benar habis.
        usleep(($remaining + 1) * 1_000_000);

        return $remaining;
    }

    /**
     * Apakah device sedang dalam masa backoff?
     */
    public function deviceBackoffRemaining(string $deviceId): int
    {
        $seconds = (int) config('wara.device_backoff_seconds', 300);

        if ($seconds <= 0) {
            return 0;
        }

        $until = (int) Cache::get($this->deviceKey($deviceId), 0);

        return max(0, $until - time());
    }

    /**
     * Tandai device dalam masa backoff setelah gagal.
     */
    public function markDeviceBackoff(string $deviceId): int
    {
        $seconds = (int) config('wara.device_backoff_seconds', 300);

        Cache::put($this->deviceKey($deviceId), time() + max(0, $seconds), max(1, $seconds) * 2);

        return $seconds;
    }

    /**
     * Bersihkan masa backoff device setelah operasi berhasil.
     */
    public function clearDeviceBackoff(string $deviceId): void
    {
        Cache::forget($this->deviceKey($deviceId));
    }
}
