<?php

use Bale\Wara\Channels\SlackChannel;
use Bale\Wara\Data\DeviceState;
use Bale\Wara\Events\WaraDeviceDisconnected;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    // Default: alert mati supaya test yang tidak relevan tidak memanggil
    // webhook. Test yang butuh Slack menyalakannya sendiri.
    config()->set('wara.slack.enabled', false);
    config()->set('wara.slack.webhook_url', 'https://hooks.slack.com/services/TEST/123');
});

function makeSlackDevice(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'device_'.$counter,
        'jid' => '6285126061'.$counter.'@s.whatsapp.net',
        'display_name' => 'Device Agent '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

/**
 * Fake gateway status untuk semua request ke gateway, plus webhook Slack.
 */
function fakeSlackGatewayStatus(string $state): void
{
    Http::fake([
        'gateway.test/*' => Http::response(['state' => $state], 200),
        'hooks.slack.com/*' => Http::response('ok', 200),
    ]);
}

/**
 * Body JSON dari seluruh request yang benar-benar sampai ke webhook Slack.
 *
 * @return array<int, array<string, mixed>>
 */
function slackAlertPayloads(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), 'hooks.slack.com'))
        ->map(fn ($pair) => json_decode((string) $pair[0]->body(), true))
        ->values()
        ->all();
}

function slackAlertCount(): int
{
    return count(slackAlertPayloads());
}

describe('registrasi kanal slack', function () {
    it('kanal "slack" terdaftar di ChannelManager', function () {
        expect(app(ChannelManager::class)->driver('slack'))
            ->toBeInstanceOf(SlackChannel::class);
    });

    it('Notification::route("slack", $url) mengirim JSON ke webhook', function () {
        config()->set('wara.slack.enabled', true);

        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        NotificationFacade::route('slack', 'https://hooks.slack.com/services/TEST/123')
            ->notify(new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return ['slack'];
                }

                public function toSlack(object $notifiable): array
                {
                    return ['text' => 'Notifikasi uji kanal Slack.'];
                }
            });

        expect(slackAlertCount())->toBe(1)
            ->and(slackAlertPayloads()[0]['text'])->toBe('Notifikasi uji kanal Slack.');
    });

    it('kanal no-op saat wara.slack.enabled=false', function () {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        NotificationFacade::route('slack', 'https://hooks.slack.com/services/TEST/123')
            ->notify(new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return ['slack'];
                }

                public function toSlack(object $notifiable): array
                {
                    return ['text' => 'harus tidak terkirim.'];
                }
            });

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'hooks.slack.com'));
    });
});

describe('alert saat device terputus (wara:poll-status)', function () {
    it('mengirim ke Slack saat device logged_in menjadi disconnected', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice();
        fakeSlackGatewayStatus('disconnected');

        $this->artisan('wara:poll-status')->assertFailed();

        expect(slackAlertCount())->toBe(1);

        $payload = slackAlertPayloads()[0];
        $card = $payload['attachments'][0];
        $body = $card['text'];

        expect($card['title'])->toContain('Device Agent 1')
            ->and($card['color'])->toBe('#dc2626')
            ->and($body)->toContain('Device *Device Agent 1*')
            ->and($body)->toContain('terputus')
            ->and($body)->toContain('pairing ulang')
            ->and($card['fields'][2]['title'])->toBe('Nomor (disamarkan)');
    });

    it('JID (nomor device) tidak pernah dikirim utuh ke Slack', function () {
        config()->set('wara.slack.enabled', true);

        $device = makeSlackDevice();
        fakeSlackGatewayStatus('disconnected');

        $this->artisan('wara:poll-status')->assertFailed();

        $slackRequest = collect(Http::recorded())
            ->first(fn ($pair) => str_contains((string) $pair[0]->url(), 'hooks.slack.com'));

        expect((string) $slackRequest[0]->body())
            ->not->toContain($device->jid)
            ->not->toContain('6285126061')
            // Tetap menyisakan jejak yang berguna untuk operator.
            ->toContain('62');
    });

    it('tidak mengirim ulang saat device sudah disconnected di poll berikutnya', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice();
        fakeSlackGatewayStatus('disconnected');

        $this->artisan('wara:poll-status')->assertFailed();
        $this->artisan('wara:poll-status')->assertFailed();

        expect(slackAlertCount())->toBe(1);
    });

    it('device yang sejak awal tidak siap tidak memicu alert', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice(['is_logged_in' => false, 'is_connected' => false]);
        fakeSlackGatewayStatus('disconnected');

        $this->artisan('wara:poll-status')->assertFailed();

        expect(slackAlertCount())->toBe(0);
    });

    it('alert tidak terkirim saat config wara.slack.enabled=false', function () {
        makeSlackDevice();
        fakeSlackGatewayStatus('disconnected');

        $this->artisan('wara:poll-status')->assertFailed();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'hooks.slack.com'));
    });

    it('memancarkan WaraDeviceDisconnected dengan state lama dan baru', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice();
        fakeSlackGatewayStatus('disconnected');

        Event::fake([WaraDeviceDisconnected::class]);

        $this->artisan('wara:poll-status')->assertFailed();

        Event::assertDispatched(
            WaraDeviceDisconnected::class,
            fn (WaraDeviceDisconnected $event) => $event->previous === DeviceState::LoggedIn
                && $event->state === DeviceState::Disconnected
        );
    });
});

describe('alert saat device hilang dari katalog (wara:sync-devices)', function () {
    it('device yang hilang ditandai offline dan memicu alert "hilang dari gateway"', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice(['device_id' => 'org_lama']);

        Http::fake([
            'gateway.test/*' => Http::response(['data' => [
                ['device_id' => 'org_baru', 'jid' => '6280088@s.whatsapp.net', 'display_name' => 'Device Baru', 'state' => 'logged_in'],
            ]], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        $orphan = WaraSession::query()->where('device_id', 'org_lama')->first();

        expect($orphan->is_present)->toBeFalse();

        expect(slackAlertCount())->toBe(1);

        $body = slackAlertPayloads()[0]['attachments'][0]['text'];

        expect($body)->toContain('hilang dari gateway')
            ->and($body)->toContain('org_lama');
    });

    it('device sehat yang tetap tampil di katalog tidak memicu alert', function () {
        config()->set('wara.slack.enabled', true);

        makeSlackDevice(['device_id' => 'org_sehat']);

        Http::fake([
            'gateway.test/*' => Http::response(['data' => [
                ['device_id' => 'org_sehat', 'jid' => '6280088@s.whatsapp.net', 'display_name' => 'Device Sehat', 'state' => 'logged_in'],
            ]], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'hooks.slack.com'));
    });
});
