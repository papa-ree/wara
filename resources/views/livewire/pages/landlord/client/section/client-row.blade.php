<tr wire:key="wara-client-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Primary column: nama + tipe. Always visible, jadi jadi anchor di mobile. --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <div class="font-medium text-sm text-gray-900 dark:text-gray-100">
            {{ $record->name }}
        </div>
        <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">
            {{ $record->type }}
        </div>
    </td>

    <td class="px-4 py-3.5 hidden md:table-cell">
        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $record->type }}</span>
    </td>

    {{-- Peta purpose -> device. Kolom utama: ini yang tidak bisa dijawab
    dari halaman Device sejak purpose pindah ke (client, purpose). --}}
    <td class="px-4 py-3.5">
        @if ($record->routes->isEmpty())
            <span class="text-xs text-gray-400 dark:text-gray-500">
                {{ __('belum dipetakan') }}
            </span>
        @else
            <div class="flex flex-wrap gap-1.5">
                @foreach ($record->routes->sortBy('purpose') as $route)
                    <span wire:key="wara-client-route-chip-{{ $route->getKey() }}"
                        class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs
                                                                                    {{ $route->outbound_enabled
                    ? 'border-teal-100 bg-teal-50 text-teal-700 dark:border-teal-800/40 dark:bg-teal-900/20 dark:text-teal-300'
                    : 'border-rose-100 bg-rose-50 text-rose-700 dark:border-rose-800/40 dark:bg-rose-900/20 dark:text-rose-400' }}">
                        <span class="font-mono">{{ $route->purpose }}</span>
                        <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">&rarr;</span>
                        <span class="font-mono">{{ $route->device_id }}</span>
                        @unless ($route->outbound_enabled)
                            <span class="sr-only">{{ __('kirim mati') }}</span>
                            <span aria-hidden="true">&times;</span>
                        @endunless
                    </span>
                @endforeach
            </div>
        @endif
    </td>

    {{-- Kesiapan. Menampilkan PENYEBAB tidak bisa melayani traffic, bukan
    sekadar badge hijau/abu, karena "aktif" saja tidak menjawab
    pertanyaan admin: kenapa request saya ditolak? --}}
    <td class="px-4 py-3.5 hidden lg:table-cell">
        @php $issues = $record->blockingReadinessIssues(); @endphp

        @if ($issues === [])
            <span
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium
                                            bg-emerald-50 text-emerald-700 border border-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400 dark:border-emerald-800/40">
                {{ __('siap') }}
            </span>
        @else
            <span
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium
                                            bg-amber-50 text-amber-700 border border-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40"
                title="{{ $issues[0]['message'] }}">
                {{ __('perlu perhatian') }}
            </span>
        @endif
    </td>

    <td class="px-4 py-3.5 hidden xl:table-cell">
        <span class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->created_at?->format('d M Y H:i') }}
        </span>
    </td>

    <td class="px-4 py-3.5 whitespace-nowrap w-px">
        <livewire:core.shared-components.item-actions
            :editUrl="route('wara.client.edit', $record->getKey())"
            :deleteId="$record->getKey()"
            deleteEvent="deleteWaraClient"
            :navigate="false"
            confirmMessage="{{ __('Client, route, dan endpoint webhook-nya ikut terhapus.') }}"
            wire:key="item-actions-{{ $record->getKey() }}" />
    </td>
</tr>