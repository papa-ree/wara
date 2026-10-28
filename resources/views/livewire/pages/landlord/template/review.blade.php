<div class="mx-auto max-w-5xl space-y-6">
    <x-wara::hero
        :title="$template->name"
        :subtitle="__('Diajukan oleh :owner', ['owner' => $template->user?->name ?? $template->user_uuid])"
        icon="file-search"
        :badge="$template->status->label()"
        badge-color="amber">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-folder-tree class="size-3.5" />
                {{ $template->category->label() }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-braces class="size-3.5" />
                {{ $template->parameter_format->label() }}
            </span>
        </x-slot>

        <x-slot name="actions">
            <a href="{{ route('wara.template.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-arrow-left class="size-4" />
                {{ __('Kembali ke antrean') }}
            </a>
        </x-slot>
    </x-wara::hero>

    @if (session('wara_template_status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('wara_template_status') }}
        </div>
    @endif

    @error('review')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-wara::card
                :title="__('Pratinjau Template')"
                :subtitle="__('Tampilan pesan setelah parameter contoh diisi.')"
                icon="message-square-text"
                accent="teal">
                <x-slot name="header">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $template->status->badgeClasses() }}">
                            {{ $template->status->label() }}
                        </span>
                        <span class="inline-flex items-center rounded-md border border-teal-100 bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                            {{ $template->category->label() }}
                        </span>
                    </div>
                </x-slot>

                <div class="rounded-xl bg-[#e5ddd5] p-4 dark:bg-gray-800">
                    <div class="rounded-lg bg-white px-4 py-3 text-sm text-gray-800 shadow-sm dark:bg-gray-900 dark:text-gray-100">
                        <p class="whitespace-pre-line break-words">{{ $preview }}</p>
                    </div>
                </div>

                @if (! empty($template->components['body']['examples'] ?? []))
                    <div class="mt-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Contoh Parameter') }}</h3>
                        <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($template->components['body']['examples'] as $key => $value)
                                @php($token = '{'.'{'.$key.'}'.'}')
                                <div class="rounded-lg border border-gray-100 bg-gray-50/60 px-3 py-2 text-sm dark:border-white/[0.06] dark:bg-gray-900/60">
                                    <dt class="font-mono text-xs text-teal-600 dark:text-teal-400">{{ $token }}</dt>
                                    <dd class="text-gray-800 dark:text-gray-200">{{ $value !== '' ? $value : '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if (! empty($template->components['buttons'] ?? []))
                    <div class="mt-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Tombol') }}</h3>
                        <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($template->components['buttons'] as $index => $button)
                                <li class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 dark:border-white/[0.06]">
                                    <span class="flex size-5 shrink-0 items-center justify-center rounded-md bg-teal-50 text-[10px] font-bold text-teal-700 dark:bg-teal-900/20 dark:text-teal-300">{{ $index + 1 }}</span>
                                    <span>{{ $button['text'] ?? '' }}</span>
                                    <span class="ml-auto text-xs text-gray-500 dark:text-gray-500">{{ $button['type'] ?? '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-wara::card>

            @if ($template->status === \Bale\Wara\Enums\TemplateStatus::Pending)
                <x-wara::card
                    :title="__('Keputusan')"
                    :subtitle="__('Setujui bila isi sudah sesuai, atau tolak dengan alasan yang jelas.')"
                    icon="gavel"
                    accent="amber">
                    <div>
                        <label for="rejection-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Alasan penolakan') }}
                        </label>
                        <textarea id="rejection-reason"
                                  rows="3"
                                  wire:model="rejectionReason"
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            {{ __('Wajib diisi hanya jika menolak. Alasan akan terlihat oleh pemilik template.') }}
                        </p>
                        @error('rejectionReason') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button"
                                wire:click="approve"
                                wire:confirm="{{ __('Setujui template ini? Template yang disetujui tidak bisa diubah.') }}"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50">
                            <x-lucide-check class="size-4" />
                            {{ __('Setujui') }}
                        </button>

                        <button type="button"
                                wire:click="reject"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50 dark:border-rose-800/40 dark:text-rose-400 dark:hover:bg-rose-900/20">
                            <x-lucide-x class="size-4" />
                            {{ __('Tolak') }}
                        </button>
                    </div>
                </x-wara::card>
            @elseif ($template->status === \Bale\Wara\Enums\TemplateStatus::Rejected && $template->rejection_reason)
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300">
                    <span class="font-medium">{{ __('Alasan penolakan:') }}</span>
                    {{ $template->rejection_reason }}
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <x-wara::card
                :title="__('Jejak Review')"
                :subtitle="__('Waktu dan reviewer terakhir.')"
                icon="history"
                accent="sky">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-500">
                            <x-lucide-send class="size-3.5" />
                            {{ __('Diajukan') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">{{ $template->submitted_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-500">
                            <x-lucide-clock class="size-3.5" />
                            {{ __('Direview') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">{{ $template->reviewed_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-500">
                            <x-lucide-user-check class="size-3.5" />
                            {{ __('Oleh') }}
                        </dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-gray-200">{{ $template->reviewer?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </x-wara::card>
        </div>
    </div>
</div>
