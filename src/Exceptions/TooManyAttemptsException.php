<?php

namespace Bale\Wara\Exceptions;

use Bale\Wara\Support\SendPacer;

/**
 * Batas percobaan kirim pada satu sesi login sudah habis (PRD §8.2).
 *
 * Terpisah dari cooldown: cooldown bersifat sementara per nomor, sedangkan ini
 * batas keras per sesi. Setelah terlampaui, pengiriman ditolak sampai jendela
 * sesi berakhir — supaya pola "captcha salah → kirim ulang" tidak berubah
 * jadi burst.
 */
class TooManyAttemptsException extends WaraException
{
    protected function __construct(
        public readonly int $attempts,
        public readonly int $limit,
        public readonly int $windowSeconds,
        public readonly int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forSession(string $sessionKey, SendPacer $pacer, int $attempts): self
    {
        $limit = (int) config('wara.max_attempts_per_session', 3);
        $window = $pacer->sessionWindowSeconds();
        $retryAfter = $window;

        $message = sprintf(
            'Batas percobaan kirim pada sesi "%s" habis: %d dari %d percobaan terpakai dalam jendela %d menit.%s'
            .' Tunggu jendela berakhir sebelum mencoba lagi, atau naikkan WARA_MAX_ATTEMPTS_PER_SESSION bila memang perlu.%s'
            .' Dikirim dari device: %s',
            $sessionKey,
            $attempts,
            $limit,
            (int) round($window / 60),
            PHP_EOL,
            PHP_EOL,
            'lihat wara_logs untuk riwayat percobaan sesi ini'
        );

        return new self($attempts, $limit, $window, $retryAfter, $message);
    }
}
