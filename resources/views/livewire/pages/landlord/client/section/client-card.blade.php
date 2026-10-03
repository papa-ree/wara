{{-- Card untuk layar sempit. Data-table menyajikannya di bawah md, di atas
     tabel desktop yang disembunyikan. --}}
<div wire:key="wara-client-card-{{ $record->getKey() }}"
    class="flex items-start justify-between gap-3 px-4 py-3.5">

    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <span class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">
                {{ $record->name }}
            </span>

            @php $issues = $record->blockingReadinessIssues(); @endphp

            @if ($issues !== [])
                <span class="inline-flex flex-none items-center px-1.5 py-0.5 rounded text-[10px] font-medium
                    bg-amber-50 text-amber-700 border border-amber-100 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800/40"
                    title="{{ $issues[0]['message'] }}">
                    {{ __('perlu perhatian') }}
                </span>
            @endif
        </div>

        <div class="mt-0.5 text-xs font-mono text-gray-500 dark:text-gray-400">
            {{ $record->type }}
        </div>

        <div class="mt-2 flex flex-wrap gap-1.5">
            @forelse ($record->routes->sortBy('purpose') as $route)
                <span wire:key="wara-client-card-route-{{ $route->getKey() }}"
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
            @empty
                <span class="text-xs text-gray-400 dark:text-gray-500">
                    {{ __('belum dipetakan') }}
                </span>
            @endforelse
        </div>
    </div>

    <div class="flex-none">
        <livewire:core.shared-components.item-actions
            :itemId="$record->getKey()"
            editRoute="wara.client.edit"
            :navigate="false"
            confirmMessage="{{ __('Client, route, dan endpoint webhook-nya ikut terhapus.') }}"
            wire:key="item-actions-card-{{ $record->getKey() }}" />
    </div>
</div>
