<?php

use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\DeviceNotReadyException;
use Bale\Wara\Exceptions\WaraException;
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

describe('urutan resolusi §6.5.1', function () {
    it('baris 1: deviceId eksplisit menang atas purpose', function () {
        $eksplisit = makeSessionDeviceResolutionTest();
        $ditugaskan = makeSessionDeviceResolutionTest();
        $ditugaskan->forceFill(['purpose' => 'otp'])->save();

        $resolved = $this->manager->resolveDevice('otp', $eksplisit->device_id);

        expect($resolved->device_id)->toBe($eksplisit->device_id)
            ->and($resolved->device_id)->not->toBe($ditugaskan->device_id);
    });

    it('baris 2: purpose memilih device yang ditugaskan', function () {
        $lain = makeSessionDeviceResolutionTest();
        $ditugaskan = makeSessionDeviceResolutionTest();
        $ditugaskan->forceFill(['purpose' => 'otp'])->save();

        $resolved = $this->manager->resolveDevice('otp');

        expect($resolved->device_id)->toBe($ditugaskan->device_id)
            ->and($resolved->device_id)->not->toBe($lain->device_id);
    });

    it('baris 3: purpose tanpa device jatuh ke default_device_id', function () {
        $default = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $default->jid);

        $resolved = $this->manager->resolveDevice('otp');

        expect($resolved->device_id)->toBe($default->device_id);
    });

    it('baris 4: purpose null memakai default_device_id', function () {
        $default = makeSessionDeviceResolutionTest();
        config()->set('wara.default_device_id', $default->jid);

        $resolved = $this->manager->resolveDevice();

        expect($resolved->device_id)->toBe($default->device_id);
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

describe('gerbang §6.5.2 — gagal keras, tanpa fallback', function () {
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

    it('device offline tidak dialihkan ke device lain yang sehat', function () {
        $sehat = makeSessionDeviceResolutionTest();

        $rusak = makeSessionDeviceResolutionTest(['is_logged_in' => false]);
        $rusak->forceFill(['purpose' => 'otp'])->save();

        // Tetap gagal walau ada device lain yang siap.
        expect(fn () => $this->manager->resolveDevice('otp'))
            ->toThrow(DeviceNotReadyException::class);

        expect($sehat->isSendable())->toBeTrue()
            ->and($rusak->isSendable())->toBeFalse();
    });
});

describe('device yang hilang (§6.5.3)', function () {
    it('deviceId yang tidak ada di database menghasilkan DeviceNotFoundException', function () {
        expect(fn () => $this->manager->resolveDevice(null, 'org_hantu'))
            ->toThrow(DeviceNotFoundException::class, 'wara:sync-devices');
    });
});

describe('kese uniqueness purpose', function () {
    it('satu purpose tidak bisa dilayani dua device', function () {
        // purpose tidak ada di $fillable, jadi harus lewat forceFill.
        $pertama = makeSessionDeviceResolutionTest();
        $pertama->forceFill(['purpose' => 'otp'])->save();

        $kedua = makeSessionDeviceResolutionTest();

        expect(fn () => $kedua->forceFill(['purpose' => 'otp'])->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('banyak device boleh punya purpose null', function () {
        makeSessionDeviceResolutionTest();
        makeSessionDeviceResolutionTest();

        expect(WaraSession::query()->whereNull('purpose')->count())->toBe(2);
    });
});
