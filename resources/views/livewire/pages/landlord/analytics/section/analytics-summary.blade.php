{{--
    Empat KPI ringkas di atas funnel.

    🔴 Memakai `x-core::stat-card`, bukan kartu yang digambar manual di sini.
    Markup kartu yang ditulis ulang per halaman selalu berakhir berbeda satu
    padding atau satu warna, dan perbedaannya tidak pernah ketahuan karena
    tidak ada yang dibandingkan.
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-send class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Pesan Keluar') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($summary['outbound']) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ trans_choice('{1} :count pesan|[2,*] :count pesan', $summary['outbound'], ['count' => $summary['outbound']]) }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    {{--
        🔴 Persentase ditolak memakai nilai `null` dari summary, bukan angka "0%".
        Kalau tidak ada pesan keluar sama sekali, persentase tidak punya
        makna — nol berarti "tidak ada yang ditolak", bukan "semua ditolak".
    --}}
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-circle-x
                @class([
                    'size-6',
                    'text-gray-500 dark:text-gray-400' => $summary['rejected_percent'] === null || $summary['rejected_percent'] < 5,
                    'text-amber-600 dark:text-amber-400' => $summary['rejected_percent'] !== null && $summary['rejected_percent'] >= 5 && $summary['rejected_percent'] < 20,
                    'text-red-600 dark:text-red-400' => $summary['rejected_percent'] !== null && $summary['rejected_percent'] >= 20,
                ]) />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Ditolak Gateway') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div @class([
                'text-2xl font-bold',
                'text-gray-900 dark:text-white' => $summary['rejected_percent'] === null || $summary['rejected_percent'] < 5,
                'text-amber-600 dark:text-amber-400' => $summary['rejected_percent'] !== null && $summary['rejected_percent'] >= 5 && $summary['rejected_percent'] < 20,
                'text-red-600 dark:text-red-400' => $summary['rejected_percent'] !== null && $summary['rejected_percent'] >= 20,
            ])>
                {{ $summary['rejected_percent'] === null ? '—' : $summary['rejected_percent'].'%' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($summary['rejected']) }} {{ __('dari') }} {{ number_format($summary['outbound']) }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    {{--
        🔴 Rata-rata latency menampilkan "—" saat `latency_ms` NULL semua.
        Itu berarti "tidak terukur", bukan "0 ms" — lihat catatan
        `WaraAnalytics::latency()`.
    --}}
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-timer class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Rata-rata Kirim') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ $summary['latency_average'] === null ? '—' : number_format($summary['latency_average']).' ms' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Waktu tunggu gateway GOWA') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>

    {{--
        🔴 Angka ini dijumlahkan dari dua sumber yang berbeda satuan: jumlah baris
        retry yang menyentuh batas, dan jumlah device yang perlu pairing ulang.
        Yang dijumlahkan adalah "hal yang perlu ditindak", bukan "jumlah device",
        dan label di bawahnya membedakannya supaya tidak salah dibaca.
    --}}
    <x-core::stat-card>
        <x-slot:icon>
            <x-lucide-alert-triangle class="size-6 text-gray-500 dark:text-gray-400" />
        </x-slot:icon>

        <x-slot:title>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('Perlu Perhatian') }}
            </span>
        </x-slot:title>

        <x-slot:data>
            <div class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ number_format($summary['exhausted'] + $summary['devices_needing_attention']) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($summary['exhausted']) }} {{ __('sesi habis') }} &middot;
                {{ number_format($summary['devices_needing_attention']) }} {{ __('device perlu pair ulang') }}
            </div>
        </x-slot:data>
    </x-core::stat-card>
</div>