{{--
    Header Analitik Wara — Filter komprehensif.

    Tiga tier filter:
    1. Preset periode    — 4 tombol segmented: Hari / Minggu / Bulan / Tahun
    2. Custom date range — dua input date (from / to)
    3. Filter konteks    — dropdown Device dan Client (opsional)

    🔴 Masalah tombol aktif:
    State aktif dihitung dari $filter (server-side), tapi saat user klik tombol,
    UI perlu langsung merespons sebelum round-trip Livewire selesai.
    Solusinya: Alpine.js memegang `activeRange` secara lokal (optimistic update),
    dan tombol membaca dari Alpine — bukan dari $filter Blade.

    🔴 Semua perubahan di-dispatch ke `Index` via event. Header tidak menyimpan
    state filter yang sesungguhnya — hanya optimistic local state untuk UX.
--}}
<div
    class="space-y-3"
    x-data="{
        {{-- Optimistic local state — diupdate segera saat klik, sebelum server merespons --}}
        activeRange: {{ $filter->isCustomRange() ? 'null' : $filter->hours }},
        showCustom: {{ $filter->isCustomRange() ? 'true' : 'false' }},
        fromDate: '{{ $filter->from?->format('Y-m-d') ?? '' }}',
        toDate: '{{ $filter->until?->format('Y-m-d') ?? '' }}',
        deviceId: '{{ $filter->deviceId ?? '' }}',
        clientId: '{{ $filter->clientId ?? '' }}',

        selectPreset(hours) {
            this.activeRange = hours
            this.showCustom = false
            this.fromDate = ''
            this.toDate = ''
            $dispatch('analytics-range-changed', { range: hours })
        },

        applyCustomRange() {
            if (!this.fromDate || !this.toDate) return
            this.activeRange = null
            $dispatch('analytics-custom-range', { from: this.fromDate, to: this.toDate })
        },

        applyContextFilters() {
            $dispatch('analytics-filters-changed', { deviceId: this.deviceId, clientId: this.clientId })
        },

        resetCustom() {
            this.showCustom = false
            this.fromDate = ''
            this.toDate = ''
            this.activeRange = {{ \Bale\Wara\Analytics\WaraAnalytics::DEFAULT_RANGE_HOURS }}
            $dispatch('analytics-range-changed', { range: {{ \Bale\Wara\Analytics\WaraAnalytics::DEFAULT_RANGE_HOURS }} })
        }
    }"
