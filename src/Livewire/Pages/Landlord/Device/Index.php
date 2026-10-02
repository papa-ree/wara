<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman katalog device: daftar device dari gateway + penugasan `purpose`
 * oleh admin. Ini satu-satunya tempat `purpose` boleh diisi (PRD §7).
 */
#[Layout('rakaca::layouts.app')]
#[Title('Device WhatsApp')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('wara.device.read');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.index');
    }
}
