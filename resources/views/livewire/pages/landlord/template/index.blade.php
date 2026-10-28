<div class="space-y-6">
    <x-wara::hero
        :title="__('Review Template')"
        :subtitle="__('Template yang diajukan guest harus disetujui sebelum bisa dipakai lewat API.')"
        icon="clipboard-check"
        :badge="__('Review')"
        badge-color="amber">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-clock class="size-3.5" />
                {{ __('Menunggu review: :count', ['count' => $this->pendingCount]) }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5" />
                {{ __('Hanya APPROVED yang bisa dipakai API') }}
            </span>
        </x-slot>
    </x-wara::hero>

    @if (session('wara_template_status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('wara_template_status') }}
        </div>
    @endif

    <x-wara::card
        :title="__('Antrean Template')"
        :subtitle="__('Tinjau isi, kategori, dan contoh parameter sebelum menyetujui.')"
        icon="list-checks"
        padding="p-0">
        <x-slot name="header">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-100 bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
                <span class="inline-block size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                {{ __('Menunggu review: :count', ['count' => $this->pendingCount]) }}
            </span>
        </x-slot>

        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <label for="template-status-filter" class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Status') }}
                </label>
                <select id="template-status-filter"
                        wire:model.live="status"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    <option value="" @selected($status === '')>{{ __('Semua') }}</option>
                    @foreach (\Bale\Wara\Enums\TemplateStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected($status === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-full sm:w-72">
                <input type="search"
                       wire:model.live.debounce.400ms="search"
                       placeholder="{{ __('Cari nama template / UUID pemilik') }}"
                       class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
            </div>
        </div>

        {{-- Skeleton singkat saat filter berubah. --}}
        <div wire:loading wire:target="status,search" class="space-y-2 px-5 py-4">
            @for ($i = 0; $i < 4; $i++)
                <div class="flex items-center gap-4">
                    <div class="h-9 w-9 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3.5 w-40 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                        <div class="h-3 w-64 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                </div>
            @endfor
        </div>

        <div wire:loading.remove wire:target="status,search" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-white/[0.06]">
                <thead class="bg-gray-50/80 dark:bg-gray-900/60">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Template') }}</th>
                        <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 lg:table-cell dark:text-gray-400">{{ __('Pemilik') }}</th>
                        <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 md:table-cell dark:text-gray-400">{{ __('Kategori') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Status') }}</th>
                        <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 xl:table-cell dark:text-gray-400">{{ __('Diajukan') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/[0.06]">
                    @forelse ($this->templates as $template)
                        <tr wire:key="template-row-{{ $template->id }}" class="transition-colors duration-150 hover:bg-teal-50/40 dark:hover:bg-teal-900/10">
                            <td class="px-4 py-3.5">
                                <div class="font-mono text-sm font-medium text-gray-900 dark:text-gray-100">{{ $template->name }}</div>
                                <div class="mt-0.5 max-w-md truncate text-xs text-gray-500 dark:text-gray-500">{{ $template->bodyPreview(90) }}</div>
                            </td>
                            <td class="hidden px-4 py-3.5 text-sm text-gray-700 lg:table-cell dark:text-gray-300">
                                {{ $template->user?->name ?? $template->user_uuid }}
                            </td>
                            <td class="hidden px-4 py-3.5 md:table-cell">
                                <span class="inline-flex items-center rounded-md border border-teal-100 bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                                    {{ $template->category->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $template->status->badgeClasses() }}">
                                    {{ $template->status->label() }}
                                </span>
                            </td>
                            <td class="hidden px-4 py-3.5 text-sm text-gray-700 xl:table-cell dark:text-gray-300">
                                {{ $template->submitted_at?->format('d M Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <a href="{{ route('wara.template.review', $template->id) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-white px-3 py-1.5 text-sm font-medium text-teal-700 transition hover:bg-teal-50 dark:border-teal-800/40 dark:bg-gray-900 dark:text-teal-300 dark:hover:bg-teal-900/20">
                                    <x-lucide-eye class="size-3.5" />
                                    {{ __('Lihat') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-wara::empty-state
                                    icon="clipboard-x"
                                    :title="__('Tidak ada template pada filter ini.')"
                                    :description="__('Coba ubah status atau kata kunci pencarian.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->templates->hasPages())
            <div class="border-t border-gray-100 p-4 dark:border-white/[0.06]">
                {{ $this->templates->links() }}
            </div>
        @endif
    </x-wara::card>
</div>
