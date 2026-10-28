<?php

namespace Bale\Wara\Support;

use Bale\Wara\Enums\ParameterFormat;
use Illuminate\Validation\ValidationException;

/**
 * Validasi & normalisasi `components` template (PRD §16.3).
 *
 * Dipakai di dua tempat: saat guest menyimpan draft, dan saat API akan
 * mengirim. Yang kedua penting karena baris yang lolos validasi lama tetap
 * harus ditolak kalau bentuknya sudah tidak sesuai.
 */
class TemplateValidator
{
    public const HEADER_FORMATS = ['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'];

    public const BUTTON_TYPES = ['QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'COPY_CODE'];

    /**
     * @param  array<string, mixed>  $components
     * @return array<string, mixed> komponen yang sudah dinormalisasi
     *
     * @throws ValidationException
     */
    public function validate(array $components, ParameterFormat $format): array
    {
        $errors = [];

        $header = is_array($components['header'] ?? null) ? $components['header'] : [];
        $body = is_array($components['body'] ?? null) ? $components['body'] : [];
        $footer = is_array($components['footer'] ?? null) ? $components['footer'] : [];
        $buttons = is_array($components['buttons'] ?? null) ? $components['buttons'] : [];

        // -----------------------------------------------------------------
        // HEADER
        // -----------------------------------------------------------------
        $headerFormat = strtoupper((string) ($header['format'] ?? 'NONE'));

        if (! in_array($headerFormat, self::HEADER_FORMATS, true)) {
            $errors[] = __('Format header tidak dikenal.');
        }

        $headerText = isset($header['text']) ? trim((string) $header['text']) : null;
        $headerVariables = [];

        if ($headerFormat === 'TEXT') {
            if ($headerText === null || $headerText === '') {
                $errors[] = __('Header TEXT wajib punya teks.');
            } elseif (mb_strlen($headerText) > 60) {
                $errors[] = __('Teks header maksimal 60 karakter.');
            } else {
                $headerVariables = $this->variablesIn($headerText, $format);

                if (count($headerVariables) > 1) {
                    $errors[] = __('Header maksimal memuat satu variabel.');
                }
            }
        } elseif ($headerFormat !== 'NONE' && $headerText !== null && $headerText !== '') {
            $errors[] = __('Header media tidak boleh memuat teks.');
        }

        // -----------------------------------------------------------------
        // BODY
        // -----------------------------------------------------------------
        $bodyText = isset($body['text']) ? trim((string) $body['text']) : '';

        if ($bodyText === '') {
            $errors[] = __('Body wajib diisi.');
        } elseif (mb_strlen($bodyText) > 1024) {
            $errors[] = __('Body maksimal 1024 karakter.');
        }

        $bodyVariables = $bodyText === '' ? [] : $this->variablesIn($bodyText, $format);

        // 🔴 Nomor parameter berlaku untuk SELURUH pesan, bukan per komponen.
        //
        // Pemanggil API mengirim satu array `parameters`, jadi header dan body
        // berbagi nomor yang sama. Kalau keduanya memakai `{{1}}`, keduanya akan
        // terisi nilai yang sama - dan header yang menampilkan nama orang di
        // atas body yang menampilkan nomor tiket adalah bug yang sulit dilacak.
        // Karena itu urutannya digabung dan diperiksa bersama.
        $allVariables = array_values(array_unique(array_merge($headerVariables, $bodyVariables)));

        $examples = is_array($body['examples'] ?? null) ? $body['examples'] : [];

        foreach ($allVariables as $variable) {
            if (blank($examples[$variable] ?? null)) {
                $errors[] = __('Contoh untuk variabel ":var" wajib diisi.', ['var' => $variable]);
            }
        }

        if ($format === ParameterFormat::Positional && $allVariables !== []) {
            if (array_map('intval', $allVariables) !== range(1, count($allVariables))) {
                $errors[] = __('Variabel posisional harus berurutan mulai {{1}} tanpa lompatan.');
            }
        }

        // -----------------------------------------------------------------
        // FOOTER
        // -----------------------------------------------------------------
        $footerText = isset($footer['text']) ? trim((string) $footer['text']) : null;

        if ($footerText !== null && $footerText !== '') {
            if (mb_strlen($footerText) > 60) {
                $errors[] = __('Footer maksimal 60 karakter.');
            }

            if ($this->variablesIn($footerText, $format) !== []) {
                $errors[] = __('Footer tidak boleh memuat variabel.');
            }
        }

        // -----------------------------------------------------------------
        // BUTTONS
        // -----------------------------------------------------------------
        if (count($buttons) > 10) {
            $errors[] = __('Maksimal 10 tombol.');
        }

        $urlCount = 0;
        $phoneCount = 0;
        $copyCodeCount = 0;
        $normalizedButtons = [];

        foreach (array_values($buttons) as $index => $button) {
            if (! is_array($button)) {
                $errors[] = __('Tombol #:n tidak valid.', ['n' => $index + 1]);

                continue;
            }

            $type = strtoupper((string) ($button['type'] ?? ''));
            $text = isset($button['text']) ? trim((string) $button['text']) : '';

            if (! in_array($type, self::BUTTON_TYPES, true)) {
                $errors[] = __('Tipe tombol #:n tidak dikenal.', ['n' => $index + 1]);

                continue;
            }

            if ($text === '' || mb_strlen($text) > 25) {
                $errors[] = __('Teks tombol #:n wajib 1-25 karakter.', ['n' => $index + 1]);
            }

            $normalized = ['type' => $type, 'text' => $text, 'url' => null, 'phone_number' => null];

            if ($type === 'URL') {
                $urlCount++;
                $url = trim((string) ($button['url'] ?? ''));

                if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $errors[] = __('Tombol URL #:n wajib punya URL valid.', ['n' => $index + 1]);
                }

                $normalized['url'] = $url;
            }

            if ($type === 'PHONE_NUMBER') {
                $phoneCount++;
                $phone = trim((string) ($button['phone_number'] ?? ''));

                if ($phone === '') {
                    $errors[] = __('Tombol telepon #:n wajib punya nomor.', ['n' => $index + 1]);
                }

                $normalized['phone_number'] = $phone;
            }

            if ($type === 'COPY_CODE') {
                $copyCodeCount++;
            }

            $normalizedButtons[] = $normalized;
        }

        if ($urlCount > 2) {
            $errors[] = __('Maksimal 2 tombol URL.');
        }

        if ($phoneCount > 1) {
            $errors[] = __('Maksimal 1 tombol telepon.');
        }

        if ($copyCodeCount > 1) {
            $errors[] = __('Maksimal 1 tombol salin kode.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['components' => $errors]);
        }

        return [
            'header' => [
                'format' => $headerFormat,
                'text' => $headerFormat === 'TEXT' ? $headerText : null,
            ],
            'body' => [
                'text' => $bodyText,
                'examples' => $examples,
            ],
            'footer' => [
                'text' => ($footerText === '' ? null : $footerText),
            ],
            'buttons' => $normalizedButtons,
        ];
    }

    /**
     * Ambil daftar variabel dari sebuah teks, sesuai format.
     *
     * @return list<string>
     */
    public function variablesIn(string $text, ParameterFormat $format): array
    {
        $pattern = $format === ParameterFormat::Positional
            ? '/\{\{\s*(\d+)\s*\}\}/'
            : '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

        preg_match_all($pattern, $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
