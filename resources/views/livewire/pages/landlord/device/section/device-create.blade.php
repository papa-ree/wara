{{-- Tambah slot device (PRD §15.2). Slot dibuat kosong; pairing QR/kode
     dilakukan di halaman detail device karena di sana statusnya terlihat. --}}
<div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
    <form wire:submit="save">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                    <x-lucide-plus class="size-4 text-white" />
                </div>

                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Tambah Slot Device') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Slot dibuat kosong, lalu dipasangkan lewat QR atau kode pairing.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-end sm:p-5">
            <div class="min-w-0 grow">
                <x-core::label for="wara-device-new-id" :value="__('Nama Slot')" />
                <x-core::input
                    id="wara-device-new-id"
                    type="text"
                    wire:model="deviceId"
                    placeholder="{{ __('biarkan kosong untuk nama otomatis') }}"
                    autocomplete="off"
                    class="focus:!border-teal-500 focus:!ring-teal-500" />
                <x-core::input-error for="deviceId" class="mt-1" />
            </div>

            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-teal-500/25 transition hover:bg-teal-700 disabled:opacity-50">
                <x-lucide-plus class="size-4" wire:loading.class="animate-pulse" wire:target="save" />
                {{ __('Tambah Slot') }}
            </button>
        </div>
    </form>
</div>
