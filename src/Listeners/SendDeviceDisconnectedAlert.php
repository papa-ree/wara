<?php

namespace Bale\Wara\Listeners;

use Bale\Wara\Events\WaraDeviceDisconnected;
use Bale\Wara\Notifications\DeviceDisconnected;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Kirim notifikasi Slack saat device Wara terputus (§8.3).
 *
 * Guardi di sini cuma soal config (`enabled` + `webhook_url` bukan kosong).
 * Duplikat dicegah di *sumber* (WaraManager / SyncDevicesCommand), bukan di
 * listener: event hanya dipancarkan pada transisi `logged_in -> tidak siap`.
 */
class SendDeviceDisconnectedAlert
{
    public function handle(WaraDeviceDisconnected $event): void
    {
        if (! (bool) config('wara.slack.enabled')) {
            return;
        }

        $webhook = (string) config('wara.slack.webhook_url');

        if ($webhook === '') {
            Log::warning('Slack alert device Wara tidak terkirim: wara.slack.enabled=true tapi WARA_SLACK_WEBHOOK_URL kosong.', [
                'device_id' => $event->session->device_id,
                'state' => $event->state->value,
            ]);

            return;
        }

        Notification::route('slack', $webhook)
            ->notify(new DeviceDisconnected(
                state: $event->state,
                previous: $event->previous,
                detectedAt: $event->detectedAt,
                session: $event->session,
            ));
    }
}
