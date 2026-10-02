<?php

namespace Bale\Wara\Support;

/**
 * Penyamaran kode OTP untuk ditampilkan di log (PRD §4).
 *
 * Alasan: `wara_logs.body` menyimpan isi pesan apa adanya, dan tabel itu
 * tidak terenkripsi. Kode OTP yang tersimpan permanen di sana bisa dipakai
 * ulang siapa pun yang bisa membaca log. Untuk kebutuhan audit, jejak
 * "ada pengiriman OTP" sudah cukup tanpa kode utuhnya.
 */
class SensitiveData
{
    /**
     * Samarkan kode OTP di dalam body pesan.
     *
     * Menangani kode numerik maupun alfanumerik: dua karakter pertama dan
     * terakhir ditampilkan, sisanya diganti `*`.
     */
    public static function maskOtp(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        // Hanya isi di dalam asterisk yang diubah, teks lain tidak boleh
        // ikut berubah.
        return preg_replace_callback(
            '/(\*\s*)([A-Za-z0-9]{4,})(\s*\*)/u',
            fn (array $matches) => $matches[1].self::maskToken($matches[2]).$matches[3],
            $body
        );
    }

    /**
     * Samarkan satu token: sisakan 2 karakter awal dan 2 karakter akhir.
     *
     * Token yang terlalu pendek (1-3 karakter) ditampilkan penuh —
     * menyembunyikan sebagian kecil dari token pendek tidak menambah
     * keamanan, hanya membuat log membingungkan.
     */
    public static function maskToken(string $token): string
    {
        $length = mb_strlen($token);

        if ($length <= 4) {
            return $token;
        }

        $visible = 2;

        return mb_substr($token, 0, $visible)
            .str_repeat('*', $length - ($visible * 2))
            .mb_substr($token, -$visible);
    }
}
