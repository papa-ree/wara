<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Client;

use Bale\Wara\Models\WaraClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar client Wara dan peta `purpose -> device` miliknya.
 *
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
 */
#[Layout('rakaca::layouts.app')]
#[Title('Client Wara')]
class Index extends Component
{
    use AuthorizesRequests, WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('wara.client.read');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.client.index', [
            'clients' => $this->clients(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, WaraClient>
     */
    protected function clients()
    {
        return WaraClient::query()
            ->with(['routes' => fn ($q) => $q->orderBy('purpose')])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';

                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('type', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15);
    }
}