>
    {{-- ── Hero header ── --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 via-teal-700 to-emerald-800 p-5 text-white shadow-xl sm:p-6 dark:from-teal-800 dark:via-teal-900 dark:to-emerald-950">
        {{-- Decorative blobs --}}
        <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 h-40 w-40 rounded-full bg-emerald-400/20 blur-2xl"></div>

        <div class="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">

            {{-- Judul & info periode aktif --}}
            <div class="flex items-start gap-3 sm:items-center">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/15 shadow-inner ring-1 ring-white/20 backdrop-blur-sm">
                    <x-lucide-gauge class="size-5 text-white" />
                </div>

                <div class="min-w-0">
                    <h1 class="text-lg font-bold tracking-tight text-white sm:text-xl">
                        {{ __('Analitik Wara') }}
                    </h1>

                    {{-- Deskripsi: update optimis via Alpine untuk custom range, fallback ke server --}}
                    <p class="mt-0.5 text-sm text-teal-100/80">
                        <span x-show="showCustom && fromDate && toDate"
                              x-text="fromDate + ' – ' + toDate"
                              class="tabular-nums"></span>
                        <span x-show="!showCustom || !fromDate || !toDate">
                            {{ $filter->description() }}
                        </span>
                    </p>

                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-teal-100/70">
                        <span class="inline-flex items-center gap-1.5">
                            <x-lucide-calendar-range class="size-3.5" />
                            <span x-show="!showCustom">{{ $filter->label() }}</span>
                            <span x-show="showCustom && fromDate"
                                  x-text="fromDate && toDate ? fromDate + ' – ' + toDate : '{{ $filter->label() }}'"></span>
                        </span>

                        @if($filter->deviceId !== null)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold">
                                <x-lucide-smartphone class="size-3" />
                                {{ __('Device aktif') }}
                            </span>
                        @endif

                        @if($filter->clientId !== null)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold">
                                <x-lucide-users class="size-3" />
                                {{ __('Client aktif') }}
                            </span>
                        @endif

                        <a href="{{ route('wara.log.index') }}"
                            class="inline-flex items-center gap-1.5 font-medium text-teal-100/80 transition hover:text-white">
                            <x-lucide-scroll-text class="size-3.5" />
                            {{ __('Lihat Log') }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- ── Filter panel ── --}}
            <div class="flex w-full flex-col gap-3 lg:w-auto lg:min-w-[22rem] xl:min-w-[26rem]">

                {{-- Tier 1: Preset periods --}}
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-teal-100/70">
                            {{ __('Periode') }}
                        </span>
                        <button
                            type="button"
                            x-on:click="showCustom = !showCustom"
                            class="inline-flex items-center gap-1 text-[10px] font-medium text-teal-100/70 transition hover:text-white"
                        >
                            <x-lucide-calendar class="size-3" />
                            <span x-text="showCustom ? '{{ __('Pakai preset') }}' : '{{ __('Tanggal kustom') }}'"></span>
                        </button>
                    </div>

                    {{-- Segmented control — tombol membaca activeRange dari Alpine (optimistic) --}}
                    <div
                        x-show="!showCustom"
                        class="grid grid-cols-4 gap-1 rounded-xl border border-white/15 bg-white/10 p-1 backdrop-blur-sm"
                        role="group"
                        aria-label="{{ __('Pilih rentang waktu') }}"
                    >
                        @foreach($ranges as $hours => $label)
                            <button
                                type="button"
                                x-on:click="selectPreset({{ $hours }})"
                                :aria-pressed="activeRange === {{ $hours }} ? 'true' : 'false'"
                                :class="activeRange === {{ $hours }} && !showCustom
                                    ? 'bg-white text-teal-800 shadow-sm'
                                    : 'text-teal-50 hover:bg-white/10'"
                                class="inline-flex min-h-9 items-center justify-center rounded-lg px-2 py-1.5 text-xs font-semibold transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Loading indicator saat request sedang jalan --}}
                    <div wire:loading wire:target="selectRange,setCustomRange,setFilters"
                         class="mt-1.5 flex items-center gap-1.5 text-[10px] text-teal-100/60">
                        <svg class="size-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ __('Memuat data...') }}
                    </div>
                </div>

                {{-- Tier 2: Custom date range --}}
                <div
                    x-show="showCustom"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                >
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-teal-100/70">
                                {{ __('Dari') }}
                            </label>
                            <input
                                type="date"
                                x-model="fromDate"
                                :max="toDate || ''"
                                class="w-full rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-xs font-medium text-white backdrop-blur-sm transition focus:border-white/40 focus:bg-white/15 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-teal-100/70">
                                {{ __('Sampai') }}
                            </label>
                            <input
                                type="date"
                                x-model="toDate"
                                :min="fromDate || ''"
                                class="w-full rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-xs font-medium text-white backdrop-blur-sm transition focus:border-white/40 focus:bg-white/15 focus:outline-none"
                            />
                        </div>
                    </div>

                    <div class="mt-2 flex gap-2">
                        <button
                            type="button"
                            x-on:click="applyCustomRange()"
                            :disabled="!fromDate || !toDate"
                            class="flex-1 rounded-lg bg-white/15 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/25 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('Terapkan') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="resetCustom()"
                            class="rounded-lg px-3 py-2 text-xs font-semibold text-teal-100/70 transition hover:text-white"
                        >
                            {{ __('Reset') }}
                        </button>
                    </div>
                </div>

                {{-- Tier 3: Filter device & client --}}
                @if($devices->isNotEmpty() || $clients->isNotEmpty())
                    <div class="rounded-xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-teal-100/70">
                            {{ __('Filter Konteks') }}
                        </p>

                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @if($devices->isNotEmpty())
                                <div>
                                    <label class="mb-1 block text-[10px] text-teal-100/60">
                                        <x-lucide-smartphone class="mr-0.5 inline size-3" />
                                        {{ __('Device') }}
                                    </label>
                                    <select
                                        x-model="deviceId"
                                        x-on:change="applyContextFilters()"
                                        class="w-full rounded-lg border border-white/20 bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white backdrop-blur-sm transition focus:border-white/40 focus:bg-white/15 focus:outline-none [&>option]:bg-gray-900 [&>option]:text-white"
                                    >
                                        <option value="">{{ __('Semua device') }}</option>
                                        @foreach($devices as $device)
                                            <option value="{{ $device->device_id }}">
                                                {{ $device->display_name ?: $device->device_id }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if($clients->isNotEmpty())
                                <div>
                                    <label class="mb-1 block text-[10px] text-teal-100/60">
                                        <x-lucide-users class="mr-0.5 inline size-3" />
                                        {{ __('Client') }}
                                        <span class="opacity-60">({{ __('keluar') }})</span>
                                    </label>
                                    <select
                                        x-model="clientId"
                                        x-on:change="applyContextFilters()"
                                        class="w-full rounded-lg border border-white/20 bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white backdrop-blur-sm transition focus:border-white/40 focus:bg-white/15 focus:outline-none [&>option]:bg-gray-900 [&>option]:text-white"
                                    >
                                        <option value="">{{ __('Semua client') }}</option>
                                        @foreach($clients as $client)
                                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>

                        @if($filter->deviceId !== null || $filter->clientId !== null)
                            <button
                                type="button"
                                x-on:click="deviceId = ''; clientId = ''; applyContextFilters()"
                                class="mt-2 inline-flex items-center gap-1.5 text-[10px] font-medium text-teal-100/70 transition hover:text-white"
                            >
                                <x-lucide-x class="size-3" />
                                {{ __('Reset device & client') }}
                            </button>
                        @endif
                    </div>
                @endif

            </div>
        </div>
    </div>

    {{-- Info atribusi --}}
    <div class="flex items-start gap-3 rounded-xl border border-teal-100 bg-teal-50/60 px-4 py-3 dark:border-teal-800/30 dark:bg-teal-900/15">
        <x-lucide-info class="mt-0.5 size-4 shrink-0 text-teal-500 dark:text-teal-400" />
        <p class="text-sm text-teal-800 dark:text-teal-200">
            {{ __('Atribusi per client hanya untuk pesan KELUAR. Pesan masuk dicatat per device, karena satu device bisa melayani beberapa client.') }}
        </p>
    </div>

    {{-- Active filter chips --}}
    @if($filter->isCustomRange() || $filter->deviceId !== null || $filter->clientId !== null)
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Filter aktif:') }}</span>

            @if($filter->isCustomRange())
                <span class="inline-flex items-center gap-1.5 rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                    <x-lucide-calendar-range class="size-3" />
                    {{ $filter->label() }}
                    <button type="button"
                        x-on:click="resetCustom()"
                        class="ml-0.5 rounded-full text-teal-500 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-200"
                        aria-label="{{ __('Hapus filter tanggal') }}">
                        <x-lucide-x class="size-3" />
                    </button>
                </span>
            @endif

            @if($filter->deviceId !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 dark:border-sky-800/40 dark:bg-sky-900/20 dark:text-sky-300">
                    <x-lucide-smartphone class="size-3" />
                    {{ $devices->firstWhere('device_id', $filter->deviceId)?->display_name ?? $filter->deviceId }}
                    <button type="button"
                        x-on:click="deviceId = ''; applyContextFilters()"
                        class="ml-0.5 rounded-full text-sky-500 hover:text-sky-700 dark:text-sky-400"
                        aria-label="{{ __('Hapus filter device') }}">
                        <x-lucide-x class="size-3" />
                    </button>
                </span>
            @endif

            @if($filter->clientId !== null)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700 dark:border-violet-800/40 dark:bg-violet-900/20 dark:text-violet-300">
                    <x-lucide-users class="size-3" />
                    {{ $clients->firstWhere('id', $filter->clientId)?->name ?? $filter->clientId }}
                    <button type="button"
                        x-on:click="clientId = ''; applyContextFilters()"
                        class="ml-0.5 rounded-full text-violet-500 hover:text-violet-700 dark:text-violet-400"
                        aria-label="{{ __('Hapus filter client') }}">
                        <x-lucide-x class="size-3" />
                    </button>
                </span>
            @endif
        </div>
    @endif
</div>
