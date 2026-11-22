<?php

namespace Bale\Wara\Data;

use Carbon\CarbonImmutable;

/**
 * Satu pesan dari chat storage gateway, sudah ternormalisasi.
 *
 * `body` adalah teks/caption apa adanya; `mediaType` hanya nama jenis
 * (`image`, `video`, `audio`, `document`, `sticker`, `call`), tanpa URL karena
 * host gateway tidak boleh keluar adapter (PRD §11.2).
 */
final readonly class ChatMessage
{
    public function __construct(
        public string $id,
        public string $body,
        public ?CarbonImmutable $timestamp = null,
        public bool $isFromMe = false,
        public ?string $mediaType = null,
        public ?string $mediaName = null,
    ) {}
}
