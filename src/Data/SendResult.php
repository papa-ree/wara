<?php

namespace Bale\Wara\Data;

/**
 * Hasil satu pengiriman pesan.
 *
 * PENTING (PRD §8.1): `accepted` hanya berarti request diterima gateway, BUKAN
 * pesan sampai di tujuan. Kebingungan ini adalah sumber misinformasi klasik,
 * jadi nama field-nya sengaja `accepted`, bukan `delivered`.
 */
final readonly class SendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $messageId = null,
        public ?string $status = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function accepted(?string $messageId = null, ?string $status = null): self
    {
        return new self(accepted: true, messageId: $messageId, status: $status);
    }

    public static function rejected(string $errorCode, string $errorMessage): self
    {
        return new self(accepted: false, errorCode: $errorCode, errorMessage: $errorMessage);
    }
}
