<div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
    <x-wara::hero
        :title="__('Template Pesan')"
        :subtitle="__('Susun template untuk dipakai layanan lain lewat API Wara. Template hanya bisa dikirim setelah disetujui admin.')"
        icon="file-text"
        :badge="__('Guest')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5" />
                {{ __('Hanya template APPROVED yang bisa dipakai API') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-message-square-lock class="size-3.5" />
                {{ __('OTP tetap dikirim sebagai teks biasa') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.guest.template.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 shadow-sm transition hover:bg-teal-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-plus class="size-4" />
                {{ __('Template Baru') }}
            </a>
        </x-slot>
    </x-wara::hero>

    @if (session('wara_template_status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('wara_template_status') }}
        </div>
    @endif

    @error('status')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300">
            {{ $message }}
        </div>
    @enderror

    @forelse ($this->templates as $template)
        <x-wara::card
            padding="p-5"
            wire:key="guest-template-{{ $template->id }}">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                        <x-lucide-file-text class="size-5 text-white" />
                    </div>

                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $template->name }}
                            </h2>

                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $template->status->badgeClasses() }}">
                                {{ $template->status->label() }}
                            </span>

                            <span class="inline-flex items-center rounded-md border border-teal-100 bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                                {{ $template->category->label() }}
                            </span>
                        </div>

                        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-400">
                            {{ $template->bodyPreview(140) }}
                        </p>

                        <p class="text-xs text-gray-500 dark:text-gray-500">
                            {{ $template->parameter_format->label() }}
                            &middot;
                            {{ __('Diperbarui :time', ['time' => $template->updated_at?->diffForHumans()]) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:pl-4">
                    @if ($template->isEditable())
                        <a href="{{ route('wara.guest.template.edit', $template->id) }}"
                           class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                            <x-lucide-pencil class="size-3.5" />
                            {{ __('Edit') }}
                        </a>
                    @endif

                    @if ($template->status === \Bale\Wara\Enums\TemplateStatus::Draft || $template->status === \Bale\Wara\Enums\TemplateStatus::Rejected)
                        <button type="button"
                                wire:click="submit('{{ $template->id }}')"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:opacity-50">
                            <x-lucide-send class="size-3.5" />
                            {{ __('Ajukan Review') }}
                        </button>
                    @endif

                    @if ($template->isEditable())
                        <button type="button"
                                wire:click="delete('{{ $template->id }}')"
                                wire:confirm="{{ __('Hapus template ini?') }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">
                            <x-lucide-trash-2 class="size-3.5" />
                            {{ __('Hapus') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($template->status === \Bale\Wara\Enums\TemplateStatus::Rejected && $template->rejection_reason)
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300">
                    <span class="font-medium">{{ __('Alasan penolakan:') }}</span>
                    {{ $template->rejection_reason }}
                </div>
            @endif
        </x-wara::card>
    @empty
        <x-wara::card padding="p-0">
            <x-wara::empty-state
                icon="file-plus"
                :title="__('Belum ada template. Buat template pertama untuk mulai.')"
                :description="__('Template membantu menjaga isi pesan konsisten dan mengurangi risiko pesan teks bebas.')">
                <x-slot name="action">
                    <a href="{{ route('wara.guest.template.create') }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-teal-700">
                        <x-lucide-plus class="size-3.5" />
                        {{ __('Template Baru') }}
                    </a>
                </x-slot>
            </x-wara::empty-state>
        </x-wara::card>
    @endforelse
</div>
