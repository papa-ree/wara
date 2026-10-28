<?php

namespace Bale\Wara\Enums;

/**
 * Kategori template pemerintahan.
 *
 * 🔴 Ini BUKAN taksonomi WABA. `MARKETING` sengaja tidak dipakai karena
 * instansi pemerintah tidak mengirim promosi komersial. Padanan yang dipakai:
 *
 *   LAYANAN   -> transaksional (status pengajuan, jadwal, tagihan)
 *   INFORMASI -> informasi publik/kampanye, wajib opt-in
 *   OTP       -> autentikasi, dikirim sebagai teks biasa
 */
enum TemplateCategory: string
{
    case Layanan = 'LAYANAN';

    case Informasi = 'INFORMASI';

    case Otp = 'OTP';

    public function label(): string
    {
        return match ($this) {
            self::Layanan => __('Layanan'),
            self::Informasi => __('Informasi Publik'),
            self::Otp => __('OTP'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
