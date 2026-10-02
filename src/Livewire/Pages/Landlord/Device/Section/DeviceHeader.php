<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device\Section;

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeviceHeader extends Component
{
    public function syncNow(WaraManager $wara): void
    {
        try {
            $devices = $wara->listDevices();
        } catch (WaraException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', type: 'success', message: __(
            'Sinkronisasi selesai. :count device dilaporkan gateway.',
            ['count' => $devices->count()],
        ));

        $this->dispatch('refresh-device-table');
    }

    /**
     * Device yang perlu perhatian admin (§8.3): status buruk yang bertahan
     * melewati ambang `offline_alert_after_minutes`.
     */
    #[Computed]
    public function needsReattention()
    {
        return WaraSession::query()
            ->where('is_present', true)
            ->get()
            ->filter(fn (WaraSession $session) => $session->needsReattention())
            ->values();
    }

    /**
     * Device tidak siap tapi belum melewati ambang — masih dalam masa
     * tenggang, jadi ditampilkan sebagai informasi, bukan peringatan.
     */
    #[Computed]
    public function recentlyUnhealthy()
    {
        $threshold = (int) config('wara.offline_alert_after_minutes', 10);

        return WaraSession::query()
            ->where('is_present', true)
            ->where('is_logged_in', false)
            ->get()
            ->filter(fn (WaraSession $session) => ! $session->needsReattention())
            ->filter(function (WaraSession $session) use ($threshold) {
                // `last_seen_at` null = belum pernah ter-poll, bukan alarming.
                return $session->last_seen_at === null
                    || $session->last_seen_at->gte(now()->subMinutes($threshold));
            })
            ->values();
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.section.device-header');
    }
}
