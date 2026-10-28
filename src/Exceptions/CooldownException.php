<?php

namespace Bale\Wara\Exceptions;

/**
 * Cooldown per nomor belum habis dan menunggunya akan melewati batas blokir.
 *
 * 🔴 KENAPA BUKAN `usleep()` PENUH LAGI.
 *
 * Dulu `SendPacer::waitForPhoneCooldown()` selalu menidurkan proses sampai
 * cooldown habis. Dengan `cooldown_seconds = 60` itu berarti `usleep` selama
 * 61 detik — dan di request HTTP (`max_execution_time = 60`, FrankenPHP)
 * PHP mematikan proses tepat di tengah tidur dengan
 * "Maximum execution time of 60 seconds exceeded".
 *
 * Penantian sekarang dibatasi `wara.max_blocking_wait_seconds`:
 *  - sisa yang kecil (<= batas) tetap ditunggu diam-diam, supaya pacing halus
 *    tidak perlu diketahui pemanggil;
 *  - sisa yang besar dilempar sebagai exception ini, supaya pemanggil HTTP
 *    bisa membalas `429` + `Retry-After` dan pemanggil antrean bisa
 *    menjadwalkan ulang, bukan menahan worker.
 *
 * `retryAfter` adalah sisa detik cooldown, `maxBlockingSeconds` adalah batas
 * yang berlaku saat itu (0 = penantian otomatis dimatikan).
 */
class CooldownException extends WaraException
{
    protected function __construct(
        public readonly string $phone,
        public readonly int $retryAfter,
        public readonly int $maxBlockingSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forPhone(string $phone, int $retryAfter, int $maxBlockingSeconds): self
    {
        $message = $maxBlockingSeconds > 0
            ? sprintf(
                'Nomor %s masih dalam cooldown: sisa %d detik, sedangkan batas tunggu otomatis %d detik. Coba lagi setelah %d detik.',
                self::mask($phone),
                $retryAfter,
                $maxBlockingSeconds,
                $retryAfter,
            )
            : sprintf(
                'Nomor %s masih dalam cooldown %d detik dan penantian otomatis dimatikan (WARA_MAX_BLOCKING_WAIT=0). Coba lagi setelah %d detik.',
                self::mask($phone),
                $retryAfter,
                $retryAfter,
            );

        return new self($phone, $retryAfter, $maxBlockingSeconds, $message);
    }

    /**
     * Nomor tidak boleh tampil utuh di pesan/log.
     */
    protected static function mask(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return $phone;
        }

        return str_repeat('*', $length - 4).substr($phone, -4);
    }
}
