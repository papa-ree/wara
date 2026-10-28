<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Template;

use Bale\Wara\Enums\TemplateStatus;
use Bale\Wara\Exceptions\TemplateWorkflowException;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\Services\TemplateWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail + keputusan admin atas satu template (PRD §16.5).
 */
#[Layout('rakaca::layouts.app')]
#[Title('Review Template')]
class Review extends Component
{
    use AuthorizesRequests;

    public WaraTemplate $template;

    public string $rejectionReason = '';

    public function mount(WaraTemplate $template): void
    {
        $this->authorize('wara.template.review');

        $this->template = $template;
        $this->rejectionReason = (string) ($template->rejection_reason ?? '');
    }

    public function approve()
    {
        $this->authorize('wara.template.review');

        try {
            app(TemplateWorkflowService::class)->approve(
                $this->template,
                (string) auth()->user()->uuid
            );
        } catch (TemplateWorkflowException $e) {
            $this->addError('review', $e->getMessage());

            return null;
        }

        session()->flash('wara_template_status', __('Template disetujui.'));

        return $this->redirectRoute('wara.template.index');
    }

    public function reject()
    {
        $this->authorize('wara.template.review');

        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ], attributes: [
            'rejectionReason' => __('alasan penolakan'),
        ]);

        try {
            app(TemplateWorkflowService::class)->reject(
                $this->template,
                (string) auth()->user()->uuid,
                $this->rejectionReason
            );
        } catch (TemplateWorkflowException $e) {
            $this->addError('review', $e->getMessage());

            return null;
        }

        session()->flash('wara_template_status', __('Template ditolak.'));

        return $this->redirectRoute('wara.template.index');
    }

    /**
     * Pratinjau memakai contoh parameter yang diisi pemilik.
     */
    public function previewText(): string
    {
        $components = (array) $this->template->components;
        $examples = (array) data_get($components, 'body.examples', []);

        $substitute = function (string $text) use ($examples): string {
            foreach ($examples as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }

                $text = (string) preg_replace(
                    '/\{\{\s*'.preg_quote((string) $key, '/').'\s*\}\}/',
                    (string) $value,
                    $text
                );
            }

            return $text;
        };

        $parts = [];

        $headerFormat = (string) data_get($components, 'header.format', 'NONE');
        $headerText = (string) data_get($components, 'header.text', '');

        if ($headerFormat === 'TEXT' && $headerText !== '') {
            $parts[] = $substitute($headerText);
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $parts[] = '['.__('Header :format', ['format' => $headerFormat]).']';
        }

        $parts[] = $substitute((string) data_get($components, 'body.text', ''));

        $footer = (string) data_get($components, 'footer.text', '');

        if ($footer !== '') {
            $parts[] = $footer;
        }

        $buttons = (array) data_get($components, 'buttons', []);

        if ($buttons !== []) {
            $lines = [];

            foreach (array_values($buttons) as $index => $button) {
                $lines[] = ($index + 1).'. '.($button['text'] ?? '');
            }

            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.template.review', [
            'preview' => $this->previewText(),
            'canReview' => $this->template->status === TemplateStatus::Pending,
        ]);
    }
}
