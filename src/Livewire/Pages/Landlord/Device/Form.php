<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device;

use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Data\DeviceState;
use Bale\Wara\Data\WebhookConfig;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Exceptions\WaraException;
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

    /**
     * State pairing. 🔴 Hanya properti Livewire, tidak pernah masuk DB:
     * QR dan kode kedaluwarsa dalam hitungan detik-menit, jadi menyimpannya
     * hanya membuat baris basi yang tidak ada yang membersihkan.
     */
    public ?string $qrImage = null;

    public int $qrExpiresIn = 0;

    public ?string $pairingCode = null;

    public string $loginPhone = '';

    /**
     * Form webhook. Nilainya dibaca dari gateway saat admin menekan "Muat".
     */
    public string $webhookUrl = '';

    /** Write-only: tidak pernah diisi dari gateway (§15.4). */
    public string $webhookSecret = '';

    public string $webhookEvents = '';

    public bool $webhookInsecureSkipVerify = false;

    public bool $webhookLoaded = false;

    public bool $webhookHasSecret = false;

    public function mount(WaraSession $session): void
    {
        $this->authorize('wara.device.update');

        $this->session = $session;
    }

    /**
     * Route yang memakai device ini, supaya admin tahu device itu melayani
     * client apa saja.
     *
     * 🔴 `outbound` ikut dibawa karena row view menandainya. Tanpa itu,
     * device yang dipetakan ke client tapi sudah dimatikan akan terlihat
     * sama persis dengan yang bisa mengirim - dan itu informasi yang
     * menentukan apakah orang salah mendiagnosis kegagalan.
     *
     * @return array<int, array{purpose: string, client: string, outbound: bool}>
     */
    public function getRoutesProperty(): array
    {
        return $this->session
            ->routes()
            ->with('client:id,name')
            ->get()
            ->map(fn ($route): array => [
                'purpose' => (string) $route->purpose,
                'client' => (string) ($route->client?->name ?? __('(client terhapus)')),
                'outbound' => (bool) $route->outbound_enabled,
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

    /**
     * Minta QR pairing. Gambar dikembalikan adapter sebagai data-URI, jadi
     * tidak ada host gateway yang perlu dijangkau browser admin.
     */
    public function loginQr(): void
    {
        $this->authorize('wara.device.manage');

        $this->reset('pairingCode');

        try {
            $qr = app(WaraManager::class)->gateway()->loginQr($this->session->device_id);
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->qrImage = $qr->imageDataUri;
        $this->qrExpiresIn = max(0, $qr->expiresInSeconds);
    }

    /**
     * Minta kode pairing untuk dimasukkan di ponsel.
     */
    public function loginWithCode(): void
    {
        $this->authorize('wara.device.manage');

        $this->validate(
            ['loginPhone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{8,20}$/']],
            attributes: ['loginPhone' => __('nomor perangkat')],
        );

        $this->reset('qrImage');

        try {
            $pairing = app(WaraManager::class)->gateway()->loginWithCode(
                $this->session->device_id,
                ltrim($this->loginPhone, '+'),
            );
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->pairingCode = $pairing->code;
    }

    /**
     * Dipanggil `wire:poll` selama QR/kode tampil: begitu device `logged_in`,
     * tampilan pairing ditutup supaya admin tidak menunggu sesuatu yang sudah
     * selesai.
     */
    public function refreshLoginStatus(): void
    {
        if ($this->qrImage === null && $this->pairingCode === null) {
            return;
        }

        $this->authorize('wara.device.manage');

        try {
            app(WaraManager::class)->pollDeviceStatus($this->session);
        } catch (WaraException) {
            // Gateway sedang tidak bisa dihubungi; QR tetap tampil dan poll
            // berikutnya mencoba lagi. Bukan alasan menampilkan error tiap
            // lima detik.
            return;
        }

        $this->session->refresh();

        if (! $this->session->isSendable()) {
            return;
        }

        $this->reset('qrImage', 'pairingCode');

        $this->dispatch('toast', type: 'success', message: __('Device terhubung dan siap mengirim.'));
    }

    public function reconnect(): void
    {
        $this->authorize('wara.device.manage');

        $this->callGatewayAction(
            fn (WaraGateway $gateway) => $gateway->reconnect($this->session->device_id),
            __('Permintaan reconnect dikirim ke gateway.'),
        );
    }

    public function logout(): void
    {
        $this->authorize('wara.device.manage');

        $this->callGatewayAction(
            fn (WaraGateway $gateway) => $gateway->logout($this->session->device_id),
            __('Device logout. Slot tetap ada dan bisa dipasangkan ulang.'),
        );
    }

    /**
     * Hapus slot dari gateway sekaligus dari katalog lokal.
     *
     * 🔴 Guardrail keputusan #32: DITOLAK selama `wara_routes` masih merujuk
     * device ini. Menghapus device yang masih dipakai client akan membuat
     * route menunjuk identitas yang tidak ada - dan kegagalannya baru terlihat
     * saat pesan berikutnya gagal terkirim. `wara_logs` historis TIDAK
     * menghalangi: log memang arsip, bukan referensi hidup.
     */
    public function removeDevice(): void
    {
        $this->authorize('wara.device.manage');

        if ($this->session->routes()->exists()) {
            $this->dispatch('toast', type: 'error', message: __(
                'Device masih dipakai client. Lepas pemetaannya di Wara > Client lebih dulu.'
            ));

            return;
        }

        try {
            app(WaraManager::class)->gateway()->removeDevice($this->session->device_id);
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->session->delete();

        $this->redirectRoute('wara.device.index', navigate: true);
    }

    /**
     * Baca konfigurasi webhook dari gateway. Tidak dipanggil saat mount:
     * halaman detail harus tetap terbuka walau gateway sedang mati.
     */
    public function loadWebhook(): void
    {
        $this->authorize('wara.device.manage');

        try {
            $config = app(WaraManager::class)->gateway()->getWebhook($this->session->device_id);
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->webhookUrl = $config->url ?? '';
        $this->webhookHasSecret = $config->hasSecret();
        $this->webhookEvents = implode(',', $config->events);
        $this->webhookInsecureSkipVerify = $config->insecureSkipVerify;
        $this->webhookLoaded = true;

        // 🔴 `webhookSecret` sengaja TIDAK diisi dari gateway. Properti publik
        // Livewire ikut diserialisasi ke browser, jadi menyalinnya di sini
        // membocorkan secret ke HTML - persis yang dilarang §15.4.
        $this->webhookSecret = '';
    }

    public function saveWebhook(): void
    {
        $this->authorize('wara.device.manage');

        $this->validate(
            [
                'webhookUrl' => ['nullable', 'url', 'max:2048'],
                'webhookSecret' => ['nullable', 'string', 'max:255'],
                'webhookEvents' => ['nullable', 'string', 'max:500'],
                'webhookInsecureSkipVerify' => ['boolean'],
            ],
            attributes: [
                'webhookUrl' => __('URL webhook'),
                'webhookSecret' => __('secret'),
                'webhookEvents' => __('event'),
            ],
        );

        $events = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->webhookEvents)
        )));

        // GOWA mengosongkan URL device bila dikirim string kosong, dan device
        // langsung jatuh ke webhook global. Tanpa nilai hasil "Muat", simpan
        // akan menghapus webhook yang sedang dipakai — pesan masuk berhenti
        // padahal kirim tetap jalan.
        if (! $this->webhookLoaded && ! filled($this->webhookUrl)) {
            $this->dispatch('toast', type: 'error', message: __('Muat konfigurasi webhook dulu supaya URL tidak terhapus.'));

            return;
        }

        try {
            $saved = app(WaraManager::class)->gateway()->setWebhook(
                $this->session->device_id,
                new WebhookConfig(
                    url: filled($this->webhookUrl) ? $this->webhookUrl : null,
                    secret: filled($this->webhookSecret) ? $this->webhookSecret : null,
                    events: $events,
                    insecureSkipVerify: $this->webhookInsecureSkipVerify,
                ),
            );
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->webhookHasSecret = $saved->hasSecret();
        $this->webhookSecret = '';
        $this->webhookLoaded = true;

        $this->dispatch('toast', type: 'success', message: __('Konfigurasi webhook device disimpan.'));
    }

    /**
     * @param  callable(WaraGateway): void  $action
     */
    protected function callGatewayAction(callable $action, string $successMessage): void
    {
        try {
            $action(app(WaraManager::class)->gateway());
        } catch (WaraException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: $successMessage);
        $this->dispatch('refresh-device-table');
    }

    public function render()
    {
        // 🔴 `routes` diteruskan EKSPLISIT, bukan mengandalkan computed
        // property Livewire.
        //
        // Computed property hanya tersedia sebagai `$this->routes`. View yang
        // menulis `$routes` berakhir dengan `Undefined variable $routes` -
        // dan error itu baru muncul saat halaman dirender, bukan saat test
        // lain yang memakai komponen ini lewat `mount()` saja.
        return view('wara::livewire.pages.landlord.device.form', [
            'routes' => $this->routes,
        ]);
    }
}
