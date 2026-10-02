<?php

namespace Bale\Wara\Exceptions;

/**
 * Device yang diminta gateway tidak ada (PRD §6.5.3).
 *
 * Dibuat terpisah dari `DeviceNotReadyException` karena dua hal ini berbeda
 * tindakan: device offline = "pairing ulang", device hilang = "sinkron ulang".
 */
class DeviceNotFoundException extends WaraException
{
    public static function forSessionId(string $sessionId): self
    {
        return new self(sprintf(
            'Device "%s" tidak lagi terdaftar di gateway. Jalankan: php artisan wara:sync-devices',
            $sessionId
        ));
    }
}
