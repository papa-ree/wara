<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Template;

use Bale\Wara\Enums\TemplateStatus;
use Bale\Wara\Models\WaraTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Antrean review template (PRD §16.5).
 *
 * Halaman ini TIDAK menyimpan isi template. Semua perubahan status lewat
 * `TemplateWorkflowService` di halaman Review, supaya aturan transisi tidak
 * tersebar di dua tempat.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Review Template')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $status = 'PENDING';

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('wara.template.read');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function templates()
    {
        return WaraTemplate::query()
            ->with('user')
            ->when(
                in_array($this->status, TemplateStatus::values(), true),
                fn ($query) => $query->where('status', $this->status)
            )
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('user_uuid', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function pendingCount(): int
    {
        return WaraTemplate::query()
            ->where('status', TemplateStatus::Pending->value)
            ->count();
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.template.index');
    }
}
