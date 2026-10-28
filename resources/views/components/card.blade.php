@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'accent' => 'teal',
    'padding' => 'p-5',
])

@php
    $accentClasses = match ($accent) {
        'emerald' => 'from-emerald-500 to-emerald-600 shadow-emerald-500/25 dark:shadow-emerald-500/20',
        'sky' => 'from-sky-500 to-sky-600 shadow-sky-500/25 dark:shadow-sky-500/20',
        'amber' => 'from-amber-500 to-amber-600 shadow-amber-500/25 dark:shadow-amber-500/20',
        'rose' => 'from-rose-500 to-rose-600 shadow-rose-500/25 dark:shadow-rose-500/20',
        'violet' => 'from-violet-500 to-purple-600 shadow-violet-500/25 dark:shadow-violet-500/20',
        default => 'from-teal-500 to-teal-600 shadow-teal-500/25 dark:shadow-teal-500/20',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900']) }}>
    @if ($title)
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                @if ($icon)
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br {{ $accentClasses }} shadow-md">
                        <x-dynamic-component :component="'lucide-'.$icon" class="size-4 text-white" />
                    </div>
                @endif

                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $title }}
                    </h2>

                    @if ($subtitle)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $subtitle }}
                        </p>
                    @endif
                </div>
            </div>

            @isset($header)
                <div class="shrink-0 self-start sm:self-auto">
                    {{ $header }}
                </div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding }}">
        {{ $slot }}
    </div>
</div>
