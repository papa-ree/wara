<?php

namespace Bale\Wara\Exceptions;

/**
 * Device sedang diistirahatkan setelah pengiriman gagal (PRD §8.2).
 *
 * Turunan `CooldownException` karena bagi pemanggil artinya sama: permintaan
 * sah, tapi harus menunggu `retryAfter` detik. Semua jalur sudah menangani
 * `CooldownException` (job: `release()`, channel: `retry_after`, API: 429 +
 * `Retry-After`), jadi tidak ada `catch` baru yang bisa terlupa di kemudian
 * hari. Yang berbeda hanya `phone`, di sini berisi id device.
 */
class BackoffException extends CooldownException
{
    public static function forDevice(string $deviceId, int $retryAfter): self
    {
        return new self(
            $deviceId,
            $retryAfter,
            0,
            sprintf('Device %s sedang dalam backoff: coba lagi setelah %d detik.', $deviceId, $retryAfter)
        );
    }
}
