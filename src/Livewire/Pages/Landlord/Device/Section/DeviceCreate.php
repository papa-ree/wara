<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device\Section;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Tambah slot device baru dari `Wara > Device` (PRD §15.2).
 *
 * 🔴 Tanpa komponen ini, prasyarat paling dasar package - device `logged_in`
 * (§6.5.2) - hanya bisa dipenuhi dari dashboard GOWA.
 *
 * Slot dibuat kosong (belum pairing). Pairing QR/kode dilakukan di halaman
 * detail device, karena di situlah statusnya terlihat.
 */
class DeviceCreate extends Component
{
    use AuthorizesRequests;

    public string $deviceId = '';

    public function mount(): void
    {
        $this->authorize('wara.device.manage');
    }

    public function save(WaraManager $wara): void
    {
        $this->authorize('wara.device.manage');

        $this->validate(
            ['deviceId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9 _\-]+$/']],
            attributes: ['deviceId' => __('nama slot')],
        );

        $name = trim($this->deviceId);

        try {
            $device = $wara->gateway()->addDevice($name === '' ? null : $name);
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        if ($device->sessionId === '') {
            $this->dispatch('toast', type: 'error', message: __('Gateway tidak mengembalikan identitas slot.'));

            return;
        }

        // 🔴 Cermin lokal satu device, bukan `syncDevices()`. Katalog gateway
        // bisa belum memuat slot yang baru dibuat, dan sinkron penuh juga
        // menyentuh device lain - padahal di sini yang berubah hanya satu baris.
        WaraSession::query()->updateOrCreate(
            ['device_id' => $device->sessionId],
            [
                'jid' => $device->jid,
                'display_name' => $device->displayName,
                'is_present' => true,
                'is_connected' => $device->state !== DeviceState::Disconnected,
                'is_logged_in' => $device->state->isUsable(),
                'last_seen_at' => now(),
            ],
        );

        $this->reset('deviceId');

        $this->dispatch('toast', type: 'success', message: __(
            'Slot :name ditambahkan. Lanjutkan pairing dari halaman detail device.',
            ['name' => $device->sessionId]
        ));

        $this->dispatch('refresh-device-table');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.section.device-create');
    }
}
