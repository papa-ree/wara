<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Log;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Riwayat pesan masuk dan keluar per device (PRD §7).
 *
 * Halaman ini read-only. Sengaja tidak ada aksi hapus: pola restriksi akun
 * WhatsApp hanya terlihat dari riwayat percobaan yang lengkap, jadi log tidak
 * boleh hilang diam-diam (PRD §8.2).
 */
#[Layout('rakaca::layouts.app')]
#[Title('Log Pesan')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('wara.log.read');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.log.index');
    }
}
