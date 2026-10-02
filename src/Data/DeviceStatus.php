<?php

namespace Bale\Wara\Data;

/**
 * Status satu device pada satu momen.
 */
final readonly class DeviceStatus
{
    public function __construct(
        public string $sessionId,
        public DeviceState $state,
        public ?string $jid = null,
    ) {}

    public function isUsable(): bool
    {
        return $this->state->isUsable() && filled($this->jid);
    }
}
