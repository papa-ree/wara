<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics;

use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Analytics\WaraFilter;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraSession;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Metrik gateway & router (PRD §14).
 *
 * Halaman ini memegang SEMUA state filter:
 * - `$range`   — preset jam (24/168/720/8760), diabaikan kalau custom range aktif
 * - `$from`    — tanggal mulai kustom (format Y-m-d)
 * - `$to`      — tanggal akhir kustom (format Y-m-d)
 * - `$deviceId`  — filter opsional per device
 * - `$clientId`  — filter opsional per client (outbound only)
 *
 * WaraFilter dibangun di `filter()` computed property dan diteruskan ke semua
 * child component sebagai prop `:filter`. Child tidak boleh membangun filter
 * sendiri supaya tidak ada mismatch antara rentang yang ditampilkan di header
 * dan rentang yang dipakai di query.
 *
 * 🔴 `#[Url]` pada semua filter supaya admin bisa mengirim tautan dengan
 * semua kondisi filter lengkap ke rekan.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Analitik Wara')]
class Index extends Component
{
    use AuthorizesRequests;

    /** Preset rentang waktu dalam jam. Diabaikan kalau $from diisi. */
    #[Url(as: 'range', history: false)]
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    /** Tanggal mulai kustom (Y-m-d). Mengaktifkan custom range mode. */
    #[Url(as: 'from', history: false)]
    public string $from = '';

    /** Tanggal akhir kustom (Y-m-d). Default: hari ini saat $from diisi. */
    #[Url(as: 'to', history: false)]
    public string $to = '';

    /** Filter opsional per device_id. */
    #[Url(as: 'device', history: false)]
    public string $deviceId = '';

    /** Filter opsional per client_id (outbound only). */
    #[Url(as: 'client', history: false)]
    public string $clientId = '';

    public function mount(): void
    {
        $this->authorize('wara.log.read');

        $this->range = $this->normalizeRange($this->range);
        $this->from = $this->normalizeDate($this->from);
        $this->to = $this->normalizeDate($this->to);
    }

    /**
     * WaraFilter yang dipakai semua child component.
     *
     * 🔴 Computed property (bukan prop publik) supaya child tidak bisa
     * memodifikasinya — hanya Index yang memegang state filter.
     */
    #[Computed]
    public function filter(): WaraFilter
    {
        $from = $this->from !== '' ? Carbon::parse($this->from) : null;
        $to = $this->to !== '' ? Carbon::parse($this->to) : null;

        // Kalau custom range, from wajib < to. Kalau tidak valid, fallback ke preset.
        if ($from !== null && $to !== null && $from->gt($to)) {
            $from = null;
            $to = null;
        }

        return new WaraFilter(
            hours: $this->range,
            from: $from,
            until: $to,
            deviceId: $this->deviceId !== '' ? $this->deviceId : null,
            clientId: $this->clientId !== '' ? $this->clientId : null,
        );
    }

    /**
     * Key unik yang berubah setiap kali filter berubah.
     * Dipakai untuk memaksa remount child component (agar query diulang dari nol).
     */
    public function filterKey(): string
    {
        return md5($this->range.$this->from.$this->to.$this->deviceId.$this->clientId);
    }

    /**
     * Menerima pilihan preset range dari AnalyticsHeader.
     * Sekaligus reset custom date range.
     */
    #[On('analytics-range-changed')]
    public function selectRange(int $range): void
    {
        $this->range = $this->normalizeRange($range);
        $this->from = '';
        $this->to = '';
    }

    /**
     * Menerima custom date range dari AnalyticsHeader.
     */
    #[On('analytics-custom-range')]
    public function setCustomRange(string $from, string $to): void
    {
        $this->from = $this->normalizeDate($from);
        $this->to = $this->normalizeDate($to);
    }

    /**
     * Reset hanya filter device dan client (bukan periode).
     */
    #[On('analytics-filters-changed')]
    public function setFilters(string $deviceId = '', string $clientId = ''): void
    {
        $this->deviceId = $deviceId;
        $this->clientId = $clientId;
    }

    public function updatedRange(): void
    {
        $this->range = $this->normalizeRange($this->range);
        $this->from = '';
        $this->to = '';
    }

    public function updatedFrom(): void
    {
        $this->from = $this->normalizeDate($this->from);
    }

    public function updatedTo(): void
    {
        $this->to = $this->normalizeDate($this->to);
    }

    protected function normalizeRange(int $range): int
    {
        return in_array($range, WaraAnalytics::RANGES, true)
            ? $range
            : WaraAnalytics::DEFAULT_RANGE_HOURS;
    }

    /**
     * Validasi dan normalisasi string tanggal (Y-m-d).
     *
     * 🔴 Input dari URL/event bisa apa saja. Tanggal yang tidak valid
     * di-reset ke string kosong, bukan menyebabkan exception di Carbon::parse().
     */
    protected function normalizeDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);

            return $parsed !== false ? $parsed->format('Y-m-d') : '';
        } catch (\Exception) {
            return '';
        }
    }

    public function render()
    {
        $devices = WaraSession::query()
            ->orderBy('display_name')
            ->get(['device_id', 'display_name']);

        $clients = WaraClient::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('wara::livewire.pages.landlord.analytics.index', [
            'devices' => $devices,
            'clients' => $clients,
        ]);
    }
}
