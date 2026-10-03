<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Client;

use Bale\Core\Traits\HasDeleteOption;
use Bale\Wara\Models\WaraClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * 🔴 INI PENGGANTI form `purpose` yang tadinya menempel di halaman Device.
 *
 * Penugasan tidak lagi menempel pada device karena `purpose` yang unik global
 * membuat satu device hanya bisa melayani satu tujuan di seluruh sistem.
 * Begitu ada dua client, keduanya berebut device yang sama dan tidak ada cara
 * tahu siapa berhak atas apa. Jadi penugasan pindah ke `(client, purpose)`,
 * dan harus punya tempat sendiri di UI.
 *
 * Halaman ini juga tempat mengelola endpoint webhook client, karena `enabled`
 * di situ yang menentukan on/off per package.
 *
 * Komponen ini TIPIS. Query, filtering, dan tabel semuanya milik
 * `Section\ClientTable`, bukan milik halaman - halaman hanya beres navigasi.
 * Alasannya bukan selera: `HasDeleteOption` menempel di tabel, jadi listener
 * `deleteItem` harus ada di komponen yang benar-benar memegang data. Kalau
 * tabel dipisah ke file terpisah sementara delete tetap di halaman,
 * penghapusan akan terlihat berhasil padahal tak ada yang berubah.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Client Wara')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('wara.client.read');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.client.index', [
            'types' => WaraClient::TYPES,
        ]);
    }
}
