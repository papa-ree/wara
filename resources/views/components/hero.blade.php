@props([
    'title',
    'subtitle' => null,
    'icon' => 'message-circle',
    'badge' => null,
    'badgeColor' => 'teal',
])

@php
    $badgeClasses = match ($badgeColor) {
        'amber' => 'bg-amber-400/20 text-amber-50 ring-amber-200/30',
        'rose' => 'bg-rose-400/20 text-rose-50 ring-rose-200/30',
        'sky' => 'bg-sky-400/20 text-sky-50 ring-sky-200/30',
        default => 'bg-white/15 text-teal-50 ring-white/20',
    };
@endphp

<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 via-teal-700 to-emerald-800 p-5 text-white shadow-xl sm:p-6 dark:from-teal-800 dark:via-teal-900 dark:to-emerald-950">
    {{-- Dekorasi blob: wajib ada minimal dua. --}}
    <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
    <div class="pointer-events-none absolute -bottom-10 -left-10 h-40 w-40 rounded-full bg-emerald-400/20 blur-2xl"></div>
    <div class="pointer-events-none absolute bottom-0 right-24 h-24 w-24 rounded-full bg-teal-300/15 blur-xl"></div>

    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-start gap-4 sm:items-center">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/15 shadow-inner ring-1 ring-white/20 backdrop-blur-sm">
                <x-dynamic-component :component="'lucide-'.$icon" class="size-6 text-white" />
            </div>

            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">
                        {{ $title }}
                    </h1>

                    @if ($badge)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 {{ $badgeClasses }}">
                            {{ $badge }}
                        </span>
                    @endif
                </div>

                @if ($subtitle)
                    <p class="mt-0.5 max-w-3xl text-sm text-teal-100/80">
                        {{ $subtitle }}
                    </p>
                @endif

                @isset($meta)
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-teal-100/70">
                        {{ $meta }}
                    </div>
                @endisset
            </div>
        </div>

        @isset($actions)
            <div class="flex w-full shrink-0 flex-wrap items-center gap-2 lg:w-auto lg:justify-end">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
