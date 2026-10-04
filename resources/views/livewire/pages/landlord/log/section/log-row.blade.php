@php
    use Bale\Wara\Models\WaraLog;
@endphp

<tr wire:key="wara-log-row-{{ $record->getKey() }}"
    class="hover:bg-gray-50/80 dark:hover:bg-gray-800/50 transition-colors duration-150">

    {{-- Isi pesan --}}
    <td class="px-4 py-3.5 w-full max-w-0 sm:max-w-none sm:w-auto">
        <div class="flex items-center gap-3">
            <div @class([
                'size-9 rounded-lg flex items-center justify-center shrink-0 border',
                'bg-indigo-50 border-indigo-200/50 dark:bg-indigo-900/20 dark:border-indigo-700/30' => $record->direction === 'out',
                'bg-sky-50 border-sky-200/50 dark:bg-sky-900/20 dark:border-sky-700/30' => $record->direction === 'in',
            ])>
                <x-lucide-message-square @class([
                    'size-4',
                    'text-indigo-600 dark:text-indigo-400' => $record->direction === 'out',
                    'text-sky-600 dark:text-sky-400' => $record->direction === 'in',
                ]) />
            </div>
            <div class="min-w-0 flex-1">
                {{--
                    Isi pesan. `masked_body` menyamarkan kode OTP; nilai
                    aslinya tetap utuh di database.

                    PENTING: jangan taruh Blade comment DI DALAM elemen yang
                    memakai `whitespace-pre-line`. Compiler menghapus blok
                    komentar tapi baris baru di sekitarnya tetap dibiarkan, dan
                    `pre-line` menghitungnya sebagai line break sungguhan.
                    Teks lalu terdorong ke baris ke-3 dan terpotong oleh
                    `line-clamp-2`, sehingga kolom isi pesan hanya menampilkan
                    "..." padahal isinya ada.

                    Catatan tambahan: jangan juga menulis penanda komentar Blade
                    di dalam komentar ini — kompilasi akan menutup lebih awal di
                    penanda pertama dan sisanya bocor jadi teks.
                --}}
                <p class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-line line-clamp-2">{{ $record->masked_body ?? __('(tanpa isi)') }}</p>
                @if ($record->message_id)
                    <p class="text-xs font-mono text-gray-500 dark:text-gray-400 truncate mt-0.5">
                        {{ $record->message_id }}
                    </p>
                @endif
            </div>
        </div>
    </td>

    {{-- Device pengirim --}}
    <td class="px-4 py-3.5 hidden lg:table-cell">
        @if ($record->device_id)
            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate max-w-xs">
                {{ $record->device_id }}
            </div>
            <p class="text-xs font-mono text-gray-500 dark:text-gray-400 truncate mt-0.5">
                {{--
                    `phone` didahulukan, bukan `chat_id`.

                    `phone` sudah dinormalisasi (tanpa sufiks JID) dan disimpan
                    terenkripsi; `chat_id` sengaja plaintext karena dipakai
                    sebagai kunci routing (PRD §7.1b). Kalau `chat_id` yang
                    didahulukan, nomor tujuan tampil polos di layar — persis
                    yang sedang dicegah oleh enkripsi `phone` — dan baris masuk
                    jadi tidak konsisten dengan baris keluar.

                    `chat_id` hanya dipakai kalau `phone` kosong, mis. event
                    tanpa pengirim yang jelas; pada kasus itu informasinya
                    tetap lebih baik daripada sel kosong.
                --}}
                {{ $record->phone ?? $record->chat_id ?? '—' }}
            </p>
        @else
            <span class="text-sm text-gray-400 dark:text-gray-500">{{ __('Tanpa device') }}</span>
        @endif
    </td>

    {{-- Arah --}}
    <td class="px-4 py-3.5 hidden sm:table-cell">
        <span @class([
            'inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium',
            'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400' => $record->direction === 'out',
            'bg-sky-50 text-sky-700 dark:bg-sky-900/20 dark:text-sky-400' => $record->direction === 'in',
        ])>
            {{ $record->direction === 'out' ? __('Keluar') : __('Masuk') }}
        </span>
    </td>

    {{-- Status --}}
    <td class="px-4 py-3.5 hidden md:table-cell">
        <span @class([
            'inline-flex items-center gap-x-1.5 py-1.5 px-3 rounded-full text-xs font-medium ring-1 ring-inset',
            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-400' => in_array($record->status_color, ['green', 'blue', 'indigo'], true),
            'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400' => $record->status_color === 'red',
            'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800/50 dark:text-gray-400' => $record->status_color === 'gray',
        ])>
            <span class="size-1.5 rounded-full bg-current opacity-70"></span>
            {{ $record->status_label }}
        </span>
    </td>

    {{-- Percobaan sesi --}}
    <td class="px-4 py-3.5 hidden xl:table-cell">
        @if ($record->session_state === WaraLog::SESSION_NONE)
            {{--
                🔴 Tanda hubung tanpa penjelasan pernah dibaca sebagai
                "kolom ini belum diisi" atau "tidak berlaku". Padahal `—` di
                sini berarti CELAH: pemanggil tidak mengirim `session_key`, jadi
                batas percobaan §8.2 tidak pernah dihitung untuk baris ini.
                Putusan sadar 4 Okt 2026, bukan kelalaian.
            --}}
            <span class="text-sm text-gray-400 dark:text-gray-500 cursor-help"
                title="{{ __('Tidak ada session_key: batas percobaan sesi §8.2 tidak dihitung untuk baris ini. Celah yang disengaja.') }}">{{ __('—') }}</span>
        @else
            <span @class([
                'inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium font-mono',
                'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400 border border-amber-100 dark:border-amber-800/40' => $record->session_state === WaraLog::SESSION_LAST,
                'bg-gray-50 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400 border border-gray-100 dark:border-gray-700/60' => $record->session_state !== WaraLog::SESSION_LAST,
            ])>
                {{ $record->session_attempt_label }}
            </span>
{{--
    🔴 `session_key` ditampilkan utuh (di-truncate, bukan disamarkan)
    justru karena inilah yang dicari admin. Ini bukan kredensial: nilainya
    identifier jendela/percobaan yang dibuat scraper, bukan rahasia. Disamarkan
    justru membuat filter di tabel tidak bisa dipakai, karena yang diketik admin
    tidak akan cocok dengan yang tersimpan.
--}}
<p class="text-xs font-mono text-gray-400 dark:text-gray-500 truncate mt-0.5 max-w-[12rem]"
    title="{{ $record->session_key }}">
    {{ $record->session_key }}
</p>
        @endif
    </td>

    {{-- Waktu --}}
    <td class="px-4 py-3.5 whitespace-nowrap w-px">
        <div class="text-sm text-gray-700 dark:text-gray-300">
            {{ $record->created_at }}
        </div>
    </td>
</tr>
