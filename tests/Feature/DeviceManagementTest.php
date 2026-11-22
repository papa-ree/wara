<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form as DeviceForm;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceCreate;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Manajemen device (PRD §15, fase 14).
 *
 * 🔴 `Http::preventStrayRequests()` + override `wara.endpoint` wajib: suite ini
 * memuat `.env` lokal yang bisa menunjuk gateway nyata, dan `Http::fake()`
 * hanya MENAMBAH stub - bukan menutup jalan keluar.
 */
beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    Http::preventStrayRequests();

    foreach (['wara.device.read', 'wara.device.update', 'wara.device.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('wara-admin', 'web'));
    $this->user->givePermissionTo(['wara.device.read', 'wara.device.update', 'wara.device.manage']);
    $this->actingAs($this->user);
});

function makeManagedSession(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'wara-'.$counter,
        'jid' => '6285126061'.$counter.'@s.whatsapp.net',
        'display_name' => 'Device kelola '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

function toastIs(string $type): Closure
{
    return fn ($name, $params) => ($params['type'] ?? null) === $type;
}

describe('permission wara.device.manage', function () {
    it('menolak tambah slot tanpa wara.device.manage', function () {
        $this->user->revokePermissionTo('wara.device.manage');

        Livewire::test(DeviceCreate::class)->assertForbidden();
    });

    it('menolak aksi pairing tanpa wara.device.manage', function () {
        $this->user->revokePermissionTo('wara.device.manage');

        $session = makeManagedSession();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('loginQr')
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('wara:install men-seed permission wara.device.manage', function () {
        Permission::query()->where('name', 'wara.device.manage')->delete();

        $this->artisan('wara:install')->assertSuccessful();

        expect(Permission::query()->where('name', 'wara.device.manage')->exists())->toBeTrue();
    });
});

describe('tambah slot device', function () {
    it('menyimpan slot baru ke katalog lokal', function () {
        Http::fake([
            'gateway.test/devices' => Http::response([
                'code' => 'SUCCESS',
                'results' => ['id' => 'wara-baru', 'jid' => null, 'state' => 'disconnected'],
            ], 200),
        ]);

        Livewire::test(DeviceCreate::class)
            ->set('deviceId', 'wara-baru')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('refresh-device-table')
            ->assertDispatched('toast', toastIs('success'));

        $session = WaraSession::query()->where('device_id', 'wara-baru')->first();

        expect($session)->not->toBeNull()
            ->and($session->is_present)->toBeTrue()
            ->and($session->is_logged_in)->toBeFalse();
    });

    it('menolak nama slot dengan karakter aneh', function () {
        Livewire::test(DeviceCreate::class)
            ->set('deviceId', 'wara/../x')
            ->call('save')
            ->assertHasErrors('deviceId');

        Http::assertNothingSent();
    });

    it('menampilkan error saat gateway menolak', function () {
        Http::fake(['gateway.test/devices' => Http::response(['code' => 'INTERNAL_SERVER_ERROR'], 500)]);

        Livewire::test(DeviceCreate::class)
            ->set('deviceId', 'wara-baru')
            ->call('save')
            ->assertDispatched('toast', toastIs('error'));

        expect(WaraSession::query()->where('device_id', 'wara-baru')->exists())->toBeFalse();
    });
});

describe('pairing device', function () {
    it('menampilkan QR sebagai data-uri tanpa membocorkan host gateway', function () {
        Http::fake([
            'gateway.test/devices/*/login' => Http::response([
                'code' => 'SUCCESS',
                'results' => [
                    'device_id' => 'wara-1',
                    'qr_link' => 'http://gateway.test/qr/abc.png',
                    'qr_duration' => 30,
                ],
            ], 200),
            // Unduhan gambar terjadi server-side (keputusan #29).
            'gateway.test/qr/abc.png' => Http::response('PNGDATA', 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('loginQr')
            ->assertSet('qrExpiresIn', 30)
            ->assertSet('qrImage', 'data:image/png;base64,'.base64_encode('PNGDATA'))
            ->assertDontSee('gateway.test')
            ->assertSeeHtml(base64_encode('PNGDATA'));
    });

    it('menampilkan kode pairing dari nomor perangkat', function () {
        Http::fake([
            'gateway.test/devices/*/login/code*' => Http::response([
                'code' => 'SUCCESS',
                'results' => ['device_id' => 'wara-1', 'pair_code' => 'ABCD-1234'],
            ], 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('loginPhone', '628123456789')
            ->call('loginWithCode')
            ->assertSet('pairingCode', 'ABCD-1234')
            ->assertSee('ABCD-1234');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/login/code')
            && str_contains($request->url(), 'phone=628123456789'));
    });

    it('menolak nomor perangkat yang tidak valid', function () {
        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('loginPhone', 'bukan-nomor')
            ->call('loginWithCode')
            ->assertHasErrors('loginPhone');

        Http::assertNothingSent();
    });

    it('menutup panel pairing begitu device logged_in', function () {
        Http::fake([
            'gateway.test/devices/*/status' => Http::response([
                'code' => 'SUCCESS',
                'results' => ['is_logged_in' => true, 'is_connected' => true],
            ], 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1', 'is_logged_in' => false]);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('qrImage', 'data:image/png;base64,AAA')
            ->call('refreshLoginStatus')
            ->assertSet('qrImage', null)
            ->assertDispatched('toast', toastIs('success'));

        expect($session->fresh()->is_logged_in)->toBeTrue();
    });
});

describe('aksi device', function () {
    it('mengirim reconnect ke gateway', function () {
        Http::fake(['gateway.test/devices/*/reconnect' => Http::response(['code' => 'SUCCESS'], 200)]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('reconnect')
            ->assertDispatched('toast', toastIs('success'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/devices/wara-1/reconnect'));
    });

    it('logout tanpa menghapus slot', function () {
        Http::fake(['gateway.test/devices/*/logout' => Http::response(['code' => 'SUCCESS'], 200)]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('logout')
            ->assertDispatched('toast', toastIs('success'));

        expect(WaraSession::query()->whereKey($session->getKey())->exists())->toBeTrue();
    });

    it('menolak hapus slot yang masih dipakai route', function () {
        $session = makeManagedSession(['device_id' => 'wara-1']);

        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client pemakai device',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'otp',
            'device_id' => 'wara-1',
        ])->save();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('removeDevice')
            ->assertDispatched('toast', toastIs('error'));

        Http::assertNothingSent();

        expect(WaraSession::query()->whereKey($session->getKey())->exists())->toBeTrue();
    });

    it('menghapus slot di gateway dan katalog lokal', function () {
        Http::fake(['gateway.test/devices/*' => Http::response(['code' => 'SUCCESS'], 200)]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('removeDevice')
            ->assertRedirect(route('wara.device.index'));

        expect(WaraSession::query()->whereKey($session->getKey())->exists())->toBeFalse();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/devices/wara-1'));
    });
});

describe('webhook per-device', function () {
    it('membaca konfigurasi tanpa merender secret', function () {
        Http::fake([
            'gateway.test/devices/*/webhook' => Http::response([
                'code' => 'SUCCESS',
                'results' => [
                    'device_id' => 'wara-1',
                    'webhook_url' => 'https://contoh.test/hook',
                    'webhook_secret' => 'rahasia-lama',
                    'webhook_events' => 'message',
                    'webhook_insecure_skip_verify' => false,
                ],
            ], 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->call('loadWebhook')
            ->assertSet('webhookUrl', 'https://contoh.test/hook')
            ->assertSet('webhookEvents', 'message')
            ->assertSet('webhookSecret', '')
            ->assertSet('webhookHasSecret', true)
            ->assertDontSee('rahasia-lama');
    });

    it('menulis webhook beserta secret baru', function () {
        Http::fake([
            'gateway.test/devices/*/webhook' => Http::response([
                'code' => 'SUCCESS',
                'results' => [
                    'device_id' => 'wara-1',
                    'webhook_url' => 'https://contoh.test/hook',
                    'webhook_events' => 'message,message.ack',
                    'webhook_insecure_skip_verify' => false,
                    'webhook_secret' => 'rahasia-baru',
                ],
            ], 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('webhookUrl', 'https://contoh.test/hook')
            ->set('webhookSecret', 'rahasia-baru')
            ->set('webhookEvents', 'message, message.ack')
            ->call('saveWebhook')
            ->assertSet('webhookSecret', '')
            ->assertSet('webhookHasSecret', true)
            ->assertDispatched('toast', toastIs('success'));

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['webhook_url'] === 'https://contoh.test/hook'
            && $request['webhook_events'] === 'message,message.ack'
            && $request['webhook_secret'] === 'rahasia-baru');
    });

    it('menolak simpan URL kosong sebelum konfigurasi dimuat', function () {
        Http::fake();

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('webhookEvents', 'message,message.ack')
            ->call('saveWebhook')
            ->assertDispatched('toast', toastIs('error'));

        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    });

    it('tidak mengirim secret kosong agar secret lama tidak terhapus', function () {
        Http::fake([
            'gateway.test/devices/*/webhook' => Http::response([
                'code' => 'SUCCESS',
                'results' => ['device_id' => 'wara-1', 'webhook_url' => 'https://contoh.test/hook', 'webhook_secret' => 'rahasia-lama'],
            ], 200),
        ]);

        $session = makeManagedSession(['device_id' => 'wara-1']);

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('webhookUrl', 'https://contoh.test/hook')
            ->set('webhookSecret', '')
            ->call('saveWebhook')
            ->assertDispatched('toast', toastIs('success'));

        Http::assertSent(fn ($request) => ! array_key_exists('webhook_secret', $request->data()));
    });
});

describe('batas gateway tetap utuh', function () {
    it('hanya adapter yang menyebut nama field webhook/pairing gateway', function () {
        // `webhook_url` tidak ikut di sini: listener alert Slack memakai nama
        // config yang sama untuk incoming webhook miliknya sendiri.
        $fields = [
            'qr_link', 'qr_duration', 'pair_code',
            'webhook_secret', 'webhook_events', 'webhook_insecure_skip_verify',
        ];

        $violations = [];

        foreach (File::allFiles(__DIR__.'/../../src') as $file) {
            if (str_ends_with($file->getPathname(), 'Gateways'.DIRECTORY_SEPARATOR.'GowaGateway.php')) {
                continue;
            }

            foreach ($fields as $field) {
                if (str_contains($file->getContents(), $field)) {
                    $violations[] = $file->getRelativePathname().': '.$field;
                }
            }
        }

        expect($violations)->toBe([]);
    });
});
