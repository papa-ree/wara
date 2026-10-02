<?php

namespace Bale\Wara\Exceptions;

use Throwable;

/**
 * Kegagalan lapisan gateway: HTTP error, body tidak bisa di-parse, atau
 * service menjawab dengan envelope error yang tidak dikenal.
 *
 * Berbeda dari `DeviceNotReadyException` (kondisi yang butuh tindakan admin),
 * kelas ini murni kegagalan transien sehingga layak dicoba ulang dengan
 * backoff (PRD §8.2).
 */
class GatewayException extends WaraException
{
    public static function requestFailed(string $url, int $status, ?string $body = null, ?Throwable $previous = null): self
    {
        $message = sprintf('Gateway menolak request ke %s dengan HTTP %d', $url, $status);

        if (filled($body)) {
            $message .= ': '.mb_substr($body, 0, 300);
        }

        return new self($message, 0, $previous);
    }

    public static function unreachable(string $url, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Tidak bisa menghubungi gateway di %s. Pastikan service berjalan.', $url),
            0,
            $previous
        );
    }

    public static function malformedResponse(string $url, string $reason): self
    {
        return new self(sprintf('Response gateway dari %s tidak bisa dibaca: %s', $url, $reason));
    }
}
