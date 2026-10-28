<?php

namespace Bale\Wara\Services;

use Bale\Wara\Enums\ParameterFormat;
use Bale\Wara\Exceptions\TemplateWorkflowException;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\Support\TemplateValidator;

/**
 * Rakit komponen template menjadi pesan yang bisa dikirim GOWA (PRD §16.7).
 *
 * GOWA tidak punya `sendTemplate`, jadi:
 *   - header teks + body + footer + tombol -> satu teks;
 *   - header media -> dikirim lewat endpoint media dengan caption = teks;
 *   - tombol -> fallback teks bernomor (tidak ada tombol native).
 */
class TemplateRenderer
{
    public function __construct(protected TemplateValidator $validator) {}

    /**
     * @param  array<string|int, string>  $parameters
     * @return array{text: string, media: array{type: string, url: string}|null}
     *
     * @throws TemplateWorkflowException
     */
    public function render(WaraTemplate $template, array $parameters = [], ?string $headerMediaUrl = null): array
    {
        $components = $this->validator->validate(
            (array) $template->components,
            $template->parameter_format
        );

        $format = $template->parameter_format;
        $header = $components['header'];
        $parts = [];

        if (($header['format'] ?? 'NONE') === 'TEXT' && filled($header['text'] ?? null)) {
            $parts[] = $this->substitute((string) $header['text'], $parameters, $format);
        }

        $parts[] = $this->substitute((string) $components['body']['text'], $parameters, $format);

        if (filled($components['footer']['text'] ?? null)) {
            $parts[] = (string) $components['footer']['text'];
        }

        if (($components['buttons'] ?? []) !== []) {
            $lines = [];

            foreach ($components['buttons'] as $index => $button) {
                $lines[] = ($index + 1).'. '.$button['text'];
            }

            // Fallback teks bernomor: GOWA tidak mendukung tombol native.
            $parts[] = implode("\n", $lines);
        }

        $text = implode("\n\n", array_values(array_filter(
            $parts,
            fn (string $part): bool => $part !== ''
        )));

        $media = null;
        $headerFormat = (string) ($header['format'] ?? 'NONE');

        if (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            if (blank($headerMediaUrl)) {
                throw TemplateWorkflowException::mediaRequired($headerFormat);
            }

            $media = ['type' => $headerFormat, 'url' => (string) $headerMediaUrl];
        }

        return ['text' => $text, 'media' => $media];
    }

    /**
     * Ganti variabel dengan nilai pemanggil.
     *
     * @param  array<string|int, string>  $parameters
     *
     * @throws TemplateWorkflowException
     */
    public function substitute(string $text, array $parameters, ParameterFormat $format): string
    {
        foreach ($this->validator->variablesIn($text, $format) as $variable) {
            $value = $parameters[$variable]
                ?? $parameters[(int) $variable]
                ?? null;

            if ($value === null) {
                throw TemplateWorkflowException::missingParameter($variable);
            }

            $text = (string) preg_replace(
                '/\{\{\s*'.preg_quote($variable, '/').'\s*\}\}/',
                str_replace(['\\', '$'], ['\\\\', '\\$'], (string) $value),
                $text
            );
        }

        return $text;
    }
}
