<?php

namespace Bale\Wara\Data;

/**
 * Isi media yang berhasil diunduh dari gateway.
 *
 * URL media gateway sengaja TIDAK ikut: host gateway tidak boleh bocor keluar
 * adapter (PRD A11.2), dan byte-nya sudah cukup untuk consumer.
 */
final readonly class DownloadedMedia
{
    public function __construct(
        public string $contents,
        public string $filename,
        public int $size,
    ) {}
}
