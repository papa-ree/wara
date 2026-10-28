<?php

namespace Bale\Wara\Livewire\Pages\Guest\Docs;

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panduan API ringkas untuk operator organisasi penyewa (PRD §16.5).
 *
 * Halaman ini sengaja hanya menampilkan endpoint, payload, dan contoh respons.
 * Detail gateway (GOWA), scope token, dan alur review admin adalah urusan
 * internal operator layanan -- bukan informasi yang dibutuhkan pemanggil API.
 */
#[Title('Dokumentasi API')]
class Index extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);
        abort_unless(
            method_exists($user, 'hasService') && $user->hasService('wara'),
            403,
            __('Layanan Wara tidak aktif untuk organisasi Anda.')
        );
    }

    public function render()
    {
        return view('wara::livewire.pages.guest.docs.index')
            ->layout((string) config('wara.guest_layout', 'rakaca::layouts.app'));
    }
}
