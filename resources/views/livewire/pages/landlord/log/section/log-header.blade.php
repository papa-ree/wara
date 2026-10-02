<div>
    <x-core::page-header
        :title="__('Log Pesan')"
        :subtitle="__('Riwayat pesan masuk dan keluar beserta device pengirimnya.')" />

    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
        <span class="inline-flex items-center gap-1.5">
            <x-lucide-lock class="size-3.5" />
            {{ __('Halaman ini hanya untuk dibaca — log tidak dapat dihapus dari sini.') }}
        </span>
    </div>
</div>
