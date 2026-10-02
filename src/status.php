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
| terlihat, bukan Challenger jadi tebakan.
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

    'subtitle' => 'Pembungkus gateway WhatsApp (GOWA). Seluruh fase implementasi selesai; '
        .'sisa yang tercatat adalah persiapan produksi, bukan fitur yang belum dibangun.',

    'updated_at' => '2026-10-01',

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
                    'label' => 'Fase 4 — Migration, model, dashboard `purpose`',
                    'status' => 'done',
                    'meta' => 'PRD §7',
                    'note' => 'Kolom `purpose` sengaja tidak ada di `$fillable` — hanya admin yang boleh mengisinya.',
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
                    'label' => 'Fase 8 — Fan-out ke consumer',
                    'status' => 'done',
                    'meta' => 'PRD §12',
                    'note' => 'HMAC dihitung atas raw body byte-wise. Consumer Srikandi sengaja dibiarkan `enabled=false`.',
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
                    'status' => 'open',
                    'note' => '`wara:poll-status` sudah ada; status `disconnected`/`connecting` yang bertahan '
                        .'wajib muncul sebagai peringatan, bukan retry diam-diam.',
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
                    'note' => 'Keduanya masih plaintext; `phone` dan `body` sudah terenkripsi. '
                        .'Kendala: `chat_id` tidak bisa dipakai filter kalau dienkripsi, dan polling '
                        .'`bale/srikandi` bergantung padanya.',
                ],
                [
                    'label' => 'Endpoint `verified` → `consumed`',
                    'status' => 'open',
                    'note' => 'Milik `bale/srikandi`, bukan `bale/wara`. Tercatat di halaman status package itu.',
                ],
            ],
        ],

    ],

];
