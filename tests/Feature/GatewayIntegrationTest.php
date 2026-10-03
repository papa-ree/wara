<?php

use Bale\Wara\Data\DeviceState;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Provider di-boot lebih dulu supaya config tervalidasi, baru diubah.
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    $this->manager = app(WaraManager::class);
});

function makeSessionGatewayIntegrationTest(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    $session = WaraSession::query()->create(array_merge([
        'device_id' => 'org_auto_'.$counter,
        'jid' => '62851260611'.$counter.'@s.whatsapp.net',
        'display_name' => 'wago prod '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));

    // Jaring pengaman selalu diarahkan ke device yang dibuat test ini, supaya
    // baris §6.5.1 punya tujuan yang valid.
    config()->set('wara.default_device_id', $session->jid);

    return $session;
}

function makeClientGatewayIntegrationTest(): WaraClient
{
    return WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client gateway',
        'is_active' => true,
    ]);
}

function makeRouteGatewayIntegrationTest(WaraClient $client, string $deviceId): WaraRoute
{
    $route = new WaraRoute;

    $route->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => 'otp',
        'device_id' => $deviceId,
    ])->save();

    return $route;
}
describe('GowaGateway — header eksplisit (PRD §6.3)', function () {
    it('mengirim X-Device-Id pada setiap call kirim', function () {
        Http::fake(['gateway.test/*' => Http::response(['message_id' => 'msg-1'], 200)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', 'org_2'));
    });

    it('mengirim nomor tujuan dengan sufiks JID', function () {
        Http::fake(['gateway.test/*' => Http::response(['message_id' => 'msg-1'], 200)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        $this->manager->sendMessage('081999888777', 'halo', 'otp', 'org_2');

        Http::assertSent(fn ($request) => $request['phone'] === '6281999888777@s.whatsapp.net');
    });

    it('memakai basic auth dari WARA_API_KEY', function () {
        Http::fake(['gateway.test/*' => Http::response(['message_id' => 'msg-1'], 200)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2');

        Http::assertSent(fn ($request) => str_contains($request->header('Authorization')[0] ?? '', 'Basic '));
    });
});

describe('GowaGateway — device tidak ditemukan (PRD §6.5.3)', function () {
    it('DEVICE_NOT_FOUND diterjemahkan jadi DeviceNotFoundException', function () {
        Http::fake(['gateway.test/*' => Http::response(['code' => 'DEVICE_NOT_FOUND'], 404)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        expect(fn () => $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2'))
            ->toThrow(DeviceNotFoundException::class);
    });

    it('DEVICE_ID_REQUIRED dianggap bug sisi Bale, bukan kondisi device', function () {
        Http::fake(['gateway.test/*' => Http::response(['code' => 'DEVICE_ID_REQUIRED'], 400)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        expect(fn () => $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2'))
            ->toThrow(GatewayException::class, 'sudah mengirimnya');
    });

    it('HTTP error lain menjadi GatewayException dengan status', function () {
        Http::fake(['gateway.test/*' => Http::response('boom', 500)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        expect(fn () => $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2'))
            ->toThrow(GatewayException::class, 'HTTP 500');
    });

    it('body non-JSON ditolak dengan pesan jelas', function () {
        Http::fake(['gateway.test/*' => Http::response('<html>bukan json</html>', 200)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        expect(fn () => $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2'))
            ->toThrow(GatewayException::class, 'bukan JSON');
    });
});

describe('pencatatan pesan keluar (PRD §4, §7)', function () {
    it('mencatat wara_logs dengan direction out', function () {
        Http::fake(['gateway.test/*' => Http::response(['message_id' => 'msg-9'], 200)]);
        $session = makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        $result = $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2');

        $log = WaraLog::query()->first();

        expect($result->accepted)->toBeTrue()
            ->and($log->direction)->toBe('out')
            ->and($log->device_id)->toBe($session->device_id)
            ->and($log->message_id)->toBe('msg-9')
            ->and($log->body)->toBe('halo')
            ->and($log->status)->toBe('accepted');
    });

    it('gateway menolak -> tetap tercatat dengan status rejected', function () {
        Http::fake(['gateway.test/*' => Http::response(['code' => 'SOMETHING'], 422)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        // Terlempar, tapi log tetap harus ada untuk kebutuhan forensik.
        try {
            $this->manager->sendMessage('628999888777', 'halo', 'otp', 'org_2');
        } catch (GatewayException) {
            // diharapkan
        }

        $log = WaraLog::query()->first();

        expect($log)->not->toBeNull()
            ->and($log->direction)->toBe('out')
            ->and($log->status)->toBe('rejected');
    });

    it('sendOtp memakai template standar', function () {
        Http::fake(['gateway.test/*' => Http::response(['message_id' => 'msg-otp'], 200)]);
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        $this->manager->sendOtp('628999888777', '123456', 'otp', 'org_2');

        Http::assertSent(function ($request) {
            return str_contains($request['message'], '123456');
        });
    });

    it('nomor tujuan tidak valid ditolak sebelum menyentuh gateway', function () {
        Http::fake();
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        expect(fn () => $this->manager->sendMessage('bukan-nomor', 'halo', 'otp', 'org_2'))
            ->toThrow(WaraException::class);

        Http::assertNothingSent();
    });
});

describe('parsing katalog device', function () {
    it('membaca katalog dari wrapper results + field id (bentuk nyata GOWA)', function () {
        // Bentuk ini disalin apa adanya dari GET /devices GOWA 29 Sep 2026.
        Http::fake(['gateway.test/*' => Http::response([
            'code' => 'SUCCESS',
            'message' => 'List devices',
            'results' => [
                ['id' => 'Aduan Agent', 'display_name' => 'Papa', 'state' => 'disconnected', 'created_at' => '2026-09-29T13:42:43Z'],
                ['id' => 'wago prod', 'display_name' => 'Nawasara Agent', 'state' => 'logged_in', 'jid' => '6285126061182@s.whatsapp.net', 'created_at' => '2026-09-29T13:42:43Z'],
            ],
        ], 200)]);

        $devices = $this->manager->listDevices();

        expect($devices->count())->toBe(2)
            ->and($devices->findBySessionId('wago prod')?->jid)->toBe('6285126061182@s.whatsapp.net')
            ->and($devices->findBySessionId('wago prod')?->state)->toBe(DeviceState::LoggedIn)
            ->and($devices->findBySessionId('wago prod')?->displayName)->toBe('Nawasara Agent')
            ->and($devices->findBySessionId('Aduan Agent')?->state)->toBe(DeviceState::Disconnected)
            ->and($devices->findBySessionId('Aduan Agent')?->jid)->toBeNull()
            ->and(count($devices->usable()))->toBe(1);
    });

    it('membaca device dari field data', function () {
        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_2', 'jid' => '6285126061182@s.whatsapp.net', 'display_name' => 'wago prod', 'state' => 'logged_in'],
            ],
        ], 200)]);

        $devices = $this->manager->listDevices();

        expect($devices->count())->toBe(1)
            ->and($devices->findBySessionId('org_2')?->jid)->toBe('6285126061182@s.whatsapp.net')
            ->and($devices->findBySessionId('org_2')?->state)->toBe(DeviceState::LoggedIn);
    });

    it('device tanpa jid dianggap belum bisa dipakai', function () {
        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_new', 'jid' => null, 'state' => 'logged_in'],
            ],
        ], 200)]);

        $devices = $this->manager->listDevices();

        expect($devices->findBySessionId('org_new')?->isUsable())->toBeFalse();
    });

    it('membaca device dari array polos tanpa wrapper', function () {
        Http::fake(['gateway.test/*' => Http::response([
            ['device_id' => 'org_3', 'jid' => '6285@s.whatsapp.net', 'state' => 'connected'],
        ], 200)]);

        $devices = $this->manager->listDevices();

        expect($devices->count())->toBe(1)
            ->and($devices->findBySessionId('org_3')?->state)->toBe(DeviceState::Connected);
    });
});

describe('wara:sync-devices (PRD §7.1)', function () {
    it('menyimpan katalog device dari gateway', function () {
        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_2', 'jid' => '6285126061182@s.whatsapp.net', 'display_name' => 'wago prod', 'state' => 'logged_in'],
                ['device_id' => 'org_3', 'jid' => '628000000001@s.whatsapp.net', 'display_name' => 'Aduan Agent', 'state' => 'logged_in'],
            ],
        ], 200)]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        expect(WaraSession::query()->count())->toBe(2);

        $wagoProd = WaraSession::query()->where('device_id', 'org_2')->first();

        expect($wagoProd->display_name)->toBe('wago prod')
            ->and($wagoProd->is_logged_in)->toBeTrue()
            ->and($wagoProd->is_present)->toBeTrue()
            ->and($wagoProd->last_seen_at)->not->toBeNull();
    });

    it('device yang hilang ditandai offline, BUKAN dihapus', function () {
        $lama = makeSessionGatewayIntegrationTest(['device_id' => 'org_lama', 'jid' => '6285@s.whatsapp.net']);
        $client = makeClientGatewayIntegrationTest();
        $route = makeRouteGatewayIntegrationTest($client, 'org_lama');

        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_baru', 'jid' => '6286@s.whatsapp.net', 'state' => 'logged_in'],
            ],
        ], 200)]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        $lama->refresh();

        expect(WaraSession::query()->count())->toBe(2)
            ->and($lama->is_present)->toBeFalse()
            ->and($lama->is_logged_in)->toBeFalse()
            // Route milik client harus tetap utuh walau device-nya offline.
            ->and($route->fresh()->device_id)->toBe('org_lama');
    });

    it('sync tidak pernah menimpa route milik client', function () {
        $session = makeSessionGatewayIntegrationTest(['device_id' => 'org_2', 'jid' => '6285126061182@s.whatsapp.net', 'display_name' => 'nama lama']);
        $client = makeClientGatewayIntegrationTest();
        $route = makeRouteGatewayIntegrationTest($client, 'org_2');

        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_2', 'jid' => '6285126061182@s.whatsapp.net', 'display_name' => 'nama dari gateway', 'state' => 'logged_in'],
            ],
        ], 200)]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        $session->refresh();

        expect($session->display_name)->toBe('nama dari gateway')
            ->and($route->fresh()->device_id)->toBe('org_2');
    });

    it('gateway tidak dapat dihubungi tidak mengubah data lama', function () {
        $session = makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        Http::fake(['gateway.test/*' => Http::response('gateway down', 503)]);

        $this->artisan('wara:sync-devices')->assertFailed();

        expect(WaraSession::query()->count())->toBe(1)
            ->and($session->fresh()->is_present)->toBeTrue();
    });

    it('gateway melaporkan nol device tidak menandai semua device offline', function () {
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        Http::fake(['gateway.test/*' => Http::response(['data' => []], 200)]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        expect(WaraSession::query()->first()->is_present)->toBeTrue();
    });
});

