<?php

namespace Bale\Wara\Exceptions;

/**
 * Device yang dipilih tidak memenuhi syarat untuk mengirim (PRD §6.5.2).
 *
 * PENTING: kelas ini TIDAK boleh digabung dengan kegagalan jaringan. "Device
 * tidak siap" adalah kondisi yang harus diperbaiki admin, sedangkan timeout atau
 * 5xx adalah kegagalan transien. Menggabungkannya membuat Handle::class untuk
 * menentukan apakah perlu cooldown atau tidak kehilangan makna.
 */
class DeviceNotReadyException extends WaraException
{
    /**
     * @param  array<string, bool|null>  $conditions  syarat yang gagal terpenuhi.
     */
    protected function __construct(
        public readonly string $context,
        public readonly ?string $deviceName,
        public readonly ?string $deviceJid,
        public readonly array $conditions,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, bool|null>  $conditions
     */
    public static function forDevice(
        string $context,
        ?string $deviceName,
        ?string $deviceJid,
        array $conditions
    ): self {
        $failed = [];

        foreach ($conditions as $condition => $value) {
            if ($value === true) {
                continue;
            }

            $failed[] = $value === null
                ? sprintf('%s = null (perlu pairing)', $condition)
                : sprintf('%s = %s', $condition, $value ? 'true' : 'false');
        }

        $identity = sprintf(
            'nama device "%s", JID %s',
            $deviceName ?? '(tidak diketahui)',
            $deviceJid ?? '(kosong / belum pairing)'
        );

        $message = sprintf(
            'Device pengirim untuk %s (%s) tidak siap: %s.%s Perbaiki pairing di gateway, lalu jalankan: php artisan wara:sync-devices',
            $context,
            $identity,
            implode(', ', $failed),
            PHP_EOL
        );

        return new self($context, $deviceName, $deviceJid, $conditions, $message);
    }

    /**
     * Device tujuan ada di config/database tapi tidak ditemukan di gateway.
     */
    public static function notFound(string $context, ?string $deviceName, ?string $deviceJid): self
    {
        $message = sprintf(
            'Device pengirim untuk %s (nama device "%s", JID %s) tidak lagi terdaftar di gateway.%s Jalankan: php artisan wara:sync-devices',
            $context,
            $deviceName ?? '(tidak diketahui)',
            $deviceJid ?? '(kosong)',
            PHP_EOL
        );

        return new self($context, $deviceName, $deviceJid, [], $message);
    }
}
