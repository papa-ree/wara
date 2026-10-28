{{--
    Empat KPI ringkas di atas funnel.

    Kartu digambar langsung di sini (bukan `x-core::stat-card`) supaya mengikuti
    bahasa visual halaman Overview Srikandi: ikon gradien, sudut membulat, dan
    aksen teal-emerald yang sama. `x-core::stat-card` masih dipakai halaman lain
    dan tampil lebih datar; memaksanya di sini akan membuat dua halaman Bale
    terlihat berasal dari aplikasi berbeda.
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    {{-- Pesan keluar --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-md shadow-teal-500/30 dark:shadow-teal-500/20">
                <x-lucide-send class="size-5 text-white" />
            </div>

            <span class="inline-flex shrink-0 items-center rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700 dark:bg-teal-900/30 dark:text-teal-300">
                {{ $rangeLabel }}
            </span>
        </div>

        <div class="mt-4">
            <div class="text-3xl font-bold tracking-tight text-gray-900 tabular-nums dark:text-white">
                {{ number_format($summary['outbound']) }}
            </div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Pesan Keluar') }}
            </div>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ __(':count pesan', ['count' => number_format($summary['outbound'])]) }}
            </p>
        </div>

        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-teal-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{--
        🔴 Persentase ditolak memakai nilai `null` dari summary, bukan angka
        "0%". Kalau tidak ada pesan keluar sama sekali, persentase tidak
        punya makna — nol berarti "tidak ada yang ditolak", bukan "semua
        ditolak".
    --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-rose-600 shadow-md shadow-rose-500/30 dark:shadow-rose-500/20">
                <x-lucide-circle-x class="size-5 text-white" />
            </div>

            <span class="inline-flex shrink-0 items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                {{ $rangeLabel }}
            </span>
        </div>

        <div class="mt-4">
            <div class="text-3xl font-bold tracking-tight text-gray-900 tabular-nums dark:text-white">
                {{ $summary['rejected_percent'] === null ? '—' : $summary['rejected_percent'].'%' }}
            </div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Ditolak Gateway') }}
            </div>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ number_format($summary['rejected']) }} {{ __('dari') }} {{ number_format($summary['outbound']) }}
            </p>
        </div>

        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-rose-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{--
        🔴 Rata-rata latency menampilkan "—" saat `latency_ms` NULL semua.
        Itu berarti "tidak terukur", bukan "0 ms" — lihat catatan
        `WaraAnalytics::latency()`.
    --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/30 dark:shadow-sky-500/20">
                <x-lucide-timer class="size-5 text-white" />
            </div>

            <span class="inline-flex shrink-0 items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-sky-700 dark:bg-sky-900/30 dark:text-sky-300">
                {{ $rangeLabel }}
            </span>
        </div>

        <div class="mt-4">
            <div class="text-3xl font-bold tracking-tight text-gray-900 tabular-nums dark:text-white">
                {{ $summary['latency_average'] === null ? '—' : number_format($summary['latency_average']).' ms' }}
            </div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Rata-rata Kirim') }}
            </div>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ __('Waktu tunggu gateway GOWA') }}
            </p>
        </div>

        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-sky-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>

    {{--
        🔴 Angka ini dijumlahkan dari dua sumber yang berbeda satuan: jumlah
        baris retry yang menyentuh batas, dan jumlah device yang perlu
        pairing ulang. Yang dijumlahkan adalah "hal yang perlu ditindak",
        bukan "jumlah device", dan label di bawahnya membedakannya supaya
        tidak salah dibaca.
    --}}
    <div class="group relative overflow-hidden rounded-xl border border-gray-100 bg-white p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-white/[0.06] dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 shadow-md shadow-amber-500/30 dark:shadow-amber-500/20">
                <x-lucide-alert-triangle class="size-5 text-white" />
            </div>

            <span class="inline-flex shrink-0 items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                {{ $rangeLabel }}
            </span>
        </div>

        <div class="mt-4">
            <div class="text-3xl font-bold tracking-tight text-gray-900 tabular-nums dark:text-white">
                {{ number_format($summary['exhausted'] + $summary['devices_needing_attention']) }}
            </div>
            <div class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Perlu Perhatian') }}
            </div>
            <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                {{ number_format($summary['exhausted']) }} {{ __('sesi habis') }} &middot;
                {{ number_format($summary['devices_needing_attention']) }} {{ __('device perlu pair ulang') }}
            </p>
        </div>

        <div class="pointer-events-none absolute -bottom-4 -right-4 h-20 w-20 rounded-full bg-amber-500/5 transition-all duration-300 group-hover:scale-150"></div>
    </div>
</div>
