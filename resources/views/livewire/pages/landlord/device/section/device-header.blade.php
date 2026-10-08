<div>
    <x-core::page-header
        :title="__('Device WhatsApp')"
        :subtitle="__('Katalog device gateway, dan client mana saja yang memakainya.')">
        <x-slot name="action">
            {{-- 🔴 Penugasan tidak diubah dari halaman ini. Device hanya
                 menampilkan device yang dipakai client mana pun; memetakan
                 device ke purpose dilakukan di `Wara > Client`. Tanpa catatan
                 ini, admin akan mencari form penugasan yang sudah tidak ada. --}}
            <div class="flex flex-wrap items-center gap-3">
                <x-core::secondary-button
                    link
                    href="{{ route('wara.client.index') }}"
                    label="{{ __('Kelola Client') }}" />

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
            </div>
        </x-slot>
    </x-core::page-header>

    {{-- Peringatan §8.2: sesi login yang habis percobaannya.
         DISISIPKAN DI ATAS peringatan device karena urutan risikonya berbeda.
         "captcha salah → kirim ulang → mungkin lockout" lebih merusak daripada
         device offline: yang offline cuma tidak bisa kirim, yang exhausted bisa
         membuat nomor diblokir permanen. --}}
    @if ($this->recentlyExhaustedSessions->isNotEmpty())
        <div class="mt-6">
            <x-core::alert variant="warning" :title="__('Sesi login habis percobaan')">
                <ul class="space-y-1">
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
                <p class="mt-3 text-xs">
                    {{ __('Percobaan berikutnya untuk sesi ini ditolak sampai jendela :minutes menit lewat. Sesi baru tetap berjalan normal.', ['minutes' => $this->session_window_minutes]) }}
                </p>
                <p class="mt-1 text-xs opacity-80">
                    {{ __('Pola captcha salah berulang adalah pemicu lockout yang paling sering terjadi. Kalau ini sering muncul, siapkan nomor uji yang terpisah dari nomor produksi.') }}
                </p>
            </x-core::alert>
        </div>
    @endif

    {{-- Peringatan §8.3: device yang tidak siap dalam waktu lama --}}
    @if ($this->needsReattention->isNotEmpty())
        <div class="mt-6">
            <x-core::alert variant="warning" :title="__('Device perlu dipasang ulang')">
                <ul class="space-y-1">
                    @foreach ($this->needsReattention as $session)
                        <li>
                            <span class="font-mono">{{ $session->display_name ?? $session->device_id }}</span>
                            — {{ $session->state_label }},
                            {{ __('terakhir terlihat :time', ['time' => $session->last_seen_at?->diffForHumans() ?? __('tidak diketahui')]) }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs">
                    {{ __('Pasangkan ulang device di dashboard gateway. Jalankan wara:sync-devices setelahnya.') }}
                </p>
                <p class="mt-1 text-xs opacity-80">
                    {{ __('Gateway tidak melaporkan alasan disconnect, jadi ini belum berarti akun diblokir.') }}
                </p>
            </x-core::alert>
        </div>
    @endif

    {{-- Device logged-out tapi baru saja terlihat: belum jadi peringatan --}}
    @if ($this->recentlyUnhealthy->isNotEmpty())
        <div class="mt-4">
            <x-core::alert variant="info" :title="__('Device sedang tidak siap')">
                <ul class="space-y-1">
                    @foreach ($this->recentlyUnhealthy as $session)
                        <li>
                            <span class="font-mono">{{ $session->display_name ?? $session->device_id }}</span>
                            — {{ $session->state_label }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">
                    {{ __('Masih dalam masa tenggang sebelum ditandai sebagai perlu dipasang ulang.') }}
                </p>
            </x-core::alert>
        </div>
    @endif
</div>
