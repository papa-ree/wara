<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Detail Device')" />

    <div class="max-w-3xl mx-auto mt-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-xl overflow-hidden">

            {{-- Header --}}
            <div class="p-6 border-b border-gray-100 dark:border-gray-800 bg-linear-to-r from-green-50/50 to-emerald-50/50 dark:from-green-900/10 dark:to-emerald-900/10">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                    {{ __('Detail Device') }}
                    <span class="ml-2 font-mono text-sm font-semibold text-gray-500 dark:text-gray-400">
                        {{ $session->device_id }}
                    </span>
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ __('Status koneksi, dan client mana saja yang memakai device ini.') }}
                </p>
            </div>

            <div class="p-8 space-y-6">

                {{-- Info device (read-only) --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-slate-100 dark:border-slate-800 p-4">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                            {{ __('Nama Device') }}
                        </label>
                        <p class="text-sm text-gray-900 dark:text-white">
                            {{ $session->display_name ?? __('(tanpa nama)') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-100 dark:border-slate-800 p-4">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                            {{ __('JID') }}
                        </label>
                        <p class="text-sm font-mono text-gray-900 dark:text-white">
                            {{ $session->jid ?? __('Belum pairing') }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-100 dark:border-slate-800 p-4">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                            {{ __('Status') }}
                        </label>
                        <p class="text-sm text-gray-900 dark:text-white">{{ $session->state_label }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-100 dark:border-slate-800 p-4">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">
                            {{ __('Siap Mengirim') }}
                        </label>
                        <p class="text-sm text-gray-900 dark:text-white">
                            {{ $session->isSendable() ? __('Ya') : __('Tidak') }}
                        </p>
                    </div>
                </div>

                {{-- Warning kalau device belum siap --}}
                @unless ($session->isSendable())
                    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                        <div class="flex items-start gap-3">
                            <x-lucide-alert-triangle class="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                            <p class="text-sm text-amber-800 dark:text-amber-300">
                                {{ __('Device ini belum siap mengirim. Route yang menunjuk ke device ini akan gagal sampai pairing diperbaiki.') }}
                            </p>
                        </div>
                    </div>
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
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 dark:border-slate-800 px-4 py-2.5">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium font-mono bg-indigo-50 text-indigo-700 border border-indigo-100 dark:bg-indigo-900/20 dark:text-indigo-400 dark:border-indigo-800/40">
                                            {{ $route['purpose'] }}
                                        </span>
                                        <span aria-hidden="true" class="text-gray-400">&rarr;</span>
                                        <span class="text-sm text-gray-900 dark:text-white truncate">
                                            {{ $route['client'] }}
                                        </span>
                                    </div>

                                    @unless ($route['outbound'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-red-50 text-red-700 border border-red-100 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800/40">
                                            {{ __('kirim mati') }}
                                        </span>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('wara.client.index') }}"
                        class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                        {{ __('Kelola penugasan di Wara > Client') }}
                        <x-lucide-arrow-right class="size-3.5" />
                    </a>
                </div>

                {{-- Footer --}}
                <div class="pt-2 flex items-center justify-between border-t border-gray-100 dark:border-gray-800">
                    <x-core::secondary-button link href="{{ route('wara.device.index') }}"
                        label="{{ __('Kembali') }}" />

                    <x-core::button type="button" wire:click="testConnection"
                        wire:loading.attr="disabled" spinner="testConnection"
                        label="{{ __('Tes Koneksi') }}">
                        <x-slot name="icon">
                            <x-lucide-plug-zap class="w-4 h-4" />
                        </x-slot>
                    </x-core::button>
                </div>
            </div>
        </div>
    </div>
</div>