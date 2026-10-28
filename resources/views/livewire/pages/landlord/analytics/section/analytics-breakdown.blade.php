<div class="space-y-4 sm:space-y-6">
    {{-- Pesan keluar per client --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                    <x-lucide-users class="size-4 text-white" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Pesan Keluar per Client') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Hanya pesan keluar — pesan masuk tidak punya client_id (PRD §14.3).') }}
                    </p>
                </div>
            </div>

            <span class="inline-flex shrink-0 items-center self-start rounded-full bg-teal-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 dark:bg-teal-900/30 dark:text-teal-300 sm:self-auto">
                {{ $rangeLabel }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 px-5 dark:divide-white/[0.06]">
            @forelse($byClient as $row)
                <li wire:key="analytics-client-{{ $row['client_id'] ?? 'none' }}"
                    class="flex flex-col gap-2 py-3.5 transition-colors hover:bg-teal-50/40 dark:hover:bg-teal-900/10 sm:flex-row sm:items-center sm:justify-between">
                    <p @class([
                        'min-w-0 truncate text-sm font-medium text-gray-900 dark:text-gray-100',
                        'italic font-normal text-gray-500 dark:text-gray-400' => $row['client_id'] === null,
                    ])>
                        {{ $row['name'] }}
                    </p>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-teal-100 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                            {{ __('Diterima') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['accepted']) }}</span>
                        </span>

                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium',
                            'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' => $row['rejected'] === 0,
                            'border-rose-100 bg-rose-50 text-rose-700 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300' => $row['rejected'] > 0,
                        ])>
                            {{ __('Ditolak') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['rejected']) }}</span>
                        </span>
                    </div>
                </li>
            @empty
                <li class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700/50 dark:text-gray-500">
                        <x-lucide-inbox class="size-5" />
                    </span>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Belum ada pesan keluar pada rentang ini.') }}
                    </p>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Trafik per device --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-blue-600 shadow-md shadow-sky-500/25 dark:shadow-sky-500/20">
                    <x-lucide-smartphone class="size-4 text-white" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Trafik per Device') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Device adalah unit fisik yang terhubung ke gateway.') }}
                    </p>
                </div>
            </div>

            <span class="inline-flex shrink-0 items-center self-start rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-sky-700 dark:bg-sky-900/30 dark:text-sky-300 sm:self-auto">
                {{ $rangeLabel }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 px-5 dark:divide-white/[0.06]">
            @forelse($byDevice as $row)
                <li wire:key="analytics-device-{{ $row['device_id'] ?? 'none' }}"
                    class="flex flex-col gap-2 py-3.5 transition-colors hover:bg-sky-50/40 dark:hover:bg-sky-900/10 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p @class([
                            'truncate text-sm font-medium text-gray-900 dark:text-gray-100',
                            'italic font-normal text-gray-500 dark:text-gray-400' => $row['device_id'] === null,
                        ])>
                            {{ $row['display_name'] }}
                        </p>
                        @if ($row['device_id'] !== null)
                            <p class="mt-0.5 truncate font-mono text-xs text-gray-400 dark:text-gray-500">
                                {{ $row['device_id'] }}
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-sky-100 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 dark:border-sky-800/40 dark:bg-sky-900/20 dark:text-sky-300">
                            {{ __('Masuk') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['inbound']) }}</span>
                        </span>

                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-teal-100 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300">
                            {{ __('Keluar') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['outbound']) }}</span>
                        </span>

                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            {{ __('Total') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['inbound'] + $row['outbound']) }}</span>
                        </span>
                    </div>
                </li>
            @empty
                <li class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700/50 dark:text-gray-500">
                        <x-lucide-smartphone class="size-5" />
                    </span>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Belum ada trafik pesan pada rentang ini.') }}
                    </p>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Pesan masuk per pengirim (keputusan PRD §14.3, 8 Okt 2026) --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-md shadow-violet-500/25 dark:shadow-violet-500/20">
                    <x-lucide-inbox class="size-4 text-white" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Pesan Masuk per Pengirim') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Angka per nomor pengirim — bukan atribusi per client. Pesan masuk tidak pernah punya client_id (PRD §14.3).') }}
                    </p>
                </div>
            </div>

            <span class="inline-flex shrink-0 items-center self-start rounded-full bg-violet-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 dark:bg-violet-900/30 dark:text-violet-300 sm:self-auto">
                {{ $rangeLabel }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 px-5 dark:divide-white/[0.06]">
            @forelse($inboundBySender as $row)
                <li wire:key="analytics-sender-{{ $row['phone'] ?? 'none' }}"
                    class="flex flex-col gap-2 py-3.5 transition-colors hover:bg-violet-50/40 dark:hover:bg-violet-900/10 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p @class([
                            'truncate text-sm font-medium text-gray-900 dark:text-gray-100',
                            'italic font-normal text-gray-500 dark:text-gray-400' => $row['phone'] === null,
                        ])>
                            {{ $row['label'] }}
                        </p>
                        @if ($row['replayed'] > 0)
                            <p class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                {{ __(':count baris yaitu pengulangan webhook (PRD §12.4)', ['count' => number_format($row['replayed'])]) }}
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-violet-100 bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700 dark:border-violet-800/40 dark:bg-violet-900/20 dark:text-violet-300">
                            {{ __('Pesan') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['messages']) }}</span>
                        </span>

                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                            {{ __('Baris Log') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['raw']) }}</span>
                        </span>
                    </div>
                </li>
            @empty
                <li class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700/50 dark:text-gray-500">
                        <x-lucide-inbox class="size-5" />
                    </span>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Belum ada pesan masuk pada rentang ini.') }}
                    </p>
                </li>
            @endforelse
        </ul>
    </div>

    {{-- Reliability fan-out --}}
    <div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-md shadow-amber-500/25 dark:shadow-amber-500/20">
                    <x-lucide-webhook class="size-4 text-white" />
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Fan-out ke Client') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Keberhasilan webhook GOWA diteruskan ke tiap endpoint client (PRD §12.4).') }}
                    </p>
                </div>
            </div>

            <span class="inline-flex shrink-0 items-center self-start rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 sm:self-auto">
                {{ $rangeLabel }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 px-5 dark:divide-white/[0.06]">
            @forelse($fanOut as $row)
                <li wire:key="analytics-fanout-{{ $row['endpoint_id'] }}"
                    class="flex flex-col gap-3 py-3.5 transition-colors hover:bg-amber-50/40 dark:hover:bg-amber-900/10 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p @class([
                            'truncate text-sm font-medium text-gray-900 dark:text-gray-100',
                            'italic font-normal text-gray-500 dark:text-gray-400' => $row['client_name'] === __('(endpoint dihapus)'),
                        ])>
                            {{ $row['client_name'] }}
                        </p>

                        @if ($row['last_error'] !== null)
                            <p class="mt-1 break-all font-mono text-xs text-rose-600 dark:text-rose-400">
                                {{ \Illuminate\Support\Str::limit($row['last_error'], 120) }}
                            </p>
                        @endif

                        @if ($row['last_status_code'] !== null)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Status terakhir: HTTP :code', ['code' => $row['last_status_code']]) }}
                            </p>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-1.5 sm:flex sm:flex-wrap sm:items-center">
                        <span class="inline-flex items-center justify-between gap-1.5 rounded-lg border border-teal-100 bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300 sm:justify-start">
                            {{ __('Terkirim') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['delivered']) }}</span>
                        </span>

                        <span @class([
                            'inline-flex items-center justify-between gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium sm:justify-start',
                            'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' => $row['failed'] === 0,
                            'border-rose-100 bg-rose-50 text-rose-700 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-300' => $row['failed'] > 0,
                        ])>
                            {{ __('Gagal') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['failed']) }}</span>
                        </span>

                        <span class="inline-flex items-center justify-between gap-1.5 rounded-lg border border-amber-100 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300 sm:justify-start">
                            {{ __('Menunggu') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['pending']) }}</span>
                        </span>

                        <span class="inline-flex items-center justify-between gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 sm:justify-start">
                            {{ __('Percobaan') }}
                            <span class="font-semibold tabular-nums">{{ number_format($row['attempts']) }}</span>
                        </span>
                    </div>
                </li>
            @empty
                <li class="flex flex-col items-center justify-center py-10 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-700/50 dark:text-gray-500">
                        <x-lucide-webhook class="size-5" />
                    </span>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Belum ada event yang diteruskan pada rentang ini.') }}
                    </p>
                </li>
            @endforelse
        </ul>
    </div>
</div>
