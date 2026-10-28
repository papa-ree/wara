<?php

namespace Bale\Wara\Enums;

/**
 * Kontrak pengisian variabel template.
 *
 * POSITIONAL : `{{1}}`, `{{2}}` — dikirim sebagai array berurutan.
 * NAMED      : `{{nama}}` — dikirim sebagai key-value.
 *
 * Nilai ini menentukan validasi editor dan bentuk payload API. Default
 * POSITIONAL agar paritas dengan WABA.
 */
enum ParameterFormat: string
{
    case Positional = 'POSITIONAL';

    case Named = 'NAMED';

    public function label(): string
    {
        return match ($this) {
            self::Positional => __('Posisional ({{1}}, {{2}})'),
            self::Named => __('Bernama ({{nama}})'),
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
