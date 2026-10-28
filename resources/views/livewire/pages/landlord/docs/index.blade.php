<div class="space-y-6">
    <x-core::breadcrumb
        :items="[]"
        :active="__('Dokumentasi API')" />

    <x-wara::hero
        :title="__('Dokumentasi API Wara')"
        :subtitle="__('Panduan integrasi REST API gateway WhatsApp (GOWA): autentikasi, pengiriman pesan teks, pengiriman berbasis template, katalog template, dan manajemen route purpose.')"
        icon="book-open"
        :badge="__('API')">
        <x-slot name="meta">
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-key-round class="size-3.5" />
                {{ __('Bearer Token') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-shield-check class="size-3.5" />
                {{ __('Rate limit & hardening') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-lucide-file-json class="size-3.5" />
                {{ __('JSON') }}
            </span>
        </x-slot>
    </x-wara::hero>

    {{-- Quick Overview --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-md shadow-teal-500/25 dark:shadow-teal-500/20">
                <x-lucide-book-open class="size-4 text-white" />
            </div>
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">
                {{ __('Ringkasan Integrasi') }}
            </h2>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Gateway WhatsApp Wara menyediakan REST API berbasis token dengan sistem keamanan multi-lapis (`bale/api`). Setiap request REST API wajib menyertakan token Bearer, melewati pembatasan rate limit (throttle), serta memenuhi aturan hardening IP dan Host domain.') }}
        </p>
        <div class="grid gap-4 sm:grid-cols-3 pt-2">
            <div class="rounded-xl border border-teal-100 bg-teal-50/50 p-4 dark:border-teal-800/30 dark:bg-teal-900/15">
                <div class="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">{{ __('Base URL') }}</div>
                <div class="mt-1 font-mono text-xs text-gray-800 dark:text-gray-200 select-all">/api/v1/wara</div>
            </div>
            <div class="rounded-xl border border-teal-100 bg-teal-50/50 p-4 dark:border-teal-800/30 dark:bg-teal-900/15">
                <div class="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">{{ __('Autentikasi') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">Bearer Token (`Authorization`)</div>
            </div>
            <div class="rounded-xl border border-teal-100 bg-teal-50/50 p-4 dark:border-teal-800/30 dark:bg-teal-900/15">
                <div class="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">{{ __('Format Data') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">JSON Request & Response</div>
            </div>
        </div>
    </div>

    {{-- Authentication & Scopes --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-md shadow-emerald-500/25 dark:shadow-emerald-500/20">
                <x-lucide-shield-check class="size-4 text-white" />
            </div>
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">
                {{ __('Autentikasi & Scope API') }}
            </h2>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Wara tidak menggunakan tabel token terpisah. Token dikelola melalui modul `bale/api` dengan scope permissions spesifik:') }}
        </p>
        <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1.5 pl-2">
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-teal-600 dark:text-teal-400">wara.send</code> — Wajib dimiliki token untuk mengirim pesan via <code class="font-mono text-xs">POST /api/v1/wara/send</code>.</li>
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-teal-600 dark:text-teal-400">wara.routes.read</code> — Wajib untuk melihat daftar pemetaan route <code class="font-mono text-xs">GET /api/v1/wara/routes</code>.</li>
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-teal-600 dark:text-teal-400">wara.routes.write</code> — Wajib untuk mendaftarkan/memperbarui route purpose <code class="font-mono text-xs">PUT /api/v1/wara/routes</code>.</li>
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-teal-600 dark:text-teal-400">wara.templates.read</code> — Wajib untuk melihat katalog template <code class="font-mono text-xs">GET /api/v1/wara/templates</code>. Mengirim template tetap memakai scope <code class="font-mono text-xs">wara.send</code>.</li>
        </ul>
        <div class="rounded-xl bg-gray-900 p-4 font-mono text-xs text-gray-200 overflow-x-auto">
            Authorization: Bearer rkc_live_xxxxxxxxxxxxxxxxxxxxxxxx
        </div>
    </div>

    {{-- Konsep Template --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/25 dark:shadow-sky-500/20">
                <x-lucide-file-text class="size-4 text-white" />
            </div>
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">
                {{ __('Konsep Template Pesan') }}
            </h2>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Template adalah katalog lokal milik instalasi Wara, bukan template Meta/WABA. Gateway GOWA tidak punya endpoint template, jadi saat dikirim template dirakit menjadi teks — dan media bila header-nya berupa media. Hanya template berstatus APPROVED yang bisa dipakai lewat API.') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-4 space-y-2 dark:border-white/[0.06] dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">{{ __('Siklus Status') }}</div>
                <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1">
                    <li><code class="font-mono text-xs">DRAFT</code> — dibuat guest, belum diajukan.</li>
                    <li><code class="font-mono text-xs">PENDING</code> — menunggu review admin.</li>
                    <li><code class="font-mono text-xs">APPROVED</code> — boleh dipakai API; isinya tidak bisa diubah.</li>
                    <li><code class="font-mono text-xs">REJECTED</code> — ditolak beserta alasan; boleh diperbaiki lalu diajukan ulang.</li>
                </ul>
            </div>
            <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-4 space-y-2 dark:border-white/[0.06] dark:bg-gray-900/40">
                <div class="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">{{ __('Kategori & Format Parameter') }}</div>
                <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1">
                    <li><code class="font-mono text-xs">LAYANAN</code>, <code class="font-mono text-xs">INFORMASI</code>, <code class="font-mono text-xs">OTP</code> — kategori isi template.</li>
                    <li><code class="font-mono text-xs">POSITIONAL</code> — variabel <code class="font-mono text-xs">@{{1}}</code>, <code class="font-mono text-xs">@{{2}}</code>, … (default).</li>
                    <li><code class="font-mono text-xs">NAMED</code> — variabel <code class="font-mono text-xs">@{{nama}}</code>, <code class="font-mono text-xs">@{{nomor}}</code>, …</li>
                </ul>
            </div>
        </div>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Struktur Komponen') }}</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-semibold">{{ __('Komponen') }}</th>
                            <th class="py-2 font-semibold">{{ __('Aturan') }}</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 dark:text-gray-300 divide-y divide-gray-100 dark:divide-gray-800">
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">header</td>
                            <td class="py-2">{{ __('Format NONE / TEXT / IMAGE / VIDEO / DOCUMENT. TEXT maksimal 60 karakter dan maksimal satu variabel. Header media tidak boleh memuat teks.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">body</td>
                            <td class="py-2">{{ __('Wajib, maksimal 1024 karakter. Setiap variabel wajib punya contoh nilai di body.examples — contoh dipakai admin saat review, bukan nilai yang dikirim.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">footer</td>
                            <td class="py-2">{{ __('Opsional, maksimal 60 karakter, tanpa variabel.') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">buttons</td>
                            <td class="py-2">{{ __('Maksimal 10 tombol. Tipe QUICK_REPLY / URL / PHONE_NUMBER / COPY_CODE; maksimal 2 tombol URL, 1 telepon, 1 salin kode; teks 1-25 karakter.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-800/40 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('Penomoran parameter POSITIONAL berlaku untuk satu pesan penuh (header + body digabung), karena API menerima satu array parameters. Header dan body tidak boleh memakai nomor berbeda untuk nilai yang berbeda.') }}
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-500">
            {{ __('Catatan: alur OTP bale/srikandi tidak memakai template dan tetap mengirim teks biasa. Template hanya untuk pemanggil API eksternal.') }}
        </p>
    </div>

    {{-- Endpoint 1: Send Message --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-md shadow-emerald-500/25 dark:shadow-emerald-500/20">
                    <x-lucide-send class="size-4 text-white" />
                </div>
                <h2 class="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    <span class="inline-flex items-center rounded-md bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">POST</span>
                    <span class="font-mono text-sm">/api/v1/wara/send</span>
                </h2>
            </div>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-teal-600 dark:text-teal-400">wara.send</code></span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Mengirim pesan WhatsApp ke nomor tujuan. Client pengirim ditentukan secara otomatis dari token pemanggil (`SendClientResolver`), dan device dipilih berdasarkan mapping `purpose`.') }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Request Body (JSON)') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "to": "628123456789",
  "message": "Halo, ini pesan uji coba dari Bale Wara.",
  "purpose": "otp"
}</pre>
            </div>
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Response (200 OK)') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "status": "sent",
  "client": "Srikandi (Package)",
  "purpose": "otp",
  "template": null,
  "message_id": "3EB0ABC123456789"
}</pre>
            </div>
        </div>

        <div class="rounded-xl border border-teal-200 bg-teal-50/60 p-4 dark:border-teal-800/40 dark:bg-teal-900/20 space-y-3">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-teal-700 dark:text-teal-300">{{ __('Mengirim Berbasis Template') }}</h4>
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ __('Kirim `template` sebagai ganti `message`. `template` boleh berupa UUID atau nama persis. Nama hanya unik per pemilik, jadi bila satu nama dipakai lebih dari satu pemilik server menolak dengan 422 (ambigu) dan tidak memilih salah satu. Bila `message` dan `template` dikirim bersamaan, `template` yang dipakai.') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Request Body (JSON)') }}</h4>
                    <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "to": "628123456789",
  "template": "notifikasi_tagihan",
  "parameters": ["Budi Santoso", "INV-2026-001", "Rp150.000"],
  "header_media_url": "https://cdn.example.com/invoice.png",
  "purpose": "notifikasi"
}</pre>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Response (200 OK)') }}</h4>
                    <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "status": "sent",
  "client": "Srikandi (Package)",
  "purpose": "notifikasi",
  "template": "notifikasi_tagihan",
  "message_id": "3EB0ABC123456789"
}</pre>
                </div>
            </div>

            <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1.5 pl-2">
                <li>
                    {{ __('`parameters` — untuk POSITIONAL berupa array urut mulai') }}
                    <code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1 py-0.5 rounded">@{{1}}</code>
                    {{ __('sampai') }}
                    <code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1 py-0.5 rounded">@{{n}}</code>
                    {{ __('(header + body digabung); untuk NAMED berupa objek dengan kunci nama variabel, mis.') }}
                    <code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1 py-0.5 rounded">{"nama":"Budi","nomor":"INV-2026-001"}</code>.
                </li>
                <li>{{ __('Parameter yang kurang ditolak 422. Parameter berlebih diabaikan. Setiap nilai maksimal 1024 karakter. Template tanpa variabel boleh dikirim tanpa `parameters`.') }}</li>
                <li>{{ __('`header_media_url` wajib bila header template berformat IMAGE / VIDEO / DOCUMENT. URL ini yang dikirim ke endpoint media gateway, sedangkan caption-nya adalah hasil render teks template. Maksimal 2048 karakter.') }}</li>
                <li>{{ __('Tombol tidak dikirim sebagai tombol native — gateway tidak mendukungnya. Perakit menuliskannya sebagai daftar bernomor di akhir pesan.') }}</li>
                <li>{{ __('Hanya template APPROVED yang bisa dipakai. DRAFT, PENDING, dan REJECTED selalu ditolak 422.') }}</li>
                <li>{{ __('Penolakan template dibalas 422 dengan `error_code: template_required` — templatenya tidak ada, ambigu, atau belum APPROVED. Kode ini tidak berarti teks wajib diganti template; `message` bebas tetap sah.') }}</li>
            </ul>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Contoh panggilan') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">curl -X POST "https://wara.contoh.id/api/v1/wara/send" \
  -H "Authorization: Bearer rkc_live_xxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
        "to": "628123456789",
        "template": "notifikasi_tagihan",
        "parameters": ["Budi Santoso", "INV-2026-001", "Rp150.000"]
      }'</pre>
            </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-3 text-xs text-gray-500 dark:text-gray-400 space-y-1">
            <div><strong class="text-gray-700 dark:text-gray-300">HTTP 422 Unprocessable Entity:</strong> {{ __('device belum siap/offline, route purpose belum dipetakan, template tidak ditemukan atau ambigu, template belum APPROVED, parameter kurang, atau header media dikirim tanpa `header_media_url`. Bila penyebabnya template, body memuat `error_code: template_required`.') }}</div>
            <div><strong class="text-gray-700 dark:text-gray-300">HTTP 502 Bad Gateway:</strong> {{ __('permintaan ditolak oleh gateway GOWA.') }}</div>
        </div>
    </div>

    {{-- Endpoint 2: List Templates --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/25 dark:shadow-sky-500/20">
                    <x-lucide-layout-list class="size-4 text-white" />
                </div>
                <h2 class="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    <span class="inline-flex items-center rounded-md bg-sky-100 px-2.5 py-0.5 text-xs font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">GET</span>
                    <span class="font-mono text-sm">/api/v1/wara/templates</span>
                </h2>
            </div>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-teal-600 dark:text-teal-400">wara.templates.read</code></span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Mendapatkan katalog template berstatus APPROVED. Katalog bersifat global untuk instalasi — tidak menerima `client_id`, karena identitas pemanggil tetap ditentukan dari token. Gunakan `id` (UUID) atau `name` dari respons ini sebagai nilai `template` pada endpoint kirim.') }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Query Parameter (opsional)') }}</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4 font-semibold">{{ __('Parameter') }}</th>
                                <th class="py-2 font-semibold">{{ __('Keterangan') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700 dark:text-gray-300 divide-y divide-gray-100 dark:divide-gray-800">
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">category</td>
                                <td class="py-2">{{ __('Saring kategori: LAYANAN, INFORMASI, atau OTP. Nilai lain ditolak 422.') }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">search</td>
                                <td class="py-2">{{ __('Cari berdasarkan potongan nama template, maksimal 100 karakter.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Contoh panggilan') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">curl "https://wara.contoh.id/api/v1/wara/templates?category=INFORMASI&search=tagihan" \
  -H "Authorization: Bearer rkc_live_xxxxxxxxxxxxxxxxxxxxxxxx"</pre>
            </div>
        </div>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Response (200 OK)') }}</h4>
            <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
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
        "text": "Halo @{{1}}, tagihan @{{2}} sebesar @{{3}} jatuh tempo.",
        "examples": {
          "1": "Budi Santoso",
          "2": "INV-2026-001",
          "3": "Rp150.000"
        }
      },
      "footer": {
        "text": "PT Contoh Nusantara"
      },
      "buttons": [
        {
          "type": "URL",
          "text": "Lihat Tagihan",
          "url": "https://contoh.id/tagihan",
          "phone_number": null
        }
      ],
      "updated_at": "2026-10-20T09:00:00+00:00"
    }
  ]
}</pre>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-3 text-xs text-gray-500 dark:text-gray-400 space-y-1">
            <div>{{ __('Hanya template APPROVED yang muncul. `user_uuid` pemilik dan metadata review (penolakan, reviewer) tidak pernah dikembalikan.') }}</div>
            <div>{{ __('Untuk header media, `header.text` selalu null; URL medianya tidak disimpan di template dan wajib dikirim sebagai `header_media_url` saat mengirim pesan.') }}</div>
        </div>
    </div>

    {{-- Endpoint 3: List Routes --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-sky-600 shadow-md shadow-sky-500/25 dark:shadow-sky-500/20">
                    <x-lucide-route class="size-4 text-white" />
                </div>
                <h2 class="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    <span class="inline-flex items-center rounded-md bg-sky-100 px-2.5 py-0.5 text-xs font-bold text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">GET</span>
                    <span class="font-mono text-sm">/api/v1/wara/routes</span>
                </h2>
            </div>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-teal-600 dark:text-teal-400">wara.routes.read</code></span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Mendapatkan daftar seluruh pemetaan purpose ke device WhatsApp yang dimiliki oleh client pemanggil.') }}
        </p>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Response (200 OK)') }}</h4>
            <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "client": {
    "id": "9a1b2c3d-...",
    "name": "Srikandi (Package)"
  },
  "routes": [
    {
      "purpose": "otp",
      "device_id": "device-01",
      "outbound_enabled": true,
      "inbound_webhook_enabled": true,
      "device_ready": true
    }
  ]
}</pre>
        </div>
    </div>

    {{-- Endpoint 4: Upsert Route --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 shadow-md shadow-amber-500/25 dark:shadow-amber-500/20">
                    <x-lucide-route class="size-4 text-white" />
                </div>
                <h2 class="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">PUT</span>
                    <span class="font-mono text-sm">/api/v1/wara/routes</span>
                </h2>
            </div>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-teal-600 dark:text-teal-400">wara.routes.write</code></span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Membuat atau memperbarui (upsert) pemetaan antara suatu `purpose` pengiriman pesan dengan `device_id` WhatsApp tertentu.') }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Request Body (JSON)') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "purpose": "otp",
  "device_id": "device-01",
  "outbound_enabled": true,
  "inbound_webhook_enabled": true
}</pre>
            </div>
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Response (200 OK)') }}</h4>
                <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "purpose": "otp",
  "device_id": "device-01",
  "outbound_enabled": true,
  "inbound_webhook_enabled": true
}</pre>
            </div>
        </div>
    </div>

    {{-- Webhook Receiver --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/[0.06] dark:bg-gray-900 space-y-4 sm:p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-md shadow-violet-500/25 dark:shadow-violet-500/20">
                    <x-lucide-webhook class="size-4 text-white" />
                </div>
                <h2 class="flex items-center gap-2 text-base font-bold text-gray-900 dark:text-gray-100">
                    <span class="inline-flex items-center rounded-md bg-violet-100 px-2.5 py-0.5 text-xs font-bold text-violet-800 dark:bg-violet-900/40 dark:text-violet-300">POST</span>
                    <span class="font-mono text-sm">/api/wara/webhook</span>
                </h2>
            </div>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Public Endpoint (HMAC Signature Verified)') }}</span>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Endpoint penerima webhook masuk dari gateway WhatsApp GOWA. Dilindungi oleh header verifikasi tanda tangan cryptographic `X-Hub-Signature-256`. Event pesan masuk secara otomatis diteruskan ke event internal Laravel (`WaraIncomingMessage`).') }}
        </p>

        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('Contoh Payload Masuk dari GOWA') }}</h4>
            <pre class="rounded-xl bg-gray-900 p-3 font-mono text-xs text-gray-200 overflow-x-auto">{
  "event": "message",
  "device_id": "device-01",
  "payload": {
    "from": "628987654321@s.whatsapp.net",
    "body": "OTP Anda adalah 123456"
  }
}</pre>
        </div>
    </div>
</div>
