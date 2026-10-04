<?php

/*
|--------------------------------------------------------------------------
| Status bale/wara
|--------------------------------------------------------------------------
|
| Sumber tunggal untuk halaman status (route `wara.status.index`).
|
| Isi di sini adalah RINGKASAN yang bisa dibaca dashboard, bukan pengganti
| dokumen. Rincian dan alasannya tetap ada di `docs/wara/PRD.md`,
| `docs/wara/PLAN.md`, dan `docs/wara/TOPIK.md`.
|
| 🔴 Status di file ini bisa basi kalau dokumennya sudah diperbarui tapi file
| ini belum. Karena itu halaman menampilkan `updated_at` supaya staleness-nya
| terlihat, bukan harus ditebak.
|
| Yang tersisa sebagai daftar kerja ada di `docs/wara/TODO.md` (P0-P3). Kalau
| item di bawah terlihat tidak cocok dengan kodenya, perbarui kedua file itu
| bersama-sama -- jangan hanya satu.
|
| Referensi: status fase dari PLAN.md, item produksi dari TOPIK.md §8.
|
| Bentuk yang dipakai renderer `x-core::checklist`:
|
|   'sections' => [ ['title' => ..., 'items' => [ ['label','status','note','meta'], ... ] ], ... ]
|
| `status` hanya boleh `done`, `open`, atau `blocked`. Nilai lain ditampilkan
| apa adanya oleh komponen, bukan dianggap selesai.
|
*/

