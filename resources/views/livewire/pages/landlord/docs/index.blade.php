<div class="space-y-6">
    <x-core::breadcrumb
        :items="[]"
        :active="__('Dokumentasi API')" />

    <x-core::page-header
        :title="__('Dokumentasi API Wara')"
        :subtitle="__('Panduan lengkap integrasi REST API gateway WhatsApp (GOWA) meliputi autentikasi, pengiriman pesan, dan manajemen route purpose.')"
    />

    {{-- Quick Overview --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <x-lucide-book-open class="size-5 text-indigo-600 dark:text-indigo-400" />
            {{ __('Ringkasan Integrasi') }}
        </h2>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Gateway WhatsApp Wara menyediakan REST API berbasis token dengan sistem keamanan multi-lapis (`bale/api`). Setiap request REST API wajib menyertakan token Bearer, melewati pembatasan rate limit (throttle), serta memenuhi aturan hardening IP dan Host domain.') }}
        </p>
        <div class="grid gap-4 sm:grid-cols-3 pt-2">
            <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-4 border border-gray-200 dark:border-gray-700/60">
                <div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ __('Base URL') }}</div>
                <div class="mt-1 font-mono text-xs text-gray-800 dark:text-gray-200 select-all">/api/v1/wara</div>
            </div>
            <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-4 border border-gray-200 dark:border-gray-700/60">
                <div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ __('Autentikasi') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">Bearer Token (`Authorization`)</div>
            </div>
            <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-4 border border-gray-200 dark:border-gray-700/60">
                <div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ __('Format Data') }}</div>
                <div class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">JSON Request & Response</div>
            </div>
        </div>
    </div>

    {{-- Authentication & Scopes --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <x-lucide-shield-check class="size-5 text-indigo-600 dark:text-indigo-400" />
            {{ __('Autentikasi & Scope API') }}
        </h2>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Wara tidak menggunakan tabel token terpisah. Token dikelola melalui modul `bale/api` dengan scope permissions spesifik:') }}
        </p>
        <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1.5 pl-2">
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-indigo-600 dark:text-indigo-400">wara.send</code> — Wajib dimiliki token untuk mengirim pesan via <code class="font-mono text-xs">POST /api/v1/wara/send</code>.</li>
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-indigo-600 dark:text-indigo-400">wara.routes.read</code> — Wajib untuk melihat daftar pemetaan route <code class="font-mono text-xs">GET /api/v1/wara/routes</code>.</li>
            <li><code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-indigo-600 dark:text-indigo-400">wara.routes.write</code> — Wajib untuk mendaftarkan/memperbarui route purpose <code class="font-mono text-xs">PUT /api/v1/wara/routes</code>.</li>
        </ul>
        <div class="rounded-xl bg-gray-900 p-4 font-mono text-xs text-gray-200 overflow-x-auto">
            Authorization: Bearer rkc_live_xxxxxxxxxxxxxxxxxxxxxxxx
        </div>
    </div>

    {{-- Endpoint 1: Send Message --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">POST</span>
                <span class="font-mono text-sm">/api/v1/wara/send</span>
            </h2>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-indigo-600 dark:text-indigo-400">wara.send</code></span>
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
  "message_id": "3EB0ABC123456789"
}</pre>
            </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800 pt-3 text-xs text-gray-500 dark:text-gray-400 space-y-1">
            <div><strong class="text-gray-700 dark:text-gray-300">HTTP 422 Unprocessable Entity:</strong> Device belum siap, offline, atau route purpose belum dipetakan.</div>
            <div><strong class="text-gray-700 dark:text-gray-300">HTTP 502 Bad Gateway:</strong> Permintaan ditolak oleh gateway GOWA.</div>
        </div>
    </div>

    {{-- Endpoint 2: List Routes --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-bold bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">GET</span>
                <span class="font-mono text-sm">/api/v1/wara/routes</span>
            </h2>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-indigo-600 dark:text-indigo-400">wara.routes.read</code></span>
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

    {{-- Endpoint 3: Upsert Route --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">PUT</span>
                <span class="font-mono text-sm">/api/v1/wara/routes</span>
            </h2>
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Scope: <code class="font-mono text-indigo-600 dark:text-indigo-400">wara.routes.write</code></span>
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
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700/60 dark:bg-gray-900 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h2 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-bold bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300">POST</span>
                <span class="font-mono text-sm">/api/wara/webhook</span>
            </h2>
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