describe('wara:poll-status (PRD §8.3)', function () {
    it('memperbarui status device dari gateway', function () {
        $session = makeSessionGatewayIntegrationTest(['device_id' => 'org_2', 'is_logged_in' => false, 'is_connected' => false]);

        Http::fake(['gateway.test/*' => Http::response(['state' => 'logged_in'], 200)]);

        $this->artisan('wara:poll-status')->assertSuccessful();

        $session->refresh();

        expect($session->is_logged_in)->toBeTrue()
            ->and($session->is_present)->toBeTrue();
    });

    it('menandai device yang perlu pairing ulang sebagai kegagalan', function () {
        config()->set('wara.offline_alert_after_minutes', 10);

        makeSessionGatewayIntegrationTest([
            'device_id' => 'org_gak_siap',
            'is_connected' => false,
            'is_logged_in' => false,
            'last_seen_at' => now()->subHour(),
        ]);

        Http::fake(['gateway.test/*' => Http::response(['state' => 'disconnected'], 200)]);

        $this->artisan('wara:poll-status')->assertFailed();
    });

    it('semua device baik => sukses', function () {
        makeSessionGatewayIntegrationTest(['device_id' => 'org_2']);

        Http::fake(['gateway.test/*' => Http::response(['state' => 'logged_in'], 200)]);

        $this->artisan('wara:poll-status')->assertSuccessful();
    });
});

describe('batas gateway tidak bocor (PRD §11.2)', function () {
    it('hanya adapter yang boleh menyebut sufiks JID gateway', function () {
        $adapter = file_get_contents(__DIR__.'/../../src/Gateways/GowaGateway.php');

        expect($adapter)->toContain('@s.whatsapp.net');

        // Manager dan controller tidak boleh menyebutnya.
        $manager = file_get_contents(__DIR__.'/../../src/WaraManager.php');
        $controller = file_get_contents(__DIR__.'/../../src/Http/Controllers/WebhookController.php');

        expect($manager)->not->toContain('@s.whatsapp.net')
            ->and($controller)->not->toContain('@s.whatsapp.net');
    });

    it('hanya adapter yang boleh menyebut header signature', function () {
        $adapter = file_get_contents(__DIR__.'/../../src/Gateways/GowaGateway.php');
        $manager = file_get_contents(__DIR__.'/../../src/WaraManager.php');

        expect($adapter)->toContain('X-Hub-Signature-256')
            ->and($manager)->not->toContain('X-Hub-Signature-256');
    });
});
