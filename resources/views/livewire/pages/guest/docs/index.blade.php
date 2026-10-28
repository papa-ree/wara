<div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
    <x-wara::hero
        :title="__('Dokumentasi API')"
        :subtitle="__('Gunakan API Wara untuk mengirim pesan dari sistem Anda. Semua request dan respons memakai JSON.')"
        icon="book-open"
        :badge="__('API')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-key-round class="size-3.5" />
                {{ __('Autentikasi Bearer Token') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-file-json class="size-3.5" />
                {{ __('JSON request & response') }}
            </span>
        </x-slot>
    </x-wara::hero>

    {{-- Base URL --}}
    <x-wara::card
        :title="__('Base URL')"
        :subtitle="__('Semua endpoint di bawah ini memakai prefix yang sama.')"
        icon="link"
        accent="teal">
        <code class="block overflow-x-auto rounded-lg bg-gray-900 px-3 py-2 font-mono text-xs text-gray-200 dark:bg-gray-950">{{ url('/api/v1/wara') }}</code>

        <div class="mt-4 rounded-lg border border-teal-100 bg-teal-50/70 px-3 py-2 dark:border-teal-800/30 dark:bg-teal-900/15">
            <p class="text-xs text-teal-800 dark:text-teal-200">
                {{ __('Sertakan header ini di setiap request:') }}
            </p>
            <code class="mt-1 block overflow-x-auto font-mono text-xs text-teal-900 dark:text-teal-100">Authorization: Bearer TOKEN_API_ANDA</code>
        </div>
    </x-wara::card>

    {{-- Kirim Pesan --}}
    <x-wara::card
        :title="__('Kirim Pesan')"
        :subtitle="__('Kirim pesan teks atau pesan berbasis template.')"
        icon="send"
        accent="emerald">
        <x-slot name="header">
            <span class="inline-flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">POST</span>
                <span class="font-mono text-xs text-gray-600 dark:text-gray-400">/send</span>
            </span>
        </x-slot>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Payload Pesan Teks') }}</h3>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">{
  "to": "628123456789",
  "message": "Halo, ini pesan dari sistem kami.",
  "purpose": "notifikasi"
}</pre>
            </div>
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Payload Template') }}</h3>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">{
  "to": "628123456789",
  "template": "notifikasi_tagihan",
  "parameters": ["Budi Santoso", "INV-2026-001", "Rp150.000"],
  "header_media_url": "https://cdn.example.com/invoice.png",
  "purpose": "notifikasi"
}</pre>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4 font-semibold">{{ __('Field') }}</th>
                        <th class="py-2 pr-4 font-semibold">{{ __('Wajib') }}</th>
                        <th class="py-2 font-semibold">{{ __('Keterangan') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-white/[0.06] dark:text-gray-300">
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">to</td>
                        <td class="py-2 pr-4">{{ __('Ya') }}</td>
                        <td class="py-2">{{ __('Nomor tujuan dengan kode negara, tanpa tanda plus.') }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">message</td>
                        <td class="py-2 pr-4">{{ __('Salah satu') }}</td>
                        <td class="py-2">{{ __('Isi pesan teks bebas.') }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">template</td>
                        <td class="py-2 pr-4">{{ __('Salah satu') }}</td>
                        <td class="py-2">{{ __('Nama atau ID template yang sudah disetujui.') }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">parameters</td>
                        <td class="py-2 pr-4">{{ __('Jika ada variabel') }}</td>
                        <td class="py-2">{{ __('Array untuk POSITIONAL, objek untuk NAMED.') }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">header_media_url</td>
                        <td class="py-2 pr-4">{{ __('Jika header media') }}</td>
                        <td class="py-2">{{ __('URL gambar, video, atau dokumen untuk header.') }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs">purpose</td>
                        <td class="py-2 pr-4">{{ __('Tidak') }}</td>
                        <td class="py-2">{{ __('Jalur pengiriman yang dipetakan admin.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Contoh panggilan') }}</h3>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">curl -X POST "{{ url('/api/v1/wara') }}/send" \
  -H "Authorization: Bearer TOKEN_API_ANDA" \
  -H "Content-Type: application/json" \
  -d '{
        "to": "628123456789",
        "template": "notifikasi_tagihan",
        "parameters": ["Budi Santoso", "INV-2026-001", "Rp150.000"]
      }'</pre>
            </div>
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Respons') }}</h3>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">{
  "status": "sent",
  "client": "Nama Client",
  "purpose": "notifikasi",
  "template": "notifikasi_tagihan",
  "message_id": "3EB0ABC123456789"
}</pre>
            </div>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Respons pesan teks menyertakan "template": null. Field ini aditif dan aman diabaikan oleh integrasi lama.') }}
        </p>

        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('Bila pengiriman terlalu cepat, API membalas 429 dengan header Retry-After. Tunggu sesuai nilai tersebut lalu kirim ulang.') }}
        </div>
    </x-wara::card>

    {{-- Daftar Template --}}
    <x-wara::card
        :title="__('Daftar Template')"
        :subtitle="__('Hanya template yang sudah disetujui yang tersedia.')"
        icon="layout-list"
        accent="sky">
        <x-slot name="header">
            <span class="inline-flex items-center gap-2">
                <span class="inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">GET</span>
                <span class="font-mono text-xs text-gray-600 dark:text-gray-400">/templates</span>
            </span>
        </x-slot>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Query (opsional)') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4 font-semibold">{{ __('Parameter') }}</th>
                                <th class="py-2 font-semibold">{{ __('Keterangan') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-white/[0.06] dark:text-gray-300">
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">category</td>
                                <td class="py-2">{{ __('LAYANAN, INFORMASI, atau OTP.') }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">search</td>
                                <td class="py-2">{{ __('Cari berdasarkan potongan nama.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Contoh panggilan') }}</h3>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">curl "{{ url('/api/v1/wara') }}/templates?category=INFORMASI&search=tagihan" \
  -H "Authorization: Bearer TOKEN_API_ANDA"</pre>
            </div>
        </div>

        <div class="mt-4">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Respons') }}</h3>
            <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 font-mono text-xs text-gray-200 dark:bg-gray-950">{
  "data": [
    {
      "id": "9f1c2a3b-4d5e-6f70-8192-a3b4c5d6e7f8",
      "name": "notifikasi_tagihan",
      "category": "INFORMASI",
      "parameter_format": "POSITIONAL",
      "header": {
        "format": "IMAGE",
        "text": null
      },
      "body": {
        "text": "Halo @{{1}}, tagihan @{{2}} sebesar @{{3}} jatuh tempo."
      },
      "buttons": [
        {
          "type": "URL",
          "text": "Lihat Tagihan",
          "url": "https://contoh.id/tagihan",
          "phone_number": null
        }
      ]
    }
  ]
}</pre>
        </div>
    </x-wara::card>

    {{-- Catatan --}}
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
        {{ __('Hanya template yang sudah disetujui yang bisa dikirim. Isi variabel mengikuti template: gunakan array untuk POSITIONAL atau objek untuk NAMED. Jika message dan template dikirim bersamaan, template yang dipakai.') }}
    </div>
</div>
