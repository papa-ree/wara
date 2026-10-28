<?php

namespace Bale\Wara\Livewire\Pages\Guest\Template;

use Bale\Wara\Enums\ParameterFormat;
use Bale\Wara\Enums\TemplateCategory;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\Services\TemplateWorkflowService;
use Bale\Wara\Support\TemplateValidator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Editor template milik guest (PRD §16.5).
 *
 * Satu komponen menangani create & edit. Semua isi `components` divalidasi
 * ulang oleh `TemplateValidator` sebelum disimpan; validasi Livewire di sini
 * hanya untuk field datar (nama, kategori, format).
 */
#[Title('Editor Template')]
class Form extends Component
{
    public ?WaraTemplate $template = null;

    public string $name = '';

    public string $category = 'LAYANAN';

    public string $parameterFormat = 'POSITIONAL';

    public string $headerFormat = 'NONE';

    public string $headerText = '';

    public string $bodyText = '';

    public string $footerText = '';

    /** @var array<int, array{type: string, text: string, url: string, phone_number: string}> */
    public array $buttons = [];

    /** @var array<string, string> */
    public array $examples = [];

    public function mount(?WaraTemplate $template = null): void
    {
        $this->guardService();

        if ($template === null || ! $template->exists) {
            return;
        }

        abort_unless($template->user_uuid === $this->userUuid(), 403);
        abort_unless($template->isEditable(), 403);

        $this->template = $template;

        $this->name = (string) $template->name;
        $this->category = $template->category->value;
        $this->parameterFormat = $template->parameter_format->value;
        $this->headerFormat = (string) data_get($template->components, 'header.format', 'NONE');
        $this->headerText = (string) data_get($template->components, 'header.text', '');
        $this->bodyText = (string) data_get($template->components, 'body.text', '');
        $this->footerText = (string) data_get($template->components, 'footer.text', '');
        $this->buttons = array_values((array) data_get($template->components, 'buttons', []));
        $this->examples = (array) data_get($template->components, 'body.examples', []);

        $this->syncExamples();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['bodyText', 'headerText', 'parameterFormat', 'headerFormat'], true)) {
            $this->syncExamples();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('wara_templates', 'name')
                    ->where('user_uuid', $this->userUuid())
                    ->ignore($this->template?->id),
            ],
            'category' => ['required', Rule::in(TemplateCategory::values())],
            'parameterFormat' => ['required', Rule::in(ParameterFormat::values())],
            'headerFormat' => ['required', Rule::in(TemplateValidator::HEADER_FORMATS)],
            'headerText' => ['nullable', 'string', 'max:60'],
            'bodyText' => ['required', 'string', 'max:1024'],
            'footerText' => ['nullable', 'string', 'max:60'],
            'buttons' => ['array', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('nama template'),
            'category' => __('kategori'),
            'parameterFormat' => __('format parameter'),
            'headerFormat' => __('format header'),
            'headerText' => __('teks header'),
            'bodyText' => __('body'),
            'footerText' => __('footer'),
        ];
    }

    public function addButton(): void
    {
        if (count($this->buttons) >= 10) {
            return;
        }

        $this->buttons[] = [
            'type' => 'QUICK_REPLY',
            'text' => '',
            'url' => '',
            'phone_number' => '',
        ];
    }

    public function removeButton(int $index): void
    {
        unset($this->buttons[$index]);

        $this->buttons = array_values($this->buttons);
    }

    public function save(bool $submit = false)
    {
        $this->guardService();
        $this->validate();

        $format = ParameterFormat::from($this->parameterFormat);

        $components = [
            'header' => [
                'format' => $this->headerFormat,
                'text' => $this->headerText !== '' ? $this->headerText : null,
            ],
            'body' => [
                'text' => $this->bodyText,
                'examples' => $this->examples,
            ],
            'footer' => [
                'text' => $this->footerText !== '' ? $this->footerText : null,
            ],
            'buttons' => $this->buttons,
        ];

        $normalized = app(TemplateValidator::class)->validate($components, $format);

        $template = $this->template ?? new WaraTemplate;

        $template->fill([
            'name' => $this->name,
            'category' => $this->category,
            'parameter_format' => $format,
            'components' => $normalized,
        ]);

        if (! $template->exists) {
            $template->user_uuid = $this->userUuid();
        }

        $template->save();

        if ($submit) {
            app(TemplateWorkflowService::class)->submit($template);
        }

        session()->flash(
            'wara_template_status',
            $submit
                ? __('Template disimpan dan diajukan untuk review.')
                : __('Template disimpan sebagai draft.')
        );

        return $this->redirectRoute('wara.guest.template.index');
    }

    /**
     * Variabel yang saat ini muncul di header/body.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $format = ParameterFormat::tryFrom($this->parameterFormat) ?? ParameterFormat::Positional;
        $validator = app(TemplateValidator::class);

        return array_values(array_unique(array_merge(
            $validator->variablesIn($this->headerText, $format),
            $validator->variablesIn($this->bodyText, $format),
        )));
    }

    public function previewText(): string
    {
        $text = $this->bodyText;

        foreach ($this->examples as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $text = (string) preg_replace(
                '/\{\{\s*'.preg_quote((string) $key, '/').'\s*\}\}/',
                (string) $value,
                $text
            );
        }

        $parts = [];

        if ($this->headerFormat === 'TEXT' && $this->headerText !== '') {
            $header = $this->headerText;

            foreach ($this->examples as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $header = (string) preg_replace(
                        '/\{\{\s*'.preg_quote((string) $key, '/').'\s*\}\}/',
                        (string) $value,
                        $header
                    );
                }
            }

            $parts[] = $header;
        }

        $parts[] = $text;

        if ($this->footerText !== '') {
            $parts[] = $this->footerText;
        }

        if ($this->buttons !== []) {
            $lines = [];

            foreach (array_values($this->buttons) as $index => $button) {
                $label = $button['text'] !== '' ? $button['text'] : __('Tombol :n', ['n' => $index + 1]);
                $lines[] = ($index + 1).'. '.$label;
            }

            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    protected function syncExamples(): void
    {
        $variables = $this->variables();

        $this->examples = array_intersect_key($this->examples, array_flip($variables));

        foreach ($variables as $variable) {
            $this->examples[$variable] ??= '';
        }
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
        return view('wara::livewire.pages.guest.template.form', [
            'variables' => $this->variables(),
            // Token literal `{{...}}` dibangun di PHP, bukan ditulis di Blade:
            // menulisnya langsung di dalam `{{ __('...') }}` membuat compiler
            // Blade berhenti di `}}` pertama dan view gagal dikompilasi.
            'parameterHint' => __('Gunakan :posisional untuk parameter posisional, atau :named untuk parameter bernama.', [
                'posisional' => '{'.'{1}'.'}'.' dan '.'{'.'{2}'.'}',
                'named' => '{'.'{nama}'.'}',
            ]),
        ])->layout((string) config('wara.guest_layout', 'rakaca::layouts.app'));
    }
}
