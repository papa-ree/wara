<?php

namespace Bale\Wara\Data;

use Carbon\CarbonImmutable;

/**
 * Satu percakapan dari chat storage gateway.
 *
 * `phone` NULL berarti chat itu bukan percakapan telepon (grup, newsletter,
 * broadcast, atau pengguna yang menyembunyikan nomor) dan tidak bisa dipetakan
 * ke satu nomor.
 */
final readonly class ChatSummary
{
    public function __construct(
        public ?string $phone = null,
        public ?string $name = null,
        public ?CarbonImmutable $lastMessageAt = null,
    ) {}
}
