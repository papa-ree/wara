<?php

namespace Bale\Wara\Exceptions;

use RuntimeException;

/**
 * Basis seluruh kegagalan di layer Wara.
 *
 * Konsumsi (scraper via backend) bisa menangkap satu kelas ini untuk
 * membedakan "masalah Bale" dari "masalah jaringan umum".
 */
class WaraException extends RuntimeException
{
    public static function misconfigured(string $variable, string $expected, ?string $actual = null): self
    {
        $message = sprintf(
            'Konfigurasi Wara tidak valid: %s harus %s',
            $variable,
            $expected
        );

        if ($actual !== null && $actual !== '') {
            $message .= sprintf(' (nilai saat ini: "%s")', $actual);
        }

        return new self($message);
    }
}
