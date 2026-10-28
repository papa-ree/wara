<?php

namespace Bale\Wara\Data;

/**
 * Kode pairing yang ditampilkan ke admin (PRD §15.4).
 *
 * Kode ini dimasukkan ke WhatsApp di ponsel: *Perangkat tertaut → Tautkan
 * dengan nomor telepon*. Tidak ada yang disimpan — kode kedaluwarsa sendiri.
 */
final readonly class PairingCode
{
    public function __construct(
        public string $deviceId,
        public string $code,
    ) {}
}
