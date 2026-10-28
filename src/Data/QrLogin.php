<?php

namespace Bale\Wara\Data;

/**
 * Hasil permintaan pairing QR (PRD §15.4).
 *
 * 🔴 `imageDataUri` sudah berupa data-URI hasil unduhan **server-side**
 * (keputusan #29). URL host gateway tidak pernah sampai ke browser, jadi
 * jaringan tertutup tetap bisa pairing dan invariant §11.2 tetap utuh.
 */
final readonly class QrLogin
{
    public function __construct(
        public string $deviceId,
        public int $expiresInSeconds,
        public string $imageDataUri,
    ) {}
}
