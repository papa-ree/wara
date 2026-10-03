<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL Service Gateway
    |--------------------------------------------------------------------------
    |
    | Base URL service Go (GOWA). Contoh lokal: http://localhost:7200
    | Tidak ada default produksi yang aman, jadi wajib diisi.
    |
    */

    'endpoint' => env('WARA_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Basic Auth
    |--------------------------------------------------------------------------
    |
    | Kredensial basic-auth service Go, format "user:pass".
    |
    */

    'api_key' => env('WARA_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Secret Webhook (HMAC)
    |--------------------------------------------------------------------------
    |
    | Harus identik dengan WARA_WEBHOOK_SECRET di sisi service Go. Kosongkan
    | selama pengembangan supaya event lokal tidak bocor ke production.
    |
    */

    'webhook_secret' => env('WARA_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Device Pengirim Default
    |--------------------------------------------------------------------------
    |
    | Jaring pengaman untuk request tanpa `purpose` (PRD §6.5.1 baris 3 & 4).
    | WAJIB format JID penuh, bukan nama device — nama device bisa diubah lewat
    | dashboard sedangkan JID immutable. Nilai di-resolve ke baris wara_sessions
    | lewat kolom `jid`, lalu yang dikirim ke header adalah Session ID-nya
    | (PRD §6.3.2).
    |
    | Catatan: pemetaan tujuan → device SENGAJA tidak ada di config. Itu
    | keputusan operasional yang dikelola admin di tabel wara_sessions.purpose.
    |
    */

    'default_device_id' => env('WARA_DEFAULT_DEVICE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Timeout HTTP (detik)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('WARA_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Jadwal Pemeriksaan (§8.2, §8.3)
    |--------------------------------------------------------------------------
    |
    | `sync_devices_every_minutes`  - katalog device dari GOWA (PRD §7.1)
    | `poll_status_every_minutes`   - deteksi logged-out/banned. Dipisah dari
    |                                sync karena §8.3 meminta deteksi cepat.
    | `offline_alert_after_minutes` - berapa lama `disconnected`/`connecting`
    |                                berlangsung sebelum ditandai sebagai
    |                                peringatan pairing ulang.
    |
    */

    'sync_devices_every_minutes' => (int) env('WARA_SYNC_DEVICES_EVERY', 5),
    'poll_status_every_minutes' => (int) env('WARA_POLL_STATUS_EVERY', 2),
    'offline_alert_after_minutes' => (int) env('WARA_OFFLINE_ALERT_AFTER', 10),

    /*
    |--------------------------------------------------------------------------
    | Pacing & Anti-Ban (§8.2)
    |--------------------------------------------------------------------------
    |
    | GOWA tidak punya rem pengiriman sama sekali (PRD §8.1), jadi seluruh
    | pengaman harus dibuat di sisi Bale.
    |
    | `cooldown_seconds`          - jarak minimum antar pengiriman ke nomor yang
    |                              sama. OTP kedua ke nomor yang sama ditahan,
    |                              bukan dikirim ulang.
    | `max_attempts_per_session`  - batas percobaan kirim per sesi login, agar
    |                              pola "captcha salah → kirim ulang" tidak
    |                              berubah jadi burst.
    | `device_backoff_seconds`    - jeda sebelum mencoba ulang device yang
    |                              sedang tidak siap.
    |
    */

    'cooldown_seconds' => (int) env('WARA_COOLDOWN_SECONDS', 60),
    'max_attempts_per_session' => (int) env('WARA_MAX_ATTEMPTS_PER_SESSION', 3),
    'session_window_seconds' => (int) env('WARA_SESSION_WINDOW_SECONDS', 900),
    'device_backoff_seconds' => (int) env('WARA_DEVICE_BACKOFF_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Format Nomor
    |--------------------------------------------------------------------------
    |
    | Nomor telepon dikirim ke gateway dalam format internasional tanpa suffix
    | (`62812...`). Penambahan `@s.whatsapp.net` adalah urusan adapter, bukan
    | konsumen (PRD §11.1).
    |
    */

    'phone_format' => [
        'strip_non_digits' => true,
        'local_prefix_to_international' => '0',
        'international_prefix' => '62',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fan-Out (§12)
    |--------------------------------------------------------------------------
    |
    | 🔴 DULUNYA ADA BLOCK `consumers` DI SINI, DAN SEKARANG SUDAH DIHAPUS.
    |
    | URL, secret, dan daftar event tiap client pernah hidup di file ini, yang
    | berarti tidak bisa diatur per CLIENT dan tidak bisa dibuat dari UI admin.
    | Sekarang semuanya ada di `wara_webhook_endpoints`, dan `enabled` di sana
    | yang menentukan on/off per package.
    |
    | Yang TETAP di config cuma perilaku retry/timeout jaringan. Ini memang
    | properti gateway, bukan properti client - membiarkan admin mengubahnya
    | dari UI akan jadi permukaan serang yang tidak perlu ada.
    |
    */

    'fanout' => [
        'timeout' => (int) env('WARA_FANOUT_TIMEOUT', 5),
        'tries' => (int) env('WARA_FANOUT_TRIES', 3),
        'backoff' => (int) env('WARA_FANOUT_BACKOFF', 30),
    ],

];
