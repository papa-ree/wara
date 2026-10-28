<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Analytics\WaraFilter;
use Livewire\Component;

/**
 * Judul halaman + pemilih rentang waktu dan filter device/client.
 *
 * 🔴 `$filter` di sini adalah READ-ONLY prop dari `Index`. Komponen ini
 * hanya menampilkan state aktif dan mendispatch event ke atas. Tidak boleh
 * menyimpan state filter sendiri — kalau ada, ada dua sumber kebenaran.
 */
class AnalyticsHeader extends Component
{
    public WaraFilter $filter;

    public function render()
    {
        return view('wara::livewire.pages.landlord.analytics.section.analytics-header', [
            'ranges' => WaraAnalytics::rangeOptions(),
        ]);
    }
}
