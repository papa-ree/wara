<div>
    <x-wara::hero
        :title="__('Device WhatsApp')"
        :subtitle="__('Katalog device gateway, dan client mana saja yang memakainya.')"
        icon="smartphone"
        :badge="__('Gateway')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-radio-tower class="size-3.5" />
                {{ __('Status koneksi real-time') }}
            </span>
        </x-slot>

        <x-slot name="actions">
            {{-- 🔴 Penugasan tidak diubah dari halaman ini. Device hanya
                 menampilkan device yang dipakai client mana pun; memetakan
                 device ke purpose dilakukan di `Wara > Client`. Tanpa catatan
                 ini, admin akan mencari form penugasan yang sudah tidak ada. --}}
            <a href="{{ route('wara.client.index') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-users class="size-4" />
                {{ __('Kelola Client') }}
            </a>

            <button type="button"
                    wire:click="syncNow"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 shadow-sm transition hover:bg-teal-50 disabled:cursor-wait disabled:opacity-70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                <x-lucide-refresh-cw class="size-4" wire:loading.class="animate-spin" wire:target="syncNow" />
                {{ __('Sinkronkan Sekarang') }}
            </button>
        </x-slot>
    </x-wara::hero>

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
