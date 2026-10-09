<?php

namespace Bale\Wara\Notifications;

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\SensitiveData;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi Slack saat device Wara terputus (§8.3).
 *
 * Dikirim lewat incoming webhook (kanal `slack`), diantrekan supaya pemanggil
 * (perintah polling/sync) tidak ikut menunggu HTTP ke Slack.
 *
 * 🔴 JID (nomor telepon device) TIDAK dikirim apa adanya — hanya versi
 * disamarkan lewat `SensitiveData::maskToken()`. Ini bukan untuk menyembunyikan
 * identitas device (nama + Session ID tetap tampil), tapi menjaga nomor lengkap
 * tidak tersebar ke channel Slack yang mungkin dibaca banyak orang.
 */
class DeviceDisconnected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** Keadaan BARU (pasca transisi) — `disconnected`, `connecting`, atau `connected`. */
        public DeviceState $state,
        /** Keadaan LAMA (harus `logged_in`) — null bila tidak diketahui. */
        public ?DeviceState $previous = null,
        /** Waktu deteksi transisi. */
        public ?Carbon $detectedAt = null,
        /** Session device yang menjadi tidak siap (keadaan BARU sudah tertulis). */
        public ?WaraSession $session = null,
    ) {
        $this->detectedAt ??= now();
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    /**
     * Payload Slack: `text` = baris utama, `attachments[0]` = kartu berisi
     * title dan body yang jelas bagi operator.
     *
     * @return array<string, mixed>
     */
    public function toSlack(object $notifiable): array
    {
        $name = $this->session?->display_name
            ?: $this->session?->device_id
            ?: '(tanpa nama)';

        $previousLabel = $this->stateLabel($this->previous);
        $stateLabel = $this->currentStateLabel();

        $lines = [
            sprintf('Device *%s* tidak lagi siap mengirim pesan.', $name),
            sprintf('Keadaan berubah dari *%s* ke *%s*.', $previousLabel, $stateLabel),
            sprintf('Session ID: `%s`', $this->session?->device_id ?? '—'),
            '',
            'GOWA tidak melaporkan alasan putusnya — ini belum berarti akun diblokir. Lakukan *pairing ulang* lewat halaman Device di panel Bale, lalu pantau kembali statusnya.',
        ];

        return [
            'text' => sprintf(':warning: *Device Wara terputus:* %s', $name),
            'attachments' => [
                [
                    'color' => '#dc2626',
                    'title' => sprintf('Device Wara terputus — %s', $name),
                    'text' => implode("\n", $lines),
                    'fields' => [
                        ['title' => 'Device', 'value' => $name, 'short' => true],
                        ['title' => 'Session ID', 'value' => $this->session?->device_id ?? '—', 'short' => true],
                        ['title' => 'Nomor (disamarkan)', 'value' => $this->maskedNumber($this->session?->jid) ?: '—', 'short' => true],
                        ['title' => 'Keadaan', 'value' => sprintf('%s → %s', $previousLabel, $stateLabel), 'short' => true],
                        ['title' => 'Terdeteksi', 'value' => $this->detectedAt->toDateTimeString(), 'short' => false],
                    ],
                    'footer' => 'bale/wara',
                    'ts' => $this->detectedAt->getTimestamp(),
                ],
            ],
        ];
    }

    /**
     * Label keadaan BARU. Bedanya dengan `state` yang dikirim: device yang
     * hilang dari katalog gateway ditulis `is_present=false`, dan itu lebih
     * jelas dibaca operator sebagai "hilang" daripada "terputus".
     */
    protected function currentStateLabel(): string
    {
        if ($this->session !== null && ! $this->session->is_present) {
            return 'hilang dari gateway';
        }

        return $this->stateLabel($this->state);
    }

    protected function stateLabel(?DeviceState $state): string
    {
        return match ($state) {
            DeviceState::LoggedIn => 'siap',
            DeviceState::Connected => 'terhubung, belum login',
            DeviceState::Connecting => 'menghubungkan ulang',
            DeviceState::Disconnected => 'terputus',
            default => 'tidak dikenal',
        };
    }

    /**
     * Nomor device disamarkan: JID dibuang sufiks `@s.whatsapp.net`, lalu
     * `SensitiveData::maskToken()` menyisakan 2 karakter awal dan akhir.
     */
    protected function maskedNumber(?string $jid): string
    {
        if ($jid === null || $jid === '') {
            return '';
        }

        $digits = preg_replace('/\D/', '', $jid);

        if ($digits === null || $digits === '') {
            return '';
        }

        return SensitiveData::maskToken($digits);
    }
}
