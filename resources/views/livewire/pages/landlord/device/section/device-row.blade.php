<tr wire:key="wara-device-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Nama device + Session ID --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <div class="flex items-center gap-3">
            <div @class([
                'size-9 rounded-lg flex items-center justify-center shrink-0 border',
                'bg-green-50 border-green-200/50 dark:bg-green-900/20 dark:border-green-700/30' => $record->isSendable(),
                'bg-amber-50 border-amber-200/50 dark:bg-amber-900/20 dark:border-amber-700/30' => $record->is_present && ! $record->is_logged_in,
                'bg-gray-50 border-gray-200/50 dark:bg-gray-900/20 dark:border-gray-700/30' => ! $record->is_present,
            ])>
                <x-lucide-smartphone @class([
                    'size-4',
                    'text-green-600 dark:text-green-400' => $record->isSendable(),
                    'text-amber-600 dark:text-amber-400' => $record->is_present && ! $record->is_logged_in,
                    'text-gray-500 dark:text-gray-400' => ! $record->is_present,
                ]) />
            </div>
            <div class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                    {{ $record->display_name ?? __('(tanpa nama)') }}
                </span>
                <p class="text-xs font-mono text-gray-500 dark:text-gray-400 truncate mt-0.5">
                    {{ $record->device_id }}
                </p>
            </div>
        </div>
    </td>

    {{-- 🔴 Ganti dari `$record->purpose` ke route milik device.
         Kolom `wara_sessions.purpose` sudah dihapus, jadi baris lama
         memanggil properti yang tidak ada dan mengosongkan kolom ini.
         Penugasan(device, purpose) milik CLIENT, jadi yang tampil di sini
         daftar client apa saja yang memakai device ini - jawaban yang
         sebenarnya dicari admin saat membuka halaman Device.
    --}}
    <td class="px-4 py-3.5 hidden lg:table-cell">
        @php
            $deviceRoutes = $record->routes()->with('client:id,name')->get();
        @endphp

        @if ($deviceRoutes->isEmpty())
            <span class="text-sm text-gray-400 dark:text-gray-500">
                {{ __('Belum dipakai client mana pun') }}
            </span>
        @else
            <div class="flex flex-wrap gap-1.5">
                @foreach ($deviceRoutes as $route)
                    <span wire:key="device-route-chip-{{ $route->getKey() }}"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs border
                            {{ $route->outbound_enabled
                                ? 'bg-indigo-50 text-indigo-700 border-indigo-100 dark:bg-indigo-900/20 dark:text-indigo-400 dark:border-indigo-800/40'
                                : 'bg-red-50 text-red-700 border-red-100 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800/40' }}">
                        <span class="font-mono">{{ $route->purpose }}</span>
                        <span class="text-indigo-300 dark:text-indigo-500" aria-hidden="true">&rarr;</span>
                        <span>{{ $route->client?->name ?? __('(client terhapus)') }}</span>
                        @unless ($route->outbound_enabled)
                            <span class="sr-only">{{ __('kirim mati') }}</span>
                            <span aria-hidden="true">&times;</span>
                        @endunless
                    </span>
                @endforeach
            </div>
        @endif
    </td>

    {{-- Status --}}
    <td class="px-4 py-3.5 hidden sm:table-cell">
        <span @class([
            'inline-flex items-center gap-x-1.5 py-1.5 px-3 rounded-full text-xs font-medium ring-1 ring-inset',
            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-400' => $record->isSendable(),
            'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-400' => $record->is_present && ! $record->is_logged_in,
            'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800/50 dark:text-gray-400' => ! $record->is_present,
        ])>
            <span class="size-1.5 rounded-full bg-current opacity-70"></span>
            {{ $record->state_label }}
        </span>
    </td>

    {{-- Terakhir terlihat --}}
    <td class="px-4 py-3.5 hidden md:table-cell">
        <div class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->last_seen_at }}
        </div>
    </td>

    {{-- Actions --}}
    <td class="px-4 py-3.5 whitespace-nowrap w-px">
        <livewire:core.shared-components.item-actions
            :editUrl="route('wara.device.edit', $record->getKey())"
            :navigate="true"
            wire:key="item-actions-{{ $record->getKey() }}" />
    </td>
</tr>
