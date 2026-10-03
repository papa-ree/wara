<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Client Wara') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Siapa yang boleh memakai gateway, dan device mana yang dipakai untuk tiap tujuan layanan.') }}
            </p>
        </div>

        <a href="{{ route('wara.client.create') }}"
            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            {{ __('Client Baru') }}
        </a>
    </div>

    <div>
        <input type="search" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari client...') }}"
            class="block w-full rounded-md border-gray-300 shadow-sm sm:max-w-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
    </div>

    @if ($clients->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Belum ada client.') }}
        </p>
    @endif

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Client') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Peta Purpose ke Device') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ __('Status') }}
                    </th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($clients as $client)
                    <tr wire:key="client-row-{{ $client->getKey() }}"
                        class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3.5">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $client->name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $client->type }}</div>
                        </td>

                        <td class="px-4 py-3.5">
                            @if ($client->routes->isEmpty())
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ __('belum dipetakan') }}
                                </span>
                            @else
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($client->routes as $route)
                                        <span wire:key="route-chip-{{ $route->getKey() }}"
                                            class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            <span class="font-mono">{{ $route->purpose }}</span>
                                            <span class="mx-1 text-gray-400">→</span>
                                            <span class="font-mono">{{ $route->device_id }}</span>
                                            @unless ($route->outbound_enabled)
                                                <span class="ml-1 text-red-500">{{ __('(kirim mati)') }}</span>
                                            @endunless
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-3.5">
                            @if ($client->is_active)
                                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                    {{ __('aktif') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    {{ __('nonaktif') }}
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3.5 whitespace-nowrap text-right">
                            <a href="{{ route('wara.client.edit', $client->getKey()) }}"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                                {{ __('Kelola') }}
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        {{ $clients->links() }}
    </div>
</div>