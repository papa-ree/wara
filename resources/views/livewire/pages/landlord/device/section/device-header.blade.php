<div>
    <x-core::page-header
        :title="__('Device WhatsApp')"
        :subtitle="__('Katalog device gateway dan penugasan pengirim per tujuan.')">
        <x-slot name="actions">
            <x-core::button
                type="button"
                label="{{ __('Sinkronkan Sekarang') }}"
                wire:click="syncNow"
                wire:loading.attr="disabled"
                spinner="syncNow">
                <x-slot name="icon">
                    <x-lucide-refresh-cw class="w-4 h-4" />
                </x-slot>
            </x-core::button>
        </x-slot>
    </x-core::page-header>

    {{-- Peringatan §8.3: device yang tidak siap dalam waktu lama --}}
    @if ($this->needsReattention->isNotEmpty())
        <div class="mt-6 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-alert-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <div class="min-w-0">
                    <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        {{ __('Device perlu dipasang ulang') }}
                    </h4>
                    <ul class="mt-2 text-sm text-amber-800 dark:text-amber-300 space-y-1">
                        @foreach ($this->needsReattention as $session)
                            <li>
                                <span class="font-mono">{{ $session->display_name ?? $session->device_id }}</span>
                                — {{ $session->state_label }},
                                {{ __('terakhir terlihat :time', ['time' => $session->last_seen_at?->diffForHumans() ?? __('tidak diketahui')]) }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-400">
                        {{ __('Pasangkan ulang device di dashboard gateway. Jalankan wara:sync-devices setelahnya.') }}
                    </p>
                    <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-400/80">
                        {{ __('Gateway tidak melaporkan alasan disconnect, jadi ini belum berarti akun diblokir.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Device logged-out tapi baru saja terlihat: belum jadi peringatan --}}
    @if ($this->recentlyUnhealthy->isNotEmpty())
        <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-info class="size-5 text-gray-500 dark:text-gray-400 shrink-0 mt-0.5" />
                <div class="min-w-0">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-200">
                        {{ __('Device sedang tidak siap') }}
                    </h4>
                    <ul class="mt-2 text-sm text-gray-700 dark:text-gray-300 space-y-1">
                        @foreach ($this->recentlyUnhealthy as $session)
                            <li>
                                <span class="font-mono">{{ $session->display_name ?? $session->device_id }}</span>
                                — {{ $session->state_label }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                        {{ __('Masih dalam masa tenggang sebelum ditandai sebagai perlu dipasang ulang.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
