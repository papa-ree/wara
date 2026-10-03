<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detail satu device: tes koneksi ke gateway.
 *
 * 🔴 DULUNYA HALAMAN INI ADALAH FORM PENUGASAN `purpose`. Sekarang
 * penugasan itu pindah ke `Wara > Client`, karena `purpose` tidak lagi
 * menempel pada device.
 *
 * Alasannya struktural, bukan kosmetik: `wara_sessions.purpose` yang unik
 * global membuat satu device hanya bisa melayani satu purpose di seluruh
 * sistem. Begitu ada dua client, keduanya berebut device yang sama dan tidak
 * ada cara tahu siapa berhak atas apa. Penugasan jadi milik
 * `(client, purpose)`, jadi harus punya UI sendiri - bukan lagi di halaman
 * device yang sekarang hanya menampilkan status.
 */
#[Layout('rakaca::layouts.app')]
#[Title('Detail Device')]
class Form extends Component
{
    use AuthorizesRequests;

    public WaraSession $session;

    public function mount(WaraSession $session): void
    {
        $this->authorize('wara.device.update');

        $this->session = $session;
    }

    /**
     * Route yang memakai device ini, supaya admin tahu device itu melayani
     * client apa saja.
     *
     * @return array<int, array{purpose: string, client: string}>
     */
    public function getRoutesProperty(): array
    {
        return $this->session
            ->routes()
            ->with('client:id,name')
            ->get()
            ->map(fn ($route): array => [
                'purpose' => (string) $route->purpose,
                'client' => (string) ($route->client?->name ?? '(client terhapus)'),
            ])
            ->all();
    }

    /**
     * Tes koneksi ke gateway dan segarkan status device ini.
     *
     * Dipakai sebagai JID, bukan Session ID: JID yang dilihat admin di halaman
     * ini adalah identitas yang sama dengan yang dipakai di config
     * `default_device_id` (PRD §6.3.2).
     *
     * Status yang terambil hanya disimpan di database lokal. Penugasan route
     * TIDAK pernah disentuh - itu keputusan admin di halaman Client dan tidak
     * boleh berubah gara-gara pengujian koneksi.
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
            // Gateway down adalah kondisi sementara, bukan device yang salah -
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
