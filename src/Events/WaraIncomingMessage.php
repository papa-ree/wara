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
        /**
         * Media yang menempel pada pesan, sudah dinormalisasi:
         * `type` (image|video|audio|document|sticker), `filename`, `mime`,
         * `size`, `caption`. NULL bila pesan teks biasa.
         *
         * @var array{type: string, filename: ?string, caption: ?string, path: ?string, url: ?string}|null
         */
        public readonly ?array $media = null,
        /**
         * TRUE bila pesan dikirim dari device ini sendiri (mis. dari HP), bukan
         * oleh lawan bicara. GOWA tetap memancarkan event `message` untuk pesan
         * itu, dengan `from` = nomor sendiri.
         */
        public readonly bool $fromMe = false,
        /** Id gateway pesan yang dikutip pesan ini, NULL bila bukan balasan. */
        public readonly ?string $repliedToId = null,
        /** Teks kutipan menurut gateway, sudah dipotong pengirim. */
        public readonly ?string $quotedBody = null,
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
     *
     * Media dihitung sebagai isi: gambar tanpa caption tetap milik satu nomor.
     */
    public function isAddressableOneToOne(): bool
    {
        return ! $this->isGroupChat()
            && ! $this->fromMe
            && $this->phone !== null
            && $this->phone !== ''
            && ($this->hasMedia() || ($this->body !== null && trim($this->body) !== ''));
    }

    public function isFromMe(): bool
    {
        return $this->fromMe;
    }

    /**
     * Nomor lawan bicara menurut `chat_id`, bukan `from`.
     *
     * `from` adalah pengirim: pada pesan yang dikirim dari HP, nilainya nomor
     * sendiri sementara lawan bicaranya ada di `chat_id`. Percakapan harus
     * mengikuti `chat_id`, kalau tidak pesan ke banyak kontak menumpuk di satu
     * chat "nomor sendiri".
     *
     * NULL untuk grup, `@bot`, `@newsletter`, `@lid`, dan chat tanpa nomor —
     * jenis itu tidak bisa dibalas sebagai chat 1:1 di v1.
     */
    public function chatPhone(): ?string
    {
        if ($this->chatId === null) {
            return null;
        }

        $at = strpos($this->chatId, '@');
        $user = $at === false ? $this->chatId : substr($this->chatId, 0, $at);
        $suffix = $at === false ? '' : strtolower(substr($this->chatId, $at));

        if ($suffix !== '' && $suffix !== '@s.whatsapp.net') {
            return null;
        }

        // JID multi-device membawa sufiks `:12`; itu bukan bagian nomor.
        $colon = strpos($user, ':');

        if ($colon !== false) {
            $user = substr($user, 0, $colon);
        }

        $user = trim($user);

        return $user === '' ? null : $user;
    }

    public function hasMedia(): bool
    {
        return $this->media !== null && ($this->media['type'] ?? '') !== '';
    }

    /**
     * Jenis media yang bisa diproses consumer (video/stiker ditunda).
     */
    public function mediaType(): ?string
    {
        return $this->hasMedia() ? (string) $this->media['type'] : null;
    }
}
