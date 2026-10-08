<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Livewire\Component;

/**
 * Judul halaman + pemilih rentang waktu.
 *
 * 🔴 `$range` di sini BUKAN sumber kebenaran. Nilai itu milik `Index`; komponen
 * ini menerima salinannya untuk menandai tombol aktif, lalu meneruskan pilihan
 * admin lewat event. Kalau header yang memegang `$range`, setiap grafik di bawah
 * perlu menggambar ulang dengan sendirinya saat tombol ditekan.
 */
class AnalyticsHeader extends Component
{
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    public function render()
    {
        return view('wara::livewire.pages.landlord.analytics.section.analytics-header', [
            'ranges' => WaraAnalytics::RANGES,
        ]);
    }
}
