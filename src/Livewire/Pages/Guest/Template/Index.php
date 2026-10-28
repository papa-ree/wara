<?php

namespace Bale\Wara\Livewire\Pages\Guest\Template;

use Bale\Wara\Exceptions\TemplateWorkflowException;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\Services\TemplateWorkflowService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Daftar template milik guest yang sedang login (PRD §16.5).
 *
 * Kepemilikan ditentukan `user_uuid`, bukan `bale_id`: satu organisasi bisa
 * punya beberapa operator, dan template tidak boleh saling menimpa hanya
 * karena berada di organisasi yang sama (keputusan #36).
 */
#[Title('Template Pesan')]
class Index extends Component
{
    public function mount(): void
    {
        $this->guardService();
    }

    #[Computed]
    public function templates()
    {
        return WaraTemplate::query()
            ->ownedBy($this->userUuid())
            ->orderByDesc('updated_at')
            ->get();
    }

    public function submit(string $id): void
    {
        $template = $this->findOwned($id);

        try {
            app(TemplateWorkflowService::class)->submit($template);
            session()->flash('wara_template_status', __('Template diajukan untuk review.'));
        } catch (TemplateWorkflowException $e) {
            $this->addError('status', $e->getMessage());
        }

        unset($this->templates);
    }

    public function delete(string $id): void
    {
        $template = $this->findOwned($id);

        if (! $template->isEditable()) {
            $this->addError('status', __('Template yang sudah diajukan atau disetujui tidak bisa dihapus.'));

            return;
        }

        $template->delete();

        unset($this->templates);

        session()->flash('wara_template_status', __('Template dihapus.'));
    }

    protected function findOwned(string $id): WaraTemplate
    {
        return WaraTemplate::query()
            ->ownedBy($this->userUuid())
            ->whereKey($id)
            ->firstOrFail();
    }

    protected function userUuid(): string
    {
        return (string) auth()->user()->uuid;
    }

    protected function guardService(): void
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
        return view('wara::livewire.pages.guest.template.index')
            ->layout((string) config('wara.guest_layout', 'rakaca::layouts.app'));
    }
}
