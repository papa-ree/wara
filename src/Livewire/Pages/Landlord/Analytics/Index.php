<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics;

use Bale\Wara\Analytics\WaraAnalytics;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Metrik gateway & router (PRD §14).
 *
 * Sengaja dipisah dari Log Pesan, bukan digabung sebagai tab di sana. Alasannya
 * bukan soal tampilan: Log menjawab "pesan ini milik siapa", Analitik menjawab
 * "apakah gateway-nya sehat". Satu orang sering butuh yang kedua tanpa perlu
 * menggulir log yang thousands baris.
 *
 * 🔴 Halaman ini menampilkan angka agregat, bukan isi pesan. Isi pesan tetap
 * hanya di Log Pesan, dan tetap disamarkan OTP di sana.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Analitik Wara')]
class Index extends Component
{
    use AuthorizesRequests;

    /**
     * Rentang waktu aktif, dalam jam.
     *
     * 🔴 Ini satu-satunya sumber kebenaran rentang di halaman. `AnalyticsHeader`
     * hanya menampilkan tombol dan meneruskan pilihan lewat event; komponen
     * summary/funnel/breakdown menerima nilainya sebagai atribut.
     *
     * `#[Url]` supaya admin bisa mengirim tautan "kejadian jam 3 pagi kemarin"
     * ke rekan satu tim tanpa menjelaskan rentangnya.
     */
    #[Url(as: 'range', history: false)]
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    public function mount(): void
    {
        $this->authorize('wara.log.read');

        $this->range = $this->normalizeRange($this->range);
    }

    /**
     * Menerima pilihan rentang dari `AnalyticsHeader`.
     *
     * 🔴 Guard ada di sini juga, bukan hanya di `updatedRange()`. Event Livewire
     * bisa dikirim dari mana saja, termasuk request yang direkam pihak ketiga,
     * jadi nilai tetap harus divalidasi di pintu yang sama.
     */
    #[On('analytics-range-changed')]
    public function selectRange(int $range): void
    {
        $this->range = $this->normalizeRange($range);
    }

    /**
     * 🔴 Nilai dari query string bisa apa saja — termasuk yang tidak ada di
     * `RANGES`. Tanpa guard ini, `?range=0` akan sampai ke `now()->subHours(0)`
     * dan menggambar funnel dengan lebar bucket yang membagi dengan nol.
     *
     * Sengaja fallback, bukan `validate()`: URL jelek tidak boleh membuat admin
     * melihat halaman error, dan tidak ada data yang perlu dilindungi di sini —
     * nilainya hanya memilih rentang baca.
     */
    public function updatedRange(): void
    {
        $this->range = $this->normalizeRange($this->range);
    }

    protected function normalizeRange(int $range): int
    {
        return in_array($range, WaraAnalytics::RANGES, true)
            ? $range
            : WaraAnalytics::DEFAULT_RANGE_HOURS;
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.analytics.index');
    }
}
