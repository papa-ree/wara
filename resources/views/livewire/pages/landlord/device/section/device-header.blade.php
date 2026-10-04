<div>
    <x-core::page-header
        :title="__('Device WhatsApp')"
        :subtitle="__('Katalog device gateway, dan client mana saja yang memakainya.')">
        <x-slot name="actions">
            {{-- 🔴 Penugasan tidak diubah dari halaman ini. Device hanya
                 menampilkan device yang dipakai client mana pun; memetakan
                 device ke purpose dilakukan di `Wara > Client`. Tanpa catatan
                 ini, admin akan mencari form penugasan yang sudah tidak ada. --}}
            <a href="{{ route('wara.client.index') }}"
                class="inline-flex items-center gap-x-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                <x-lucide-users class="w-4 h-4" />
                {{ __('Kelola Client') }}
            </a>

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

    {{-- Peringatan §8.2: sesi login yang habis percobaannya.
         DISISIPKAN DI ATAS peringatan device karena urutan risikonya berbeda.
         "captcha salah → kirim ulang → mungkin lockout" lebih merusak daripada
         device offline: yang offline cuma tidak bisa kirim, yang exhausted bisa
         membuat nomor diblokir permanen. --}}
    @if ($this->recentlyExhaustedSessions->isNotEmpty())
        <div class="mt-6 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
            <div class="flex items-start gap-3">
                <x-lucide-alert-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <div class="min-w-0">
                    <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        {{ __('Sesi login habis percobaan') }}
                    </h4>
                    <ul class="mt-2 text-sm text-amber-800 dark:text-amber-300 space-y-1">
                        @foreach ($this->recentlyExhaustedSessions as $log)
                            <li>
                                <span class="font-mono">{{ $log->session_key }}</span>
                                — {{ __('percobaan terakhir yang diizinkan: :label', ['label' => $log->session_attempt_label]) }}
                                @if ($log->device_id)
                                    <span class="text-xs">
                                        ({{ __('device :device', ['device' => $log->device_id]) }})
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-400">
                        {{ __('Percobaan berikutnya untuk sesi ini ditolak sampai jendela :minutes menit lewat. Sesi baru tetap berjalan normal.', ['minutes' => $this->session_window_minutes]) }}
                    </p>
                    <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-400/80">
                        {{ __('Pola captcha salah berulang adalah pemicu lockout yang paling sering terjadi. Kalau ini sering muncul, siapkan nomor uji yang terpisah dari nomor produksi.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

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
