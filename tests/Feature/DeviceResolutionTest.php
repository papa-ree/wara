<?php

use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\DeviceNotReadyException;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.default_device_id', '');

    $this->manager = app(WaraManager::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeSessionDeviceResolutionTest(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'org_res_'.$counter,
        'jid' => '62851260777'.$counter.'@s.whatsapp.net',
        'display_name' => 'wago prod '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

function makeClientDeviceResolutionTest(array $overrides = []): WaraClient
{
    static $counter = 0;
    $counter++;

    return WaraClient::query()->create(array_merge([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client res '.$counter,
        'is_active' => true,
    ], $overrides));
}

function makeRouteDeviceResolutionTest(WaraClient $client, string $deviceId, string $purpose = 'otp'): WaraRoute
{
    // `client_id` di luar $fillable, jadi harus lewat forceFill.
    $route = new WaraRoute;

    $route->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => $purpose,
        'device_id' => $deviceId,
    ])->save();

    return $route;
}

/**
 * 🔴 URUTAN RESOLUSI BERUBAH, dan test ini ditulis ulang karena itu.
 *
 * Schema lama menjawab "device mana untuk purpose ini?" secara global lalu
 * jatuh ke `default_device_id`. Sekarang pertanyaannya "client ini, purpose
 * ini -> device mana", dan jawabannya harus tegas: kalau tidak ada, itu
 * kesalahan konfigurasi yang harus terlihat, bukan alasan untuk diam-diam
 * mengirim dari nomor lain.
 */
describe('urutan resolusi: device > client+purpose > default', function () {
    it('baris 1: deviceId eksplisit menang atas route client', function () {
        $eksplisit = makeSessionDeviceResolutionTest();
        $client = makeClientDeviceResolutionTest();
        makeRouteDeviceResolutionTest($client, 'device_dari_route');

        $resolved = $this->manager->resolveDevice('otp', $eksplisit->device_id, $client);

        expect($resolved->device_id)->toBe($eksplisit->device_id)
            ->and($resolved->device_id)->not->toBe('device_dari_route');
    });

    it('baris 2: client + purpose memilih device dari route', function () {
        $client = makeClientDeviceResolutionTest();
        $device = makeSessionDeviceResolutionTest();
        $lain = makeSessionDeviceResolutionTest();

        makeRouteDeviceResolutionTest($client, $device->device_id);

        $resolved = $this->manager->resolveDevice('otp', null, $client);

        expect($resolved->device_id)->toBe($device->device_id)
            ->and($resolved->device_id)->not->toBe($lain->device_id);
    });

    it('baris 3: tanpa client memakai default_device_id', function () {
        $default = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $default->jid);

        $resolved = $this->manager->resolveDevice('otp');

        expect($resolved->device_id)->toBe($default->device_id);
    });

    it('baris 4: purpose null tanpa client memakai default_device_id', function () {
        $default = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $default->jid);

        $resolved = $this->manager->resolveDevice();

        expect($resolved->device_id)->toBe($default->device_id);
    });

    it('🔴 client tanpa route GAGAL, tidak jatuh ke default_device_id', function () {
        // Ini perubahan perilaku paling penting. Schema lama diam-diam
        // memakai default device kalau purpose tidak ditugaskan - artinya
        // pesan terkirim dari nomor yang tidak diminta siapa pun.
        $default = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $default->jid);

        $client = makeClientDeviceResolutionTest();

        expect(fn () => $this->manager->resolveDevice('otp', null, $client))
            ->toThrow(DeviceNotFoundException::class, $client->name);
    });

    it('🔴 route dengan outbound dimatikan GAGAL keras', function () {
        $client = makeClientDeviceResolutionTest();
        $device = makeSessionDeviceResolutionTest();

        $route = makeRouteDeviceResolutionTest($client, $device->device_id);
        $route->forceFill(['outbound_enabled' => false])->save();

        expect(fn () => $this->manager->resolveDevice('otp', null, $client))
            ->toThrow(DeviceNotFoundException::class, 'dimatikan');
    });

    it('🔴 dua client boleh punya route ke device yang sama', function () {
        $device = makeSessionDeviceResolutionTest();
        $clientA = makeClientDeviceResolutionTest(['name' => 'A']);
        $clientB = makeClientDeviceResolutionTest(['name' => 'B']);

        makeRouteDeviceResolutionTest($clientA, $device->device_id, 'notifikasi');
        makeRouteDeviceResolutionTest($clientB, $device->device_id, 'notifikasi');

        expect($this->manager->resolveDevice('notifikasi', null, $clientA)->device_id)
            ->toBe($device->device_id)
            ->and($this->manager->resolveDevice('notifikasi', null, $clientB)->device_id)
            ->toBe($device->device_id);
    });

    it('🔴 route milik client lain tidak bisa dipakai', function () {
        $deviceA = makeSessionDeviceResolutionTest();
        $deviceB = makeSessionDeviceResolutionTest();
        $clientA = makeClientDeviceResolutionTest(['name' => 'A']);
        $clientB = makeClientDeviceResolutionTest(['name' => 'B']);

        makeRouteDeviceResolutionTest($clientA, $deviceA->device_id);

        // Client B tidak punya route, jadi tidak boleh memakai device milik A.
        expect(fn () => $this->manager->resolveDevice('otp', null, $clientB))
            ->toThrow(DeviceNotFoundException::class);
    });
});

