<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form as DeviceForm;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    Permission::findOrCreate('wara.device.update', 'web');

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('wara-admin', 'web'));
    $this->user->givePermissionTo('wara.device.update');
    $this->actingAs($this->user);
});

function makeDeviceSession(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'wago prod '.$counter,
        'jid' => '6285126061'.$counter.'@s.whatsapp.net',
        'display_name' => 'Nawasara Agent '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

function fakeGatewayWithJid(?string $jid, string $state = 'logged_in'): void
{
    Http::fake(['gateway.test/*' => Http::response([
        'code' => 'SUCCESS',
        'message' => 'List devices',
        'results' => [[
            'id' => 'wago prod 1',
            'jid' => $jid,
            'display_name' => 'Nawasara Agent',
            'state' => $state,
        ]],
    ], 200)]);
}

describe('tes koneksi di halaman edit device', function () {
    it('menolak device tanpa JID', function () {
        $session = makeDeviceSession(['jid' => null]);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('testConnection')
            ->assertDispatched('toast', fn ($name, $params) => ($params['type'] ?? null) === 'error');
    });

    it('menolak JID yang tidak terdaftar di gateway', function () {
        fakeGatewayWithJid('628999@s.whatsapp.net');

        $session = makeDeviceSession();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('testConnection')
            ->assertDispatched('toast', fn ($name, $params) => str_contains(
                (string) ($params['message'] ?? ''),
                'wara:sync-devices'
            ));
    });

    it('memperbarui status saat JID ditemukan', function () {
        $session = makeDeviceSession(['is_logged_in' => false, 'is_connected' => false]);
        fakeGatewayWithJid($session->jid);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('testConnection')
            ->assertDispatched('toast', fn ($name, $params) => ($params['type'] ?? null) === 'success');

        $session->refresh();

        expect($session->is_logged_in)->toBeTrue()
            ->and($session->is_connected)->toBeTrue()
            ->and($session->last_seen_at)->not->toBeNull();
    });

    it('tidak mengubah purpose milik admin', function () {
        $session = makeDeviceSession();
        $session->forceFill(['purpose' => 'otp'])->save();

        fakeGatewayWithJid($session->jid);

        Livewire::test(DeviceForm::class, ['session' => $session])->call('testConnection');

        expect($session->fresh()->purpose)->toBe('otp');
    });

    it('menandai device offline tanpa mengubah purpose', function () {
        $session = makeDeviceSession();
        $session->forceFill(['purpose' => 'otp'])->save();

        fakeGatewayWithJid($session->jid, 'disconnected');

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('testConnection')
            ->assertDispatched('toast', fn ($name, $params) => ($params['type'] ?? null) === 'warning');

        $session->refresh();

        expect($session->is_logged_in)->toBeFalse()
            ->and($session->is_connected)->toBeFalse()
            ->and($session->purpose)->toBe('otp');
    });

    it('gateway tidak dapat dihubungi memberi pesan error', function () {
        Http::fake(['gateway.test/*' => Http::response(['code' => 'INTERNAL_SERVER_ERROR'], 500)]);

        $session = makeDeviceSession();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('testConnection')
            ->assertDispatched('toast', fn ($name, $params) => ($params['type'] ?? null) === 'error');
    });
});
