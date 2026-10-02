<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Formulir penugasan `purpose` untuk satu device.
 *
 * Ini satu-satunya jalur yang boleh mengisi kolom `purpose` (PRD §7). Kolomnya
 * sengaja tidak ada di `$fillable` model, jadi penulisan harus lewat sini
 * memakai forceFill.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Tugaskan Device')]
class Form extends Component
{
    use AuthorizesRequests;

    public WaraSession $session;

    public ?string $purpose = null;

    public function mount(WaraSession $session): void
    {
        $this->authorize('wara.device.update');

        $this->session = $session;
        $this->purpose = $session->purpose;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'purpose' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
        ]);

        try {
            // forceFill: purpose di luar $fillable (PRD §7).
            $this->session->forceFill([
                'purpose' => $validated['purpose'] ?: null,
            ])->save();
        } catch (QueryException $e) {
            // Index unique pada `purpose` catching assignment ganda.
            $this->addError('purpose', __(
                'Purpose ini sudah dilayani device lain. Satu purpose hanya boleh dilayani satu device.'
            ));

            return;
        }

        $this->dispatch('toast', message: __('Penugasan device berhasil disimpan.'), type: 'success');
        $this->redirect(route('wara.device.index'), navigate: true);
    }

    /**
     * Tes koneksi ke gateway dan segarkan status device ini.
     *
     * Dipakai sebagai JID, bukan Session ID: JID yang dilihat admin di halaman
     * ini adalah identitas yang sama dengan yang dipakai di config
     * `default_device_id` (PRD §6.3.2).
     *
     * Status yang terambil hanya disimpan di database lokal. `purpose` TIDAK
     * pernah disentuh — itu keputusan admin dan tidak boleh berubah gara-gara
     * pengujian koneksi.
     */
    public function testConnection(): void
    {
        $jid = trim((string) $this->session->jid);

        if ($jid === '') {
            $this->dispatch(
                'toast',
                message: __('Device ini belum punya JID. Pasangkan dulu di dashboard gateway.'),
                type: 'error'
            );

            return;
        }

        try {
            $catalog = app(WaraManager::class)->listDevices();
        } catch (GatewayException $e) {
            // Gateway down adalah kondisi sementara, bukan device yang salah —
            // pesannya dibedakan supaya admin tidak salah mendiagnosis.
            $this->dispatch(
                'toast',
                message: __('Tidak bisa menghubungi gateway: :message', ['message' => $e->getMessage()]),
                type: 'error'
            );

            return;
        }

        $remote = $catalog->findByJid($jid);

        if ($remote === null) {
            $this->dispatch('toast', message: sprintf(
                __('JID %s tidak ditemukan di gateway. Jalankan: php artisan wara:sync-devices'),
                $jid
            ), type: 'error');

            return;
        }

        $this->session->forceFill([
            'display_name' => $remote->displayName ?? $this->session->display_name,
            'is_present' => true,
            'is_connected' => $remote->state !== DeviceState::Disconnected,
            'is_logged_in' => $remote->state->isUsable(),
            'last_seen_at' => now(),
        ])->save();

        $this->session->refresh();

        $this->dispatch('toast', type: $this->session->isSendable() ? 'success' : 'warning', message: sprintf(
            __('Koneksi berhasil. Status saat ini: %s.'),
            $this->session->state_label
        ));
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.form');
    }
}
