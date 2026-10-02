<?php

namespace Bale\Wara\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipancarkan setiap pesan masuk yang diterima webhook gateway (TOPIK §7).
 *
 * Inilah jalur masuk resmi `bale/wara` ke consumer **dalam proses yang sama**.
 * `bale/srikandi` memakainya untuk mencocokkan kode OTP balasan operator tanpa
 * perlu HTTP sama sekali.
 *
 * 🔴 `phone` dan `body` di sini adalah **teks polos** — hasil dekripsi cast
 * `encrypted` pada model, bukan ciphertext. Event ini dibuat dari payload
 * webhook, bukan dibaca balik dari `wara_logs`, jadi tidak ada biaya query.
 *
 * Kontrak ini dijaga ketat (TOPIK §7): menambah field baru boleh, mengubah
 * arti field yang ada tidak boleh — consumer lain sudah bergantung padanya.
 */
final class WaraIncomingMessage
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        /** Session ID device yang aktif, bukan JID. */
        public readonly string $deviceId,
        /** Nama event gateway: `message`, `message.ack`, ... */
        public readonly string $event,
        /** Id pesan dari gateway, NULL bila tidak ada. */
        public readonly ?string $messageId,
        /** Nomor pengirim polos (`62812...`), sufiks JID sudah dibuang. */
        public readonly ?string $phone,
        /** Chat ID mentah dari gateway, mis. `62812@s.whatsapp.net`. */
        public readonly ?string $chatId,
        /** Isi pesan teks polos. */
        public readonly ?string $body,
        /** JSON mentah event, untuk consumer yang butuh field tambahan. */
        public readonly string $rawBody,
        /** Waktu penerimaan menurut gateway. */
        public readonly ?string $receivedAt = null,
    ) {}

    /**
     * Group chat selalu berakhiran `@g.us`. Nomor pribadi tidak pernah berakhiran
     * seperti itu, jadi ini penanda yang bisa dipercaya untuk membedakan chat
     * 1:1 dari grup — `bale/srikandi` mengabaikannya supaya balasan OTP orang
     * lain di grup tidak ikut terverifikasi.
     */
    public function isGroupChat(): bool
    {
        return $this->chatId !== null
            && str_ends_with($this->chatId, '@g.us');
    }

    /**
     * Group chat || tanpa pengirim || tanpa isi pesan tidak bisa dicocokkan ke
     * satu nomor tertentu, jadi listener tidak perlu membacanya.
     */
    public function isAddressableOneToOne(): bool
    {
        return ! $this->isGroupChat()
            && $this->phone !== null
            && $this->phone !== ''
            && $this->body !== null
            && trim($this->body) !== '';
    }
}
