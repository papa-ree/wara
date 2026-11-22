<?php

namespace Bale\Wara\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipancarkan saat gateway mengirim bukti pesan keluar sampai atau dibaca.
 *
 * Consumer memakai `messageIds` untuk mencocokkan pesan yang pernah dikirim,
 * lalu menaikkan statusnya. Urutan `delivered` → `read` tidak boleh turun:
 * ack lama bisa datang terlambat.
 */
final class WaraMessageAck
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $messageIds  id pesan dari gateway
     * @param  string|null  $receiptType  `delivered`, `read`, `played`, `read-self`
     * @param  string|null  $chatId  chat mentah dari gateway
     * @param  string|null  $phone  nomor lawan bicara polos, dari `chat_id`
     */
    public function __construct(
        public readonly string $deviceId,
        public readonly array $messageIds,
        public readonly ?string $receiptType,
        public readonly ?string $chatId = null,
        public readonly ?string $phone = null,
    ) {}
}
