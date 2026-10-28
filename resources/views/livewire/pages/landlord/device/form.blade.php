<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Detail Device')" />

    <div class="max-w-3xl mx-auto mt-6">
        <x-wara::hero
            :title="__('Detail Device')"
            :subtitle="__('Status koneksi, dan client mana saja yang memakai device ini.')"
            icon="smartphone"
            :badge="__('Device')">
            <x-slot name="meta">
                <span class="inline-flex items-center gap-1.5 font-mono">
                    <x-lucide-fingerprint class="size-3.5" />
                    {{ $session->device_id }}
                </span>
            </x-slot>
        </x-wara::hero>

        <div class="mt-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
            <div class="space-y-6 p-5 sm:p-6">

                {{-- Info device (read-only) --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-gray-100 bg-gray-50/40 dark:border-white/[0.06] dark:bg-gray-900/40 p-4">
                        <x-core::label :value="__('Nama Device')" class="text-xs font-semibold uppercase tracking-wider mb-1" />
                        <p class="text-sm text-gray-900 dark:text-white">
                            {{ $session->display_name ?? __('(tanpa nama)') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-gray-50/40 dark:border-white/[0.06] dark:bg-gray-900/40 p-4">
                        <x-core::label :value="__('JID')" class="text-xs font-semibold uppercase tracking-wider mb-1" />
                        <p class="text-sm font-mono text-gray-900 dark:text-white">
                            {{ $session->jid ?? __('Belum pairing') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-gray-50/40 dark:border-white/[0.06] dark:bg-gray-900/40 p-4">
                        <x-core::label :value="__('Status')" class="text-xs font-semibold uppercase tracking-wider mb-1" />
                        <p class="text-sm text-gray-900 dark:text-white">{{ $session->state_label }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-100 bg-gray-50/40 dark:border-white/[0.06] dark:bg-gray-900/40 p-4">
                        <x-core::label :value="__('Siap Mengirim')" class="text-xs font-semibold uppercase tracking-wider mb-1" />
                        <p class="text-sm text-gray-900 dark:text-white">
                            {{ $session->isSendable() ? __('Ya') : __('Tidak') }}
                        </p>
                    </div>
                </div>

                {{-- Warning kalau device belum siap --}}
                @unless ($session->isSendable())
                    <x-core::alert variant="warning">
                        {{ __('Device ini belum siap mengirim. Route yang menunjuk ke device ini akan gagal sampai pairing diperbaiki.') }}
                    </x-core::alert>
                @endunless

                {{-- 🔴 INI BUKAN INPUT. Penugasan device -> purpose sudah pindah
                     ke halaman `Wara > Client` dan form ini tidak lagi punya
                     `purpose` maupun `save()`.

                     Versi lama halaman ini masih menaruh `<input
                     wire:model="purpose">` dan tombol "Simpan Penugasan".
                     Selain kolomnya sudah dihapus, mislead: admin akan
                     mengira menugaskan device dari sini, padahal yang
                     tersimpan route milik client LAIN.
                --}}
                <div>
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-1.5">
                        {{ __('Dipakai Client') }}
                    </h4>

                    @if ($routes === [])
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Belum dipakai client mana pun.') }}
                        </p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($routes as $route)
                                <li wire:key="device-detail-route-{{ $loop->index }}"
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-100 bg-gray-50/40 dark:border-white/[0.06] dark:bg-gray-900/40 px-4 py-2.5">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <x-core::badge color="teal" :label="$route['purpose']" />
                                        <span aria-hidden="true" class="text-gray-400">&rarr;</span>
                                        <span class="text-sm text-gray-900 dark:text-white truncate">
                                            {{ $route['client'] }}
                                        </span>
                                    </div>

                                    @unless ($route['outbound'])
                                        <x-core::badge color="red" :label="__('kirim mati')" />
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('wara.client.index') }}" wire:navigate.hover
                        class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-teal-600 hover:text-teal-800 dark:text-teal-400 dark:hover:text-teal-300">
                        {{ __('Kelola penugasan di Wara > Client') }}
                        <x-lucide-arrow-right class="size-3.5" />
                    </a>
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-between border-t border-gray-100 pt-4 dark:border-white/[0.06]">
                    <a href="{{ route('wara.device.index') }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                        <x-lucide-arrow-left class="size-4" />
                        {{ __('Kembali') }}
                    </a>

                    <button type="button"
                            wire:click="testConnection"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-teal-500/25 transition hover:bg-teal-700 disabled:opacity-50">
                        <x-lucide-plug-zap class="size-4" wire:loading.class="animate-pulse" wire:target="testConnection" />
                        {{ __('Tes Koneksi') }}
                    </button>
                </div>
            </div>
        </div>

        @can('wara.device.manage')
            {{-- Pairing & aksi slot (PRD §15.2/§15.4). Semua tombol memanggil
                 gateway lewat method Livewire, bukan route POST baru: halaman
                 ini sudah hidup di dalam satu komponen. --}}
            <div class="mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                            <x-lucide-qr-code class="size-4 text-white" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ __('Pairing & Aksi Device') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Pasangkan perangkat, hubungkan ulang, atau lepas slot ini.') }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-5 p-4 sm:p-5">
                    @if ($qrImage !== null || $pairingCode !== null)
                        {{-- Poll hanya hidup selama QR/kode tampil; begitu device
                             `logged_in`, method ini menutup panelnya sendiri. --}}
                        <div wire:poll.5s="refreshLoginStatus"
                             class="rounded-xl border border-teal-100 bg-teal-50/50 p-4 dark:border-teal-800/40 dark:bg-teal-900/10">
                            @if ($qrImage !== null)
                                <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                                    <img src="{{ $qrImage }}"
                                         alt="{{ __('QR pairing') }}"
                                         class="size-48 shrink-0 rounded-xl border border-teal-100 bg-white p-2 dark:border-teal-800/40">

                                    <div class="min-w-0 text-center sm:text-left"
                                         x-data="{ left: {{ (int) $qrExpiresIn }} }"
                                         x-init="if (left > 0) { const t = setInterval(() => { if (left <= 0) { clearInterval(t); return } left-- }, 1000) }">
                                        <p class="text-sm font-semibold text-teal-900 dark:text-teal-200">
                                            {{ __('Pindai QR ini dari WhatsApp') }}
                                        </p>
                                        <p class="mt-1 text-xs text-teal-800/80 dark:text-teal-300/80">
                                            {{ __('WhatsApp > Perangkat tertaut > Tautkan perangkat.') }}
                                        </p>
                                        <p class="mt-2 text-xs font-medium text-teal-800/80 dark:text-teal-300/80">
                                            <span x-show="left > 0">{{ __('Kedaluwarsa dalam') }}
                                                <span class="font-bold tabular-nums" x-text="left"></span>
                                                {{ __('detik.') }}</span>
                                            <span x-show="left <= 0" x-cloak>{{ __('QR kedaluwarsa — minta QR baru.') }}</span>
                                        </p>
                                    </div>
                                </div>
                            @endif

                            @if ($pairingCode !== null)
                                <div class="text-center sm:text-left">
                                    <p class="text-xs text-teal-800/80 dark:text-teal-300/80">
                                        {{ __('Masukkan kode ini di WhatsApp > Perangkat tertaut > Tautkan dengan nomor telepon.') }}
                                    </p>
                                    <p class="mt-2 font-mono text-2xl font-bold tracking-[0.3em] text-teal-900 dark:text-teal-200">
                                        {{ $pairingCode }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-core::label for="wara-login-phone" :value="__('Nomor Perangkat')" />
                            <x-core::input
                                id="wara-login-phone"
                                type="tel"
                                wire:model="loginPhone"
                                placeholder="62812xxxxxxx"
                                autocomplete="off"
                                class="focus:!border-teal-500 focus:!ring-teal-500" />
                            <x-core::input-error for="loginPhone" class="mt-1" />
                        </div>

                        <div class="flex items-end gap-2">
                            <button type="button"
                                    wire:click="loginQr"
                                    wire:loading.attr="disabled"
                                    wire:target="loginQr"
                                    class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-white px-4 py-2.5 text-sm font-semibold text-teal-700 shadow-sm transition hover:bg-teal-50 disabled:opacity-50 dark:border-teal-800/40 dark:bg-gray-900 dark:text-teal-300 dark:hover:bg-teal-900/20">
                                <x-lucide-qr-code class="size-4" />
                                {{ __('Login QR') }}
                            </button>

                            <button type="button"
                                    wire:click="loginWithCode"
                                    wire:loading.attr="disabled"
                                    wire:target="loginWithCode"
                                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-teal-500/25 transition hover:bg-teal-700 disabled:opacity-50">
                                <x-lucide-key-round class="size-4" />
                                {{ __('Minta Kode') }}
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-white/[0.06]">
                        <button type="button"
                                wire:click="reconnect"
                                wire:loading.attr="disabled"
                                wire:target="reconnect"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                            <x-lucide-rotate-cw class="size-4" />
                            {{ __('Reconnect') }}
                        </button>

                        <button type="button"
                                wire:click="logout"
                                wire:confirm="{{ __('Logout device ini? Slot tetap ada dan bisa dipasangkan ulang.') }}"
                                wire:loading.attr="disabled"
                                wire:target="logout"
                                class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-white px-3.5 py-2 text-sm font-semibold text-amber-700 shadow-sm transition hover:bg-amber-50 disabled:opacity-50 dark:border-amber-800/40 dark:bg-gray-900 dark:text-amber-300 dark:hover:bg-amber-900/20">
                            <x-lucide-log-out class="size-4" />
                            {{ __('Logout') }}
                        </button>

                        {{-- 🔴 Guardrail §15.7 juga ditegakkan di server; tombol ini
                             hanya menjelaskan kenapa aksinya bisa ditolak. --}}
                        <button type="button"
                                wire:click="removeDevice"
                                wire:confirm="{{ __('Hapus slot ini dari gateway? Tindakan ini tidak bisa dibatalkan.') }}"
                                wire:loading.attr="disabled"
                                wire:target="removeDevice"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3.5 py-2 text-sm font-semibold text-red-700 shadow-sm transition hover:bg-red-50 disabled:opacity-50 dark:border-red-800/40 dark:bg-gray-900 dark:text-red-300 dark:hover:bg-red-900/20">
                            <x-lucide-trash-2 class="size-4" />
                            {{ __('Hapus Slot') }}
                        </button>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Slot yang masih dipakai client tidak bisa dihapus.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Webhook per-device (PRD §15.6). Nilainya hidup di gateway,
                 bukan di database Bale - karena itu fase ini nol migration. --}}
            <div class="mt-6 rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/25 dark:shadow-sky-500/20">
                            <x-lucide-webhook class="size-4 text-white" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ __('Webhook Device') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Menimpa webhook global saat URL diisi.') }}
                            </p>
                        </div>
                    </div>

                    <button type="button"
                            wire:click="loadWebhook"
                            wire:loading.attr="disabled"
                            wire:target="loadWebhook"
                            class="inline-flex shrink-0 items-center self-start gap-2 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800 sm:self-auto">
                        <x-lucide-download class="size-3.5" />
                        {{ __('Muat dari Gateway') }}
                    </button>
                </div>

                <div class="space-y-4 p-4 sm:p-5">
                    @unless ($webhookLoaded)
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Nilai webhook dibaca dari gateway saat Anda menekan "Muat dari Gateway".') }}
                        </p>
                    @endunless

                    <div>
                        <x-core::label for="wara-webhook-url" :value="__('URL Webhook')" />
                        <x-core::input
                            id="wara-webhook-url"
                            type="url"
                            wire:model="webhookUrl"
                            placeholder="{{ url('/api/wara/webhook') }}"
                            autocomplete="off"
                            class="focus:!border-teal-500 focus:!ring-teal-500" />
                        <x-core::input-error for="webhookUrl" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-core::label for="wara-webhook-secret" :value="__('Secret')" />
                            <x-core::input
                                id="wara-webhook-secret"
                                type="password"
                                wire:model="webhookSecret"
                                placeholder="{{ $webhookHasSecret ? __('sudah terpasang — isi untuk mengganti') : __('belum ada secret') }}"
                                autocomplete="new-password"
                                class="focus:!border-teal-500 focus:!ring-teal-500" />
                            <x-core::input-error for="webhookSecret" class="mt-1" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Kosongkan untuk mempertahankan secret yang sudah ada.') }}
                            </p>
                        </div>

                        <div>
                            <x-core::label for="wara-webhook-events" :value="__('Event')" />
                            <x-core::input
                                id="wara-webhook-events"
                                type="text"
                                wire:model="webhookEvents"
                                placeholder="message,message.ack"
                                autocomplete="off"
                                class="focus:!border-teal-500 focus:!ring-teal-500" />
                            <x-core::input-error for="webhookEvents" class="mt-1" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Pisahkan dengan koma. Kosongkan untuk semua event.') }}
                            </p>
                        </div>
                    </div>

                    <label for="wara-webhook-insecure" class="flex items-start gap-3">
                        <x-core::checkbox id="wara-webhook-insecure" wire:model="webhookInsecureSkipVerify" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            {{ __('Lewati verifikasi sertifikat TLS') }}
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Hanya untuk endpoint penerima dengan sertifikat self-signed.') }}
                            </span>
                        </span>
                    </label>

                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-white/[0.06]">
                        <button type="button"
                                wire:click="saveWebhook"
                                wire:loading.attr="disabled"
                                wire:target="saveWebhook"
                                class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-teal-500/25 transition hover:bg-teal-700 disabled:opacity-50">
                            <x-lucide-save class="size-4" />
                            {{ __('Simpan Webhook') }}
                        </button>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Mengosongkan URL mengembalikan device ke webhook global.') }}
                        </p>
                    </div>

                    <x-core::alert variant="warning" :title="__('Fan-out bisa mati')">
                        {{ __('Bila URL diarahkan menjauh dari endpoint Bale, event device ini tidak lagi lewat fan-out bale/wara dan tidak tercatat di Wara > Log.') }}
                    </x-core::alert>
                </div>
            </div>
        @endcan
    </div>
</div>
