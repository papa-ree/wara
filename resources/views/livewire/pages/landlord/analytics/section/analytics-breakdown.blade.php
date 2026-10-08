<div class="space-y-6">
    {{-- Pesan keluar per client --}}
    <x-core::page-container>
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Pesan Keluar per Client') }}
                </h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Hanya pesan keluar — pesan masuk tidak punya client_id (PRD §14.3).') }}
                </p>
            </div>
            <x-lucide-users class="size-5 text-gray-400 dark:text-gray-500" />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <x-core::table-th :label="__('Client')" />
                        <x-core::table-th :label="__('Diterima')" align="right" />
                        <x-core::table-th :label="__('Ditolak')" align="right" />
                    </tr>
                </thead>
                <tbody>
                    @forelse($byClient as $row)
                        <tr wire:key="analytics-client-{{ $row['client_id'] ?? 'none' }}"
                            class="border-b border-gray-100 last:border-0 dark:border-gray-700/60 hover:bg-gray-50/80 dark:hover:bg-gray-700/30 transition-colors duration-150">
                            <td class="py-2.5 pr-4">
                                <div @class([
                                    'text-gray-900 dark:text-gray-100',
                                    'italic text-gray-500 dark:text-gray-400' => $row['client_id'] === null,
                                ])>
                                    {{ $row['name'] }}
                                </div>
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['accepted']) }}
                            </td>
                            <td @class([
                                'py-2.5 text-right tabular-nums',
                                'text-gray-700 dark:text-gray-300' => $row['rejected'] === 0,
                                'text-red-600 dark:text-red-400 font-medium' => $row['rejected'] > 0,
                            ])>
                                {{ number_format($row['rejected']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('Belum ada pesan keluar pada rentang ini.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-core::page-container>

    {{-- Trafik per device --}}
    <x-core::page-container>
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Trafik per Device') }}
                </h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Device adalah unit fisik yang terhubung ke gateway.') }}
                </p>
            </div>
            <x-lucide-smartphone class="size-5 text-gray-400 dark:text-gray-500" />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <x-core::table-th :label="__('Device')" />
                        <x-core::table-th :label="__('Masuk')" align="right" />
                        <x-core::table-th :label="__('Keluar')" align="right" />
                        <x-core::table-th :label="__('Total')" align="right" />
                    </tr>
                </thead>
                <tbody>
                    @forelse($byDevice as $row)
                        <tr wire:key="analytics-device-{{ $row['device_id'] ?? 'none' }}"
                            class="border-b border-gray-100 last:border-0 dark:border-gray-700/60 hover:bg-gray-50/80 dark:hover:bg-gray-700/30 transition-colors duration-150">
                            <td class="py-2.5 pr-4">
                                <div @class([
                                    'text-gray-900 dark:text-gray-100',
                                    'italic text-gray-500 dark:text-gray-400' => $row['device_id'] === null,
                                ])>
                                    {{ $row['display_name'] }}
                                </div>
                                @if($row['device_id'] !== null)
                                    <div class="font-mono text-xs text-gray-400 dark:text-gray-500">
                                        {{ $row['device_id'] }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['inbound']) }}
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['outbound']) }}
                            </td>
                            <td class="py-2.5 text-right font-medium text-gray-900 tabular-nums dark:text-gray-100">
                                {{ number_format($row['inbound'] + $row['outbound']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('Belum ada trafik pesan pada rentang ini.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-core::page-container>

    {{-- Pesan masuk per pengirim (keputusan PRD §14.3, 8 Okt 2026) --}}
    <x-core::page-container>
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Pesan Masuk per Pengirim') }}
                </h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Angka per nomor pengirim — bukan atribusi per client. Pesan masuk tidak pernah punya client_id (PRD §14.3).') }}
                </p>
            </div>
            <x-lucide-inbox class="size-5 text-gray-400 dark:text-gray-500" />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <x-core::table-th :label="__('Pengirim')" />
                        <x-core::table-th :label="__('Pesan')" align="right" />
                        <x-core::table-th :label="__('Baris Log')" align="right" />
                    </tr>
                </thead>
                <tbody>
                    @forelse($inboundBySender as $row)
                        <tr wire:key="analytics-sender-{{ $row['phone'] ?? 'none' }}"
                            class="border-b border-gray-100 last:border-0 dark:border-gray-700/60 hover:bg-gray-50/80 dark:hover:bg-gray-700/30 transition-colors duration-150">
                            <td class="py-2.5 pr-4">
                                <div @class([
                                    'text-gray-900 dark:text-gray-100',
                                    'italic text-gray-500 dark:text-gray-400' => $row['phone'] === null,
                                ])>
                                    {{ $row['label'] }}
                                </div>
                                @if($row['replayed'] > 0)
                                    <div class="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                        {{ __(':count baris yaitu pengulangan webhook (PRD §12.4)', ['count' => number_format($row['replayed'])]) }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4 text-right font-medium text-gray-900 tabular-nums dark:text-gray-100">
                                {{ number_format($row['messages']) }}
                            </td>
                            <td class="py-2.5 text-right text-gray-500 tabular-nums dark:text-gray-400">
                                {{ number_format($row['raw']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('Belum ada pesan masuk pada rentang ini.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-core::page-container>

    {{-- Reliability fan-out --}}
    <x-core::page-container>
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Fan-out ke Client') }}
                </h4>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Keberhasilan webhook GOWA diteruskan ke tiap endpoint client (PRD §12.4).') }}
                </p>
            </div>
            <x-lucide-webhook class="size-5 text-gray-400 dark:text-gray-500" />
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <x-core::table-th :label="__('Client')" />
                        <x-core::table-th :label="__('Terkirim')" align="right" />
                        <x-core::table-th :label="__('Gagal')" align="right" />
                        <x-core::table-th :label="__('Menunggu')" align="right" />
                        <x-core::table-th :label="__('Percobaan')" align="right" />
                    </tr>
                </thead>
                <tbody>
                    @forelse($fanOut as $row)
                        <tr wire:key="analytics-fanout-{{ $row['endpoint_id'] }}"
                            class="border-b border-gray-100 last:border-0 dark:border-gray-700/60 hover:bg-gray-50/80 dark:hover:bg-gray-700/30 transition-colors duration-150">
                            <td class="py-2.5 pr-4">
                                <div @class([
                                    'text-gray-900 dark:text-gray-100',
                                    'italic text-gray-500 dark:text-gray-400' => $row['client_name'] === __('(endpoint dihapus)'),
                                ])>
                                    {{ $row['client_name'] }}
                                </div>
                                @if($row['last_error'] !== null)
                                    {{--
                                        `last_error` ditampilkan mentah, hanya dipotong
                                        120 karakter. Isinya memang bisa memuat URL
                                        endpoint client, dan ini halaman landlord,
                                        operator sudah berhak melihat endpoint miliknya
                                        sendiri. Yang TIDAK ditampilkan: nilai `url` itu
                                        sendiri sebagai kolom, karena label client sudah
                                        cukup untuk mengenali endpointnya.
                                    --}}
                                    <div class="mt-0.5 font-mono text-xs text-red-600 dark:text-red-400 break-all">
                                        {{ \Illuminate\Support\Str::limit($row['last_error'], 120) }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['delivered']) }}
                            </td>
                            <td @class([
                                'py-2.5 pr-4 text-right tabular-nums',
                                'text-gray-700 dark:text-gray-300' => $row['failed'] === 0,
                                'text-red-600 dark:text-red-400 font-medium' => $row['failed'] > 0,
                            ])>
                                {{ number_format($row['failed']) }}
                            </td>
                            <td class="py-2.5 pr-4 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['pending']) }}
                            </td>
                            <td class="py-2.5 text-right text-gray-700 tabular-nums dark:text-gray-300">
                                {{ number_format($row['attempts']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                {{ __('Belum ada event yang diteruskan pada rentang ini.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-core::page-container>
</div>