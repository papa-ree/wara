<div>
    <x-core::page-header
        :title="__('Analitik Wara')"
        :subtitle="__('Kesehatan gateway dan router pesan dalam rentang waktu terpilih.')" />

    {{--
        🔴 Rentang ditampilkan sebagai tombol, bukan <select>. Alasannya
        kelihatan: hanya 5 pilihan, semua berupa label yang perlu dibaca
        ("6 jam", "7 hari"), dan <select> menyembunyikan sisa pilihan sampai
        diklik. Tanpa tombol "Terapkan" — filter sekecil ini tidak layak punya
        langkah konfirmasi.

        🔴 Tombolnya `$dispatch`, BUKAN `$set('range', ...)`. `$set` hanya
        mengubah `$range` milik komponen ini, sedangkan `$range` yang dipakai
        summary/funnel/breakdown milik `Index`. Kalau pakai `$set`, header-nya
        berubah tapi ketiga grafik tetap menampilkan rentang lama, dan tidak ada
        error apa pun — persis kelas bug yang paling mahal.
    --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
            {{ __('Rentang') }}
        </span>

        @foreach($ranges as $option)
            <button
                type="button"
                wire:click="$dispatch('analytics-range-changed', { range: {{ $option }} })"
                @class([
                    'px-2.5 py-1 text-xs font-medium rounded-md border transition-colors duration-150',
                    'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300' => (int) $range === $option,
                    'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700/50' => (int) $range !== $option,
                ])>
                {{ $option >= 24
                    ? trans_choice('{1} :hari|[2,*] :hari', (int) ($option / 24), ['hari' => (int) ($option / 24)])
                    : __(':jam jam', ['jam' => $option]) }}
            </button>
        @endforeach
    </div>

    {{--
        🔴 Batasan atribusi ditampilkan sebagai catatan, bukan disembunyikan di
        tooltip. Angka "keluar per client" tanpa catatan ini akan dibaca sebagai
        "client ini tidak menerima pesan" — padahal isinya nol untuk alasan yang
        sama sekali berbeda: pesan masuk memang tidak pernah punya client_id
        (PRD §14.3).
    --}}
    <div class="mt-3 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
        <x-lucide-info class="size-4 shrink-0 mt-px" />
        <span>
            {{ __('Atribusi per client hanya untuk pesan KELUAR. Pesan masuk dicatat per device, karena satu device bisa melayani beberapa client.') }}
        </span>
    </div>
</div>