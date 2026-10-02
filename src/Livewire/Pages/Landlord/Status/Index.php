<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Status;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman status bale/wara: apa yang sudah selesai, apa yang direncanakan.
 *
 * 🔴 Halaman ini read-only, seperti halaman Log. Yang ditampilkan berasal dari
 * `src/status.php` — bukan dari parsing dokumen Markdown.
 *
 * Alasannya: dokumen dan dashboard punya bentuk berbeda. Dokumen ditulis untuk
 * dibaca manusia lengkap dengan alasannya; dashboard untuk hal yang perlu dilihat
 * sekilas. Membaca Markdown di sini berarti setiap halaman bergantung pada
 * format dokumen, dan perubahan satu baris di dokumen bisa memecah UI.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Status Wara')]
class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('wara.status.read');
    }

    public function render()
    {
        // `dirname(__DIR__, 4)` naik dari `src/Livewire/Pages/Landlord/Status`
        // sampai ke `src/`. Menulis `__DIR__.'/../../../status.php'` terlihat
        // benar tapi sebenarnya berhenti di `src/Livewire/` — dan gejalanya
        // "Failed to open stream", yang sama sekali tidak menyiratkan path.
        return view('wara::livewire.pages.landlord.status.index', [
            'status' => require dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'status.php',
        ]);
    }
}
