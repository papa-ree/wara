<?php

namespace Bale\Wara\Exceptions;

use Bale\Wara\Models\WaraClient;

/**
 * Device yang diminta gateway tidak ada (PRD §6.5.3).
 *
 * Dibuat terpisah dari `DeviceNotReadyException` karena dua hal ini berbeda
 * tindakan: device offline = "pairing ulang", device hilang = "sinkron ulang".
 *
 * 🔴 Ketiga factory di bawah sengaja menyebut NAMA CLIENT, bukan hanya
 * purpose. Schema lama menjawab "device mana untuk purpose ini?" secara
 * global, jadi pesan errornya tidak pernah memberi tahu siapa yang sebenarnya
 * sedang bertanya - dan itulah yang membuat masalah router sulit didiagnosis.
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

    /**
     * Client tidak punya route untuk purpose tersebut.
     *
     * Ini kondisi konfigurasi, bukan kegagalan device: perbaikannya di UI
     * admin, bukan dengan `wara:sync-devices`.
     */
    public static function forPurpose(WaraClient $client, string $purpose): self
    {
        return new self(sprintf(
            'Client "%s" (%s) belum punya route untuk purpose "%s". '
            .'Petakan purpose ke device di menu Wara > Client.',
            $client->name,
            $client->type,
            $purpose
        ));
    }

    /**
     * Route ada, tapi outbound-nya sengaja dimatikan admin.
     *
     * Pesannya eksplisit soal MATI, bukan "tidak ditemukan", supaya tidak
     * ada yang mengira route-nya hilang lalu membuatnya ulang.
     */
    public static function outboundDisabled(WaraClient $client, string $purpose, string $deviceId): self
    {
        return new self(sprintf(
            'Outbound client "%s" untuk purpose "%s" dimatikan (device "%s"). '
            .'Nyalakan di menu Wara > Client kalau memang harus dikirim.',
            $client->name,
            $purpose,
            $deviceId
        ));
    }
}
