<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Analytics\WaraFilter;
use Livewire\Component;

/**
 * Daftar per-client, per-device, pesan masuk per pengirim, dan reliability fan-out.
 */
class AnalyticsBreakdown extends Component
{
    public WaraFilter $filter;

    public function render()
    {
        $analytics = new WaraAnalytics;

        return view('wara::livewire.pages.landlord.analytics.section.analytics-breakdown', [
            'byClient' => $analytics->filteredOutboundByClient($this->filter),
            'byDevice' => $analytics->filteredTrafficByDevice($this->filter),
            'inboundBySender' => $analytics->filteredInboundBySender($this->filter),
            'fanOut' => $analytics->filteredFanOutByEndpoint($this->filter),
            'rangeLabel' => $this->filter->label(),
        ]);
    }
}
