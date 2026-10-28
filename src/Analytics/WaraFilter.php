<?php

namespace Bale\Wara\Analytics;

use Illuminate\Support\Carbon;
use Livewire\Wireable;

/**
 * Value object filter analitik Wara.
 *
 * Dibuat supaya setiap method `WaraAnalytics` menerima satu argumen, bukan
 * tiga atau empat. Kalau filter berkembang (mis. ditambah `status` atau
 * `direction`), cukup tambahkan property di sini — semua query yang sudah
 * ada tidak perlu mengubah tanda tangan.
 *
 * 🔴 Custom range (`$from`/`$until`) dan preset `$hours` bersifat EKSKLUSIF:
 * kalau `$from` diisi, `$hours` diabaikan. Tidak ada yang menjumlahkan keduanya.
 * Ini bisa diubah nanti (mis. "sejak X sampai sekarang"), tapi saat ini
 * keduanya digunakan untuk hal yang berbeda dan mencampur logiknya lebih
 * berbahaya dari sekedar membatasi.
 *
 * 🔴 `$deviceId` dan `$clientId` adalah filter TAMBAHAN di atas rentang waktu.
 * Nilainya string (karena berasal dari URL), bukan int — konversi tipe ada di
 * query builder, bukan di sini.
 */
final class WaraFilter implements Wireable
{
    public function __construct(
        /** Rentang preset dalam jam (24/168/720/8760). Diabaikan kalau $from diisi. */
        public readonly int $hours = WaraAnalytics::DEFAULT_RANGE_HOURS,

        /** Tanggal mulai kustom (override $hours). */
        public readonly ?Carbon $from = null,

        /** Tanggal akhir kustom (default: now()). */
        public readonly ?Carbon $until = null,

        /** Filter opsional per device_id. */
        public readonly ?string $deviceId = null,

        /** Filter opsional per client_id (hanya berlaku untuk arah keluar). */
        public readonly ?string $clientId = null,
    ) {}

    /**
     * Titik awal window, disesuaikan dengan custom range atau preset.
     */
    public function sinceCarbon(): Carbon
    {
        if ($this->from !== null) {
            return $this->from->copy()->startOfDay();
        }

        return now()->subHours($this->hours);
    }

    /**
     * Titik akhir window (inklusif).
     */
    public function untilCarbon(): Carbon
    {
        if ($this->until !== null) {
            return $this->until->copy()->endOfDay();
        }

        return now();
    }

    /**
     * Apakah filter ini memakai custom date range (bukan preset jam).
     */
    public function isCustomRange(): bool
    {
        return $this->from !== null;
    }

    /**
     * Jumlah jam efektif antara from dan until, untuk keperluan bucketing funnel.
     *
     * Kalau memakai preset, langsung kembalikan $hours.
     * Kalau custom, hitung selisih aktual — minimal 1 jam supaya tidak
     * membagi nol di `bucketWidth()`.
     */
    public function effectiveHours(): int
    {
        if (! $this->isCustomRange()) {
            return $this->hours;
        }

        $diff = (int) $this->sinceCarbon()->diffInHours($this->untilCarbon(), absolute: true);

        return max(1, $diff);
    }

    /**
     * Label pendek untuk badge periode di kartu.
     * Custom range menampilkan tanggal; preset memakai label standar.
     */
    public function label(): string
    {
        if ($this->isCustomRange()) {
            $f = $this->sinceCarbon()->translatedFormat('d M');
            $t = $this->untilCarbon()->translatedFormat('d M Y');

            return "{$f} – {$t}";
        }

        return WaraAnalytics::rangeLabel($this->hours);
    }

    /**
     * Deskripsi panjang untuk sub-judul di header.
     */
    // ── Wireable — Livewire 4 serialization ──────────────────────────────────

    /**
     * Serialize ke array sederhana untuk Livewire.
     * Carbon di-cast ke string Y-m-d; null tetap null.
     *
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return [
            'hours' => $this->hours,
            'from' => $this->from?->format('Y-m-d'),
            'until' => $this->until?->format('Y-m-d'),
            'deviceId' => $this->deviceId,
            'clientId' => $this->clientId,
        ];
    }

    /**
     * Reconstruct dari array Livewire.
     *
     * @param  array<string, mixed>  $value
     */
    public static function fromLivewire(mixed $value): static
    {
        return new self(
            hours: (int) ($value['hours'] ?? WaraAnalytics::DEFAULT_RANGE_HOURS),
            from: isset($value['from']) && $value['from'] !== null
                ? Carbon::createFromFormat('Y-m-d', $value['from'])
                : null,
            until: isset($value['until']) && $value['until'] !== null
                ? Carbon::createFromFormat('Y-m-d', $value['until'])
                : null,
            deviceId: $value['deviceId'] ?? null,
            clientId: $value['clientId'] ?? null,
        );
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    public function description(): string
    {
        if ($this->isCustomRange()) {
            return $this->sinceCarbon()->translatedFormat('d M Y')
                .' – '
                .$this->untilCarbon()->translatedFormat('d M Y');
        }

        return WaraAnalytics::rangeDescription($this->hours);
    }
}
