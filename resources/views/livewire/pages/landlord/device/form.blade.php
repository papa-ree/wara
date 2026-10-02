<div>
    <x-core::breadcrumb
        :items="[['label' => __('Device WhatsApp'), 'route' => 'wara.device.index']]"
        :active="__('Tugaskan Device')" />

    <div class="max-w-2xl mx-auto mt-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-xl overflow-hidden">

            {{-- Header --}}
            <div class="p-6 border-b border-gray-100 dark:border-gray-800 bg-linear-to-r from-green-50/50 to-emerald-50/50 dark:from-green-900/10 dark:to-emerald-900/10">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                    {{ __('Tugaskan Purpose') }}
                    <span class="ml-2 font-mono text-sm font-semibold text-gray-500 dark:text-gray-400">
                        {{ $session->device_id }}
                    </span>
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ __('Purpose menentukan device mana yang mengirim pesan untuk suatu tujuan.') }}
                </p>
            </div>

            <form wire:submit="save" class="p-8 space-y-6">

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
                                {{ __('Device ini belum siap mengirim. Penugasan tetap boleh disimpan, tapi pengiriman dari device ini akan gagal sampai pairing diperbaiki.') }}
                            </p>
                        </div>
                    </div>
                @endunless

                {{-- Purpose --}}
                <div>
                    <label for="purpose" class="block text-sm font-bold text-gray-900 dark:text-white mb-1.5">
                        {{ __('Purpose') }}
                    </label>
                    <input wire:model="purpose" type="text" id="purpose"
                        placeholder="otp"
                        class="block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-slate-800 dark:text-gray-200 text-sm font-mono focus:border-green-500 focus:ring-green-500" />
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Kosongkan bila device ini belum ditugaskan. Satu purpose hanya boleh dilayani satu device.') }}
                    </p>
                    @error('purpose')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Footer --}}
                <div class="pt-2 flex items-center justify-between border-t border-gray-100 dark:border-gray-800">
                    <x-core::secondary-button link href="{{ route('wara.device.index') }}"
                        label="{{ __('Kembali') }}" />

                    <div class="flex items-center gap-2">
                        <x-core::button type="button" wire:click="testConnection"
                            wire:loading.attr="disabled" spinner="testConnection"
                            label="{{ __('Tes Koneksi') }}">
                            <x-slot name="icon">
                                <x-lucide-plug-zap class="w-4 h-4" />
                            </x-slot>
                        </x-core::button>

                        <x-core::button type="submit" label="{{ __('Simpan Penugasan') }}" spinner="save">
                            <x-slot name="icon"><x-lucide-check class="w-4 h-4" /></x-slot>
                        </x-core::button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
