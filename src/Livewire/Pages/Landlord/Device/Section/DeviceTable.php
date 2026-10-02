<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device\Section;

use Livewire\Attributes\On;
use Livewire\Component;

class DeviceTable extends Component
{
    #[On('refresh-device-table')]
    public function render()
    {
        return view('wara::livewire.pages.landlord.device.section.device-table');
    }
}
