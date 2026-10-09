<?php

namespace Bale\Wara\Channels;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kanal notifikasi Slack melalui incoming webhook (§8.3).
 *
 * Pemakaian:
 *
 * ```php
 * Notification::route('slack', 'https://hooks.slack.com/services/...')
 *     ->notify(new DeviceDisconnected($state, $previous, $detectedAt, $session));
 * ```
 *
 * Alasan kanal ini ada di `bale/wara` (bukan memakai `laravel/slack`): Bale
 * cuma butuh satu bentuk — kirim JSON plaintext ke incoming webhook — dan
 * menolak menambahkan dependency satu package untuk itu. Payload diambil dari
 * `toSlack()` notifikasi (`attachments`, `color`, `fields`, dst) dan dikirim
 * apa adanya.
 *
 * Aturan yang sama dengan `WaraChannel` (PRD §13.3):
 *
 *  1. **Tidak melempar exception.** `NotificationSender::sendToNotifiable()`
 *     melempar ulang apa pun yang dilempar kanal, dan itu bisa membatalkan
 *     transaksi database pemanggil. Kegagalan dilaporkan lewat event
 *     `NotificationFailed`.
 *  2. Tidak me-retry sendiri — itu milik antrean Laravel (`ShouldQueue`).
 *  3. Config yang salah (matikan) dilaporkan, bukan dibiarkan gagal senyap.
 *
 * `enabled=false` (config `wara.slack.enabled`) membuat kanal jadi no-op tanpa
 * menyentuh jaringan — pemasangan webhook belum tentu ada di semua environment.
 */
class SlackChannel
{
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        if (! (bool) config('wara.slack.enabled')) {
            return null;
        }

        $url = $this->resolveWebhookUrl($notifiable, $notification);

        if ($url === null || $url === '') {
            // Webhook tidak tersedia = kesalahan konfigurasi notifikasi.
            $this->fail($notifiable, $notification, 'Webhook Slack tidak tersedia pada notifikasi.');

            return null;
        }

        $payload = $this->resolvePayload($notifiable, $notification);

        if ($payload === null) {
            $this->fail($notifiable, $notification, 'Notifikasi tidak menghasilkan payload Slack.');

            return null;
        }

        try {
            Http::post($url, $payload);
        } catch (Throwable $e) {
            $this->fail($notifiable, $notification, $e->getMessage(), $e);
        }

        return null;
    }

    /**
     * URL webhook tujuan.
     *
     * Diprioritaskan `toSlackWebhookUri()` (kontrol penuh dari sisi
     * notifikasi), lalu baru `routeNotificationFor('slack')` sebagai konvensi
     * bawaan — `Notification::route('slack', $url)` menyimpannya di sana.
     */
    protected function resolveWebhookUrl(mixed $notifiable, Notification $notification): ?string
    {
        if (method_exists($notification, 'toSlackWebhookUri')) {
            $url = $notification->toSlackWebhookUri($notifiable);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (method_exists($notifiable, 'routeNotificationFor')) {
            $url = $notifiable->routeNotificationFor('slack', $notification);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (is_string($notifiable) && $notifiable !== '') {
            return $notifiable;
        }

        return null;
    }

    /**
     * Payload JSON Slack.
     *
     * Didukung dua cara: `toSlack()` (disarankan — kontrol penuh struktur
     * attachment) atau `toArray()['slack']` (konvensi Laravel untuk kanal
     * non-teks).
     *
     * @return array<string, mixed>|null
     */
    protected function resolvePayload(mixed $notifiable, Notification $notification): ?array
    {
        if (method_exists($notification, 'toSlack')) {
            $payload = $notification->toSlack($notifiable);

            if (is_array($payload)) {
                return $payload;
            }
        }

        $payload = $notification->toArray($notifiable);

        if (isset($payload['slack']) && is_array($payload['slack'])) {
            return $payload['slack'];
        }

        return null;
    }

    /**
     * Laporkan kegagalan tanpa melempar — sama dengan `WaraChannel`.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function fail(
        mixed $notifiable,
        Notification $notification,
        string $reason,
        ?Throwable $exception = null,
        array $extra = [],
    ): void {
        Log::warning('SlackChannel: notifikasi gagal dikirim.', [
            'reason' => $reason,
            'notification' => $notification::class,
        ] + $extra);

        Event::dispatch(new NotificationFailed(
            $notifiable,
            $notification,
            'slack',
            array_filter([
                'reason' => $reason,
                'exception' => $exception,
            ], fn ($v) => $v !== null) + $extra,
        ));
    }
}
