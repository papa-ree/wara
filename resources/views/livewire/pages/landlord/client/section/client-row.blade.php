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
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs border
                                                                                    {{ $route->outbound_enabled
                    ? 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'
                    : 'bg-red-50 text-red-700 border-red-100 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800/40' }}">
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
                                            bg-green-50 text-green-700 border border-green-100 dark:bg-green-900/20 dark:text-green-400 dark:border-green-800/40">
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