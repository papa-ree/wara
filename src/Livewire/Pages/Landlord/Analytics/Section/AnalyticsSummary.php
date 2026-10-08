<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Livewire\Component;

/**
 * Kartu angka ringkas untuk beranda Analitik.
 *
 * Mengambil datanya sendiri dari `WaraAnalytics` (bukan menerima array dari
 * induk) supaya berada di satu domain dengan perhitungan yang sama. Kalau
 *-induk yang menghitung, ada dua tempat yang harus dijaga sinkron setiap kali
 * definisi "ditolak" berubah.
 */
class AnalyticsSummary extends Component
{
    /**
     * Rentang dalam jam. Dioper dari induk supaya satu klik di filter
     *anak-anak berlaku ke semua bagian halaman.
     */
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    public function render()
    {
        return view('wara::livewire.pages.landlord.analytics.section.analytics-summary', [
            'summary' => (new WaraAnalytics)->summary($this->range),
        ]);
    }
}
