<?php

namespace Bale\Wara\Enums;

/**
 * Status template. Penamaan mengikuti WABA (huruf besar), dengan satu
 * tambahan lokal: `DRAFT` untuk template yang belum diajukan.
 *
 *   DRAFT --submit--> PENDING --approve--> APPROVED
 *                        \--reject--> REJECTED --edit/submit--> PENDING
 */
enum TemplateStatus: string
{
    case Draft = 'DRAFT';

    case Pending = 'PENDING';

    case Approved = 'APPROVED';

    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Menunggu Review'),
            self::Approved => __('Disetujui'),
            self::Rejected => __('Ditolak'),
        };
    }

    /**
     * Kelas Tailwind untuk badge status.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-gray-50 text-gray-700 border-gray-200 dark:bg-gray-900/20 dark:text-gray-400 dark:border-gray-800/40',
            self::Pending => 'bg-amber-50 text-amber-700 border-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40',
            self::Approved => 'bg-emerald-50 text-emerald-700 border-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/40',
            self::Rejected => 'bg-rose-50 text-rose-700 border-rose-100 dark:bg-rose-900/20 dark:text-rose-400 dark:border-rose-800/40',
        };
    }

    /**
     * Hanya draft dan yang ditolak boleh diubah pemiliknya.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
