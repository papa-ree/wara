<?php

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Jobs\DeliverToConsumer;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.webhook_secret', 'rahasia-webhook');
    config()->set('wara.default_device_id', '');
});

function signBody(string $body, ?string $secret = 'rahasia-webhook'): string
{
    return 'sha256='.hash_hmac('sha256', $body, (string) $secret);
}

/**
 * 🔴 Endpoint NYATA untuk device `wago prod`.
 *
 * Dulu test ini memakai `config()->set('wara.consumers', ...)`. Sekarang
 * endpoint-nya hidup di `wara_webhook_endpoints`, dan kelompok yang menerima
 * event ditentukan oleh ROUTE `device + purpose` yang punya
 * `inbound_webhook_enabled = true`.
 *
 * `secret` sengaja di luar `$fillable` dan ditulis lewat `forceFill()` - sama
 * seperti di form admin.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeFanOutEndpointWebhookTest(array $overrides = []): WaraWebhookEndpoint
{
    $client = WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client webhook '.uniqid(),
        'is_active' => true,
    ]);

    $route = new WaraRoute;
    $route->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => 'notifikasi',
        'device_id' => 'wago prod',
        'inbound_webhook_enabled' => array_key_exists('inbound', $overrides)
            ? $overrides['inbound']
            : true,
    ])->save();

    $endpoint = new WaraWebhookEndpoint;
    $endpoint->forceFill(array_merge([
        'client_id' => $client->getKey(),
        'url' => 'https://srikandi.test/webhook',
        'events' => ['message'],
        'enabled' => true,
    ], $overrides));

    $endpoint->forceFill(['secret' => 'secret-srikandi-yang-cukup-panjang-32']);
    $endpoint->save();

    return $endpoint;
}

describe('verifikasi signature webhook (PRD §6.1)', function () {
    it('menerima signature yang cocok', function () {
        $body = json_encode(['event' => 'message', 'device_id' => 'org_2', 'payload' => []]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();
    });

    it('menolak signature yang salah dengan 403', function () {
        $body = json_encode(['event' => 'message', 'device_id' => 'org_2', 'payload' => []]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertForbidden();
    });

    it('menolak request tanpa header signature dengan 403', function () {
        $this->postJson('/api/wara/webhook', ['event' => 'message'])->assertForbidden();
    });

    it('menolak semua request saat secret kosong (receiver dinonaktifkan)', function () {
        config()->set('wara.webhook_secret', '');

        $body = json_encode(['event' => 'message']);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body, ''),
        ])->assertForbidden();
    });
});

describe('pencatatan event (PRD §6.2)', function () {
    it('mencatat pesan masuk ke wara_logs dengan direction in', function () {
        $body = json_encode([
            'event' => 'message',
            'device_id' => 'org_2',
            'payload' => [
                'id' => 'msg-1',
                'from' => '6281234567890@s.whatsapp.net',
                'chat_id' => '628999888777@s.whatsapp.net',
                'body' => 'Kode OTP saya 123456',
            ],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        $log = WaraLog::query()->first();

        expect($log->direction)->toBe('in')
            ->and($log->event)->toBe('message')
            ->and($log->device_id)->toBe('org_2')
            ->and($log->phone)->toBe('6281234567890')
            ->and($log->chat_id)->toBe('628999888777@s.whatsapp.net')
            ->and($log->message_id)->toBe('msg-1')
            ->and($log->body)->toBe('Kode OTP saya 123456');
    });

    it('mencatat message.ack beserta receipt type', function () {
        $body = json_encode([
            'event' => 'message.ack',
            'device_id' => 'org_2',
            'payload' => ['message_id' => 'msg-1', 'receipt_type' => 'delivered'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        $log = WaraLog::query()->first();

        expect($log->event)->toBe('message.ack')
            ->and($log->status)->toBe('delivered')
            ->and($log->message_id)->toBe('msg-1');
    });

    it('mencatat event lain lalu mengabaikan logikanya', function () {
        $body = json_encode([
            'event' => 'reaction',
            'device_id' => 'org_2',
            'payload' => ['emoji' => '👍'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        $log = WaraLog::query()->first();

        expect($log->event)->toBe('reaction')
            ->and($log->status)->toBe('ignored');
    });
});

describe('fan-out dari receiver (PRD §12.3)', function () {
    it('mengantrekan job ke consumer aktif dan tetap balas 200', function () {
        Queue::fake();

        $endpoint = makeFanOutEndpointWebhookTest();

        $body = json_encode([
            'event' => 'message',
            'device_id' => 'wago prod',
            'payload' => ['id' => 'wamid.1', 'body' => 'Kode OTP saya 123456'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        Queue::assertPushed(DeliverToConsumer::class, function (DeliverToConsumer $job) {
            return $job->event === 'message'
                && $job->event === 'message'
                && $job->deviceId === 'wago prod'
                && $job->messageId === 'wamid.1';
        });
    });

    it('tidak mengantrekan apa pun saat semua consumer disabled', function () {
        Queue::fake();

        makeFanOutEndpointWebhookTest(['enabled' => false]);

        $body = json_encode(['event' => 'message', 'device_id' => 'wago prod', 'payload' => []]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        // 🔴 Hanya job fan-out yang relevan di sini.
        //
        // `assertNothingPushed()` terlalu luas: membuat client/route/endpoint
        // juga mengantrekan `LogActivityJob` dari trait `LogsActivity`, dan
        // itu bukan bagian dari perilaku yang sedang diuji.
        Queue::assertNotPushed(DeliverToConsumer::class);
    });

    it('tetap membalas 200 dan menyimpan wara_logs walau antrean gagal', function () {
        // Paksa enqueue meledak supaya terlihat apakah bukti inbound hilang.
        // Ini skenario nyata: broker mati, atau serialisasi job gagal.
        $exploded = false;

        // 🔴 Endpoint dibuat DULUAN, sebelum `Queue::before` dipasang.
        //
        // `Queue::before` meledak untuk APA SAJA job yang diantrekan, termasuk
        // `LogActivityJob` dari trait `LogsActivity` saat model disimpan. Kalau
        // listener-nya dipasang lebih dulu, test ini akan gagal karena alasan
        // yang salah.totalnya - bukan karena fan-out.
        $endpoint = makeFanOutEndpointWebhookTest();

        Queue::before(function () use (&$exploded) {
            $exploded = true;

            throw new RuntimeException('queue down');
        });

        $body = json_encode([
            'event' => 'message',
            'device_id' => 'wago prod',
            'payload' => ['id' => 'wamid.2', 'body' => 'halo'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        // Guard: pastikan jalur kegagalan benar-benar dieksekusi. Tanpa ini,
        // test ini akan tetap hijau walau try/catch di controller dihapus.
        expect($exploded)->toBeTrue('Queue::before tidak terpanggil, test ini tidak menguji apa pun');

        // Bukti inbound harus tetap ada walau delivery gagal mengantre.
        expect(WaraLog::query()->where('direction', 'in')->count())->toBe(1);
    });

    it('hanya mengantrekan event yang di-subscribe consumer', function () {
        Queue::fake();

        makeFanOutEndpointWebhookTest(['events' => ['message.ack']]);

        $body = json_encode([
            'event' => 'message',
            'device_id' => 'wago prod',
            'payload' => ['id' => 'wamid.3', 'body' => 'halo'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])->assertOk();

        // 🔴 Hanya job fan-out yang relevan di sini.
        //
        // `assertNothingPushed()` terlalu luas: membuat client/route/endpoint
        // juga mengantrekan `LogActivityJob` dari trait `LogsActivity`, dan
        // itu bukan bagian dari perilaku yang sedang diuji.
        Queue::assertNotPushed(DeliverToConsumer::class);
    });
});

describe('DeviceState', function () {
    it('hanya logged_in yang dianggap siap', function () {
        expect(DeviceState::LoggedIn->isUsable())->toBeTrue()
            ->and(DeviceState::Connected->isUsable())->toBeFalse()
            ->and(DeviceState::Connecting->isUsable())->toBeFalse()
            ->and(DeviceState::Disconnected->isUsable())->toBeFalse();
    });

    it('menandai disconnected dan connecting sebagai perlu perhatian', function () {
        expect(DeviceState::Disconnected->needsAttention())->toBeTrue()
            ->and(DeviceState::Connecting->needsAttention())->toBeTrue()
            ->and(DeviceState::Connected->needsAttention())->toBeFalse()
            ->and(DeviceState::LoggedIn->needsAttention())->toBeFalse();
    });
});
