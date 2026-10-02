<?php

namespace Bale\Wara\Data;

use Countable;
use Illuminate\Support\Collection;

/**
 * Katalog device hasil normalisasi.
 *
 * @implements \IteratorAggregate<int, DeviceInfo>
 */
final readonly class DeviceList implements \IteratorAggregate, Countable
{
    /**
     * @param  Collection<int, DeviceInfo>  $devices
     */
    public function __construct(
        private Collection $devices,
    ) {}

    /**
     * @return Collection<int, DeviceInfo>
     */
    public function all(): Collection
    {
        return $this->devices;
    }

    public function findBySessionId(string $sessionId): ?DeviceInfo
    {
        return $this->devices->first(fn (DeviceInfo $device) => $device->sessionId === $sessionId);
    }

    public function findByJid(string $jid): ?DeviceInfo
    {
        return $this->devices->first(fn (DeviceInfo $device) => $device->jid === $jid);
    }

    /**
     * @return array<int, DeviceInfo>
     */
    public function usable(): array
    {
        return $this->devices
            ->filter(fn (DeviceInfo $device) => $device->isUsable())
            ->values()
            ->all();
    }

    public function count(): int
    {
        return $this->devices->count();
    }

    public function getIterator(): \Traversable
    {
        return $this->devices->getIterator();
    }
}
