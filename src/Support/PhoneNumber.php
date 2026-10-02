<?php

namespace Bale\Wara\Support;

/**
 * Normalisasi nomor telepon ke format internasional.
 *
 * Penambahan suffix JID milik adapter gateway, BUKAN urusan ini (PRD §11.1).
 * Konsumen selalu berhadapan dengan format `62812...`.
 */
class PhoneNumber
{
    /**
     * Ubah input mentah menjadi digit format internasional.
     *
     * Mengembalikan string kosong bila tidak ada digit sama sekali, supaya
     * pemanggil bisa menolak dengan pesan yang jelas.
     */
    public function toInternational(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $localPrefix = (string) config('wara.phone_format.local_prefix_to_international', '0');
        $international = (string) config('wara.phone_format.international_prefix', '62');

        // Sudah internasional -> biarkan apa adanya.
        if ($international !== '' && str_starts_with($digits, $international)) {
            return $digits;
        }

        // Awalan lokal -> ganti jadi prefiks internasional.
        if ($localPrefix !== '' && str_starts_with($digits, $localPrefix)) {
            return $international.substr($digits, strlen($localPrefix));
        }

        return $digits;
    }
}
