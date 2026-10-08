<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Docs;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('core::layouts.app')]
#[Title('Dokumentasi API Wara')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('wara.client.read');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.docs.index');
    }
}
