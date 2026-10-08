<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Livewire\Component;

/**
 * Tabel per-client, per-device, dan reliability fan-out.
 *
 * Satu komponen untuk tiga tabel, bukan tiga komponen. Alasannya bentuknya
 * sama: daftar agregat dengan baris yang bisa dihitung manual dari query yang
 * sama. Memisahkannya jadi tiga file Livewire hanya menambah tempat untuk
 * lupa menyinkronkan `range`.
 */
class AnalyticsBreakdown extends Component
{
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    public function render()
    {
        $analytics = new WaraAnalytics;

        return view('wara::livewire.pages.landlord.analytics.section.analytics-breakdown', [
            'byClient' => $analytics->outboundByClient($this->range),
            'byDevice' => $analytics->trafficByDevice($this->range),
            'inboundBySender' => $analytics->inboundBySender($this->range),
            'fanOut' => $analytics->fanOutByEndpoint($this->range),
        ]);
    }
}