describe('resolusi JID ke Session ID (PRD §6.3.2)', function () {
    it('JID di config dicocokkan lewat kolom jid lalu dipakai device_id', function () {
        $session = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $session->jid);

        $resolved = $this->manager->resolveDevice();

        // Yang dipakai downstream adalah Session ID, bukan JID.
        expect($resolved->device_id)->toBe($session->device_id)
            ->and($resolved->jid)->toBe($session->jid);
    });

    it('JID di config yang tidak terdaftar gagal keras tanpa fallback', function () {
        makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', '628999999999@s.whatsapp.net');

        expect(fn () => $this->manager->resolveDevice())
            ->toThrow(DeviceNotReadyException::class, '628999999999@s.whatsapp.net');
    });

    it('default_device_id kosong menghasilkan pesan konfigurasi yang jelas', function () {
        expect(fn () => $this->manager->resolveDevice())
            ->toThrow(WaraException::class, 'WARA_DEFAULT_DEVICE_ID');
    });
});

describe('gerbang §6.5.2 - gagal keras, tanpa fallback', function () {
    it('menolak device offline', function () {
        $session = makeSessionDeviceResolutionTest(['is_connected' => false, 'is_logged_in' => false]);

        expect(fn () => $this->manager->resolveDevice(null, $session->device_id))
            ->toThrow(DeviceNotReadyException::class, 'is_connected = false');
    });

    it('menolak device yang sudah logout', function () {
        $session = makeSessionDeviceResolutionTest(['is_logged_in' => false]);

        expect(fn () => $this->manager->resolveDevice(null, $session->device_id))
            ->toThrow(DeviceNotReadyException::class, 'is_logged_in = false');
    });

    it('menolak device yang tidak ada di gateway', function () {
        $session = makeSessionDeviceResolutionTest(['is_present' => false]);

        expect(fn () => $this->manager->resolveDevice(null, $session->device_id))
            ->toThrow(DeviceNotReadyException::class, 'is_present = false');
    });

    it('menolak device yang belum punya JID', function () {
        $session = makeSessionDeviceResolutionTest(['jid' => null]);

        expect(fn () => $this->manager->resolveDevice(null, $session->device_id))
            ->toThrow(DeviceNotReadyException::class, 'jid = null');
    });

    it('pesan exception menyebut nama device, JID, dan cara memperbaiki', function () {
        $session = makeSessionDeviceResolutionTest(['is_logged_in' => false]);

        try {
            $this->manager->resolveDevice(null, $session->device_id);
            $this->fail('Harus melempar exception.');
        } catch (DeviceNotReadyException $e) {
            expect($e->getMessage())
                ->toContain($session->display_name)
                ->toContain($session->jid)
                ->toContain('php artisan wara:sync-devices');
        }
    });

    it('🔴 device offline tidak dialihkan ke device lain yang sehat', function () {
        $client = makeClientDeviceResolutionTest();
        $deviceSehat = makeSessionDeviceResolutionTest();
        $deviceRusak = makeSessionDeviceResolutionTest(['is_logged_in' => false]);

        makeRouteDeviceResolutionTest($client, $deviceRusak->device_id);

        // Tetap gagal walau ada device lain yang siap.
        expect(fn () => $this->manager->resolveDevice('otp', null, $client))
            ->toThrow(DeviceNotReadyException::class);

        expect($deviceSehat->isSendable())->toBeTrue()
            ->and($deviceRusak->isSendable())->toBeFalse();
    });
});

describe('device yang hilang (§6.5.3)', function () {
    it('deviceId yang tidak ada di database menghasilkan DeviceNotFoundException', function () {
        expect(fn () => $this->manager->resolveDevice(null, 'org_hantu'))
            ->toThrow(DeviceNotFoundException::class, 'wara:sync-devices');
    });

    it('route yang menunjuk device yang sudah dihapus gagal dengan nama device itu', function () {
        $client = makeClientDeviceResolutionTest();
        makeRouteDeviceResolutionTest($client, 'device_yang_dihapus');

        expect(fn () => $this->manager->resolveDevice('otp', null, $client))
            ->toThrow(DeviceNotFoundException::class, 'device_yang_dihapus');
    });
});

describe('kese uniqueness route', function () {
    it('satu client tidak bisa punya purpose yang sama dua kali', function () {
        $client = makeClientDeviceResolutionTest();
        $pertama = makeSessionDeviceResolutionTest();
        makeRouteDeviceResolutionTest($client, $pertama->device_id);

        $kedua = makeSessionDeviceResolutionTest();

        expect(fn () => makeRouteDeviceResolutionTest($client, $kedua->device_id))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('🔴 banyak client boleh memakai purpose yang sama', function () {
        // Kebalikan dari skema lama: satu purpose tidak lagi dipetakan ke satu device
        // tunggal di seluruh sistem.
        $clientA = makeClientDeviceResolutionTest(['name' => 'A']);
        $clientB = makeClientDeviceResolutionTest(['name' => 'B']);
        $device = makeSessionDeviceResolutionTest();

        makeRouteDeviceResolutionTest($clientA, $device->device_id, 'notifikasi');
        makeRouteDeviceResolutionTest($clientB, $device->device_id, 'notifikasi');

        expect(WaraRoute::query()->where('purpose', 'notifikasi')->count())->toBe(2);
    });
});
