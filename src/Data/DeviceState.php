<?php

namespace Bale\Wara\Data;

/**
 * Status device yang sudah dinormalisasi (PRD §8.3).
 *
 * Enum ini adalah "bahasa internal" Bale. Nama state milik gateway tidak boleh
 * bocor ke luar adapter (PRD §11.2).
 */
enum DeviceState: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case LoggedIn = 'logged_in';

    /**
     * Device yang benar-benar bisa mengirim pesan.
     */
    public function isUsable(): bool
    {
        return $this === self::LoggedIn;
    }

    /**
     * Kondisi yang perlu perhatian admin (§8.3): perangkat masih ada di gateway
     * tapi tidak dalam keadaan siap menerima pesan.
     */
    public function needsAttention(): bool
    {
        return $this === self::Disconnected || $this === self::Connecting;
    }
}
