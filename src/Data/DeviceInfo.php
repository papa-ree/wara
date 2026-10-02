<?php

namespace Bale\Wara\Data;

/**
 * Satu device dalam katalog gateway, sudah ternormalisasi.
 *
 * `sessionId` adalah identitas kanonik di sisi gateway (PRD §8) dan itulah
 * yang dikirim sebagai device id saat mengirim pesan. `jid` terpisah karena
 * nilainya kosong sampai device selesai pairing.
 */
final readonly class DeviceInfo
{
    public function __construct(
        public string $sessionId,
        public ?string $jid = null,
        public ?string $displayName = null,
        public DeviceState $state = DeviceState::Disconnected,
    ) {}

    /**
     * Device yang siap dipakai sebagai pengirim.
     */
    public function isUsable(): bool
    {
        return $this->state->isUsable() && filled($this->jid);
    }
}
