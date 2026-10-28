<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Analytics\WaraFilter;
use Livewire\Component;

class AnalyticsSummary extends Component
{
    public WaraFilter $filter;

    public function render()
    {
        return view('wara::livewire.pages.landlord.analytics.section.analytics-summary', [
            'summary' => (new WaraAnalytics)->filteredSummary($this->filter),
            'rangeLabel' => $this->filter->label(),
        ]);
    }
}