return [

    'title' => 'Status bale/wara',

    'subtitle' => 'Pembungkus gateway WhatsApp (GOWA). Seluruh fase implementasi selesai sampai Fase 13; '
        .'sisa yang tercatat adalah persiapan produksi dan keputusan yang masih terbuka, bukan fitur '
        .'yang belum dibangun. Daftar kerjanya di `docs/wara/TODO.md`.',

    'updated_at' => '2026-10-04',

    'doc' => [
        'label' => 'Dokumen',
        'href' => 'docs/wara/PRD.md',
    ],

    'sections' => [

        [
            'title' => 'Fase implementasi',
            'items' => [
                [
                    'label' => 'Fase 0 — Rekonsiliasi repo & dokumen',
                    'status' => 'done',
                    'meta' => 'PRD §1–§3',
                ],
                [
                    'label' => 'Fase 1 — Kerangka package',
                    'status' => 'done',
                    'meta' => 'PRD §4',
                ],
                [
                    'label' => 'Fase 2 — Adapter GOWA + resolusi device',
                    'status' => 'done',
                    'meta' => 'PRD §11, §6.5',
                    'note' => 'Semua detail GOWA (sufiks JID, `X-Hub-Signature-256`) terisolasi di adapter. '
                        .'Device offline gagal keras tanpa fallback.',
                ],
                [
                    'label' => 'Fase 3 — Webhook receiver',
                    'status' => 'done',
                    'meta' => 'PRD §6',
                    'note' => 'Verifikasi HMAC berjalan sebelum payload disentuh sama sekali.',
                ],
                [
                    'label' => 'Fase 4 — Migration, model, dashboard admin',
                    'status' => 'done',
                    'meta' => 'PRD §7',
                    'note' => 'Cakupan berubah: penugasan `purpose` pindah ke halaman `Wara > Client` '
                        .'karena `wara_sessions.purpose` sudah dihapus. Client, route, dan endpoint webhook '
                        .'sekarang dikelola dari sana.',
                ],
                [
                    'label' => 'Fase 5 — `wara:sync-devices`',
                    'status' => 'done',
                    'meta' => 'PRD §7.1',
                ],
                [
                    'label' => 'Fase 6 — Pacing & deteksi ban',
                    'status' => 'done',
                    'meta' => 'PRD §8.2–§8.3',
                    'note' => 'Cooldown, batas percobaan per sesi, backoff device, dan `wara:poll-status --json`.',
                ],
                [
                    'label' => 'Fase 7 — Verifikasi DoD di browser sungguhan',
                    'status' => 'done',
                    'meta' => 'PRD §10',
                ],
                [
                    'label' => 'Fase 8 — Fan-out ke client',
                    'status' => 'done',
                    'meta' => 'PRD §12',
                    'note' => 'HMAC dihitung atas raw body byte-wise. Endpoint didaftarkan per-client di '
                        .'`wara_webhook_endpoints`, bukan config — dan Srikandi tidak didaftarkan sama sekali '
                        .'karena MFA-nya TOTP aplikasi autentikator.',
                ],
                [
                    'label' => 'Fase 9 — `WaraChannel` notifikasi Laravel',
                    'status' => 'done',
                    'meta' => 'PRD §13',
                    'note' => 'DoD 7/7. Terverifikasi sampai ke GOWA nyata — pesan diterima di perangkat.',
                ],
                [
                    'label' => 'Fase 10 — Event inbound `WaraIncomingMessage`',
                    'status' => 'done',
                    'meta' => 'TOPIK §7',
                    'note' => 'Dibangun untuk `bale/srikandi`. Dispatch di luar transaksi, dibungkus `try/catch` '
                        .'supaya kegagalan listener tidak mengubah `200` menjadi `500`.',
                ],
                [
                    'label' => 'Fase 11 — Router per-client',
                    'status' => 'done',
                    'meta' => 'PRD §6.5, §7.1a',
                    'note' => 'Tiga tabel baru: `wara_clients`, `wara_routes`, `wara_webhook_endpoints`. '
                        .'Penugasan pindah dari `wara_sessions.purpose` yang unik GLOBAL ke '
                        .'`UNIQUE (client_id, purpose)` — sekarang satu device boleh dipakai banyak client. '
                        .'Client tanpa route gagal keras, tidak jatuh ke `default_device_id`.',
                ],
                [
                    'label' => 'Fase 12 — Buang kolom organisasi dari `api_tokens` & `srikandi_naskah`',
                    'status' => 'done',
                    'meta' => 'PRD §12.8',
                    'note' => '`api_tokens.bale_id` dicabut: `bale/api` tidak boleh terikat ke `bale/cms`, '
                        .'dan token untuk sistem pihak ketiga tidak punya `bale_lists` sama sekali. '
                        .'Identitas lengkap (token + organisasi) hidup berdampingan di `wara_clients`.',
                ],
                [
                    'label' => 'Fase 13 — UI manajemen client + client per package',
                    'status' => 'done',
                    'meta' => 'PLAN §16',
                    'note' => 'Halaman `Wara > Client` terdaftar di sidebar SEBELUM Device — `purpose` tidak lagi '
                        .'kolom device, jadi admin yang membuka Device dulu akan mencari kolom yang memang '
                        .'tidak ada. Device jadi read-only. Tiap package punya client sendiri; '
                        .'`srikandi:install` membuat `Srikandi (Package)`. `Kosadata` & `Wago` di-uninstall.',
                ],
            ],
        ],

        [
            'title' => 'Persiapan produksi (TOPIK §8)',
            'items' => [
                [
                    'label' => 'Batas percobaan jadi stop condition, bukan cooldown',
                    'status' => 'blocked',
                    'meta' => 'wajib',
                    'note' => '`max_attempts_per_session` harus menghentikan siklus sepenuhnya dan menunggu '
                        .'campuran tangan manusia, bukan "coba lagi nanti".',
                ],
                [
                    'label' => 'Peringatan status device buruk di dashboard',
                    'status' => 'done',
                    'meta' => 'PRD §8.3',
                    'note' => '🔴 Status ini sempat `open` padahal sudah ada, jadi dicatat di '
                        .'`docs/wara/TODO.md` P0 sebagai koreksi. Amber "Device perlu dipasang ulang" + abu '
                        .'"Device sedang tidak siap" sudah dirender di header Device, ambangnya '
                        .'`offline_alert_after_minutes`, dan `wara:poll-status` exit non-nol saat perlu perhatian.',
                ],
                [
                    'label' => 'Pisahkan akun uji dari akun produksi',
                    'status' => 'open',
                    'note' => 'Nomor yang dipakai runtime adalah akun yang sama dengan benchmark captcha. '
                        .'Setiap uji integrasi sekarang memakai risiko lockout yang sama dengan produksi.',
                ],
                [
                    'label' => 'Runbook lockout',
                    'status' => 'open',
                    'note' => 'Berapa lama, lewat kanal apa, dan siapa yang memegang akun cadangan. '
                        .'Harus tertulis sebelum hari pertama produksi.',
                ],
            ],
        ],

        [
            'title' => 'Keputusan teknis yang masih terbuka',
            'items' => [
                [
                    'label' => 'Enkripsi `chat_id` dan `wara_sessions.jid`',
                    'status' => 'open',
                    'note' => '🔴 Hambatan yang dulu tercatat sudah gugur. Yang tertulis sebelumnya menyebut '
                        .'"polling `bale/srikandi` bergantung pada `chat_id`" — faktanya `OtpPendingController` '
                        .'mencari lewat `device_id` dan secara eksplisit bukan `chat_id`. `chat_id` juga tidak '
                        .'dipakai sebagai filter query di package ini. Sisa masalahnya murni pilihan: '
                        .'blind index yang bisa dicari-tapi-tidak-baca, atau biarkan plaintext dengan alasan '
                        .'yang dicatat. Rinciannya di `docs/wara/TODO.md` P2.',
                ],
                [
                    'label' => 'Endpoint `verified` → `consumed`',
                    'status' => 'open',
                    'note' => 'Milik `bale/srikandi`, bukan `bale/wara`. Tercatat di halaman status package itu.',
                ],
                [
                    'label' => 'Route `notifikasi-naskah` belum ada',
                    'status' => 'open',
                    'note' => 'Rencana purpose-nya sudah disepakati, tapi `bale/srikandi` belum punya notifikasi '
                        .'naskah sama sekali — tidak ada `Notification`, tidak ada `Wara::`. Route-nya baru '
                        .'dibuat saat notifikasinya benar-benar diimplementasikan, karena device tujuan '
                        .'belum ada artinya dan menebaknya berarti pesan keluar dari nomor yang salah.',
                ],
            ],
        ],

        [
            'title' => 'Pekerjaan lintas package yang sedang jalan',
            'items' => [
                [
                    'label' => 'Scraper `rak-srikandi` harus adaptasi payload naskah',
                    'status' => 'open',
                    'meta' => 'breaking change',
                    'note' => 'Endpoint `POST /naskah-dinas` tidak lagi punya kolom organisasi di payload — '
                        .'scraper harus mengirim `{ items }` saja, `RAKACA_ACCOUNT_ID` dihapus. '
                        .'🔴 `account_id` yang masih dikirim TIDAK akan ditolak: field itu diabaikan diam-diam '
                        .'karena tidak ada aturan validasi yang menyebut namanya. Jadi scraper bisa '
                        .'mengira semuanya baik padahal Bale sudah tidak menyimpan scoping apa pun.',
                ],
                [
                    'label' => 'Mesin OTP dipertahankan, bukan di-retire',
                    'status' => 'done',
                    'note' => 'Putusan 3 Okt 2026. `OtpService` dan endpoint `otp-request`/`otp-pending`/'
                        .'`otp-verify` TETAP ADA karena `rak-srikandi` masih memakainya sebagai jalur '
                        .'cadangan login. Menghapusnya akan memutus fallback tanpa menyentuh scraper. '
                        .'🔴 Catatan: itu semua milik `bale/srikandi` (`packages/srikandi/routes/api.php`), '
                        .'bukan `bale/wara`. Dicatat di halaman ini karena keputusannya menyangkut gateway, '
                        .'bukan karena ada kodenya di package ini.',
                ],
                [
                    'label' => 'Client harus dipetakan manual setelah install',
                    'status' => 'open',
                    'note' => 'Client `Srikandi (Package)` dibuat oleh `srikandi:install` dengan route KOSONG. '
                        .'Admin yang memetakan `purpose` → device di `Wara > Client`. Install tidak bisa '
                        .'menebak device yang benar — device yang ditebak berarti pesan keluar dari nomor '
                        .'yang tidak diminta siapa pun.',
                ],
                [
                    'label' => '`wara:install` tidak membuat client maupun route',
                    'status' => 'open',
                    'note' => '🔴 `wara:install` hanya men-seed 7 permission — nol baris `WaraClient`, nol route. '
                        .'Konsekuensinya harus terlihat: instalasi Wara tanpa `bale/srikandi` '
                        .'berarti nol client, nol route, dan gateway tidak bisa dipakai. Daftar client harus '
                        .'dibuat manual lewat `Wara > Client` saat belum ada package lain yang memilikinya.',
                ],
            ],
        ],

    ],

];
