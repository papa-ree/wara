{{-- Kartu grafik mengikuti pola kartu Overview Srikandi: header bergaris, ikon gradien, body lega. --}}
<div class="rounded-xl border border-gray-100 bg-white shadow-sm dark:border-white/[0.06] dark:bg-gray-900">
    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                <x-lucide-trending-up class="size-4 text-white" />
            </div>

            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('Trafik Pesan') }}
                </h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Lima tahap per interval waktu: diterima dan ditolak gateway, masuk, terkirim device, dan dibaca.') }}
                </p>
            </div>
        </div>

        <span class="inline-flex shrink-0 items-center self-start rounded-full bg-teal-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 dark:bg-teal-900/30 dark:text-teal-300 sm:self-auto">
            {{ $rangeLabel }}
        </span>
    </div>

    <div class="p-4 sm:p-5">
        @if ($hasFunnelData)
            {{--
                🔴 `wire:key` berubah saat rentang berubah. Tanpa ini, Livewire
                hanya mengganti atribut `x-data`, sedangkan Alpine tidak
                menjalankan ulang `x-init` — chart tetap menggambar data lama
                meskipun label di atasnya sudah berganti. Node baru memaksa
                Chart.js dibuat ulang dari nol.
            --}}
            <div wire:key="wara-funnel-chart-{{ md5($rangeLabel) }}">
                <x-core::chart
                    type="line"
                    :labels="$chartLabels"
                    :datasets="$chartDatasets"
                    :options="$chartOptions" />
            </div>
        @else
            <div class="flex h-80 flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 text-center dark:border-gray-700 dark:bg-gray-900/40">
                <span class="flex size-12 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm dark:bg-gray-800 dark:text-gray-500">
                    <x-lucide-line-chart class="size-6" />
                </span>
                <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Belum ada trafik pada rentang ini.') }}
                </p>
                <p class="mt-1 max-w-sm text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Coba pilih periode yang lebih panjang, atau periksa apakah device sudah terhubung ke gateway.') }}
                </p>
            </div>
        @endif
    </div>
</div>
