<?php

namespace Bale\Wara\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipancarkan setiap kali satu pesan berhasil dikirim ke gateway (PRD §13.4).
 *
 * Tujuannya supaya package lain — terutama `bale/srikandi` — bisa mengaitkan
 * balasan yang masuk dengan pesan asalnya.
 *
 * 🔴 Isi pesan **sengaja tidak** ada di sini. `bale/wara` tidak tahu APA yang
 * dikirim ke SIAPA; itu urusan package pengirim. Kalau butuh korelasi, simpan
 * penautannya di sisi pengirim dan pakai `destination` sebagai kuncinya.
 */
final class WaraMessageSent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Nomor tujuan polos (`62812...`), tanpa sufiks JID. */
        public readonly string $destination,
        /** Session ID device yang dipakai, bukan JID. */
        public readonly string $deviceId,
        /** Id pesan dari gateway, NULL bila gateway tidak mengembalikannya. */
        public readonly ?string $messageId,
        /** Selisih waktu dari mulai kirim sampai respons gateway. */
        public readonly int $latencyMs,
        /** Kelas notifikasi pemicu, NULL untuk pemanggilan internal. */
        public readonly ?string $notification = null,
    ) {}
}
