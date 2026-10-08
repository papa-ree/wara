<x-core::page-container>
    <div class="flex items-center justify-between">
        <div>
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('Trafik Pesan') }}
            </h4>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Lima tahap per interval waktu: diterima dan ditolak gateway, masuk, terkirim device, dan dibaca.') }}
            </p>
        </div>
        <x-lucide-activity class="size-5 text-gray-400 dark:text-gray-500" />
    </div>

    {{--
        🔴 Chart.js dimuat dari CDN oleh `x-core::chart`. Kalau CDN mati, kartu ini
        jadi kotak kosong tanpa pesan error — itu perilaku komponen yang sudah
        ada dan dipakai bale-cms juga, jadi bukan yang bisa diperbaiki di sini
        tanpa mengubah komponen bersama.
    --}}
    <div class="mt-4">
        <x-core::chart
            type="line"
            :labels="$chartLabels"
            :datasets="$chartDatasets"
            class="h-72" />
    </div>
</x-core::page-container>