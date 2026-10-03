<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Wara\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    Permission::create(['name' => 'wara.client.read']);
    Permission::create(['name' => 'wara.client.update']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('wara.client.read', 'wara.client.update');
});

function deviceForClientUiTest(string $id): WaraSession
{
    return WaraSession::query()->create([
        'device_id' => $id,
        'jid' => '6289900'.substr(md5($id), 0, 5).'@s.whatsapp.net',
        'display_name' => 'Device '.$id,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);
}

describe('daftar client', function () {
    it('menampilkan client beserta peta purpose-nya', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client notifikasi',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => deviceForClientUiTest('ui_dev_1')->device_id,
        ])->save();

        Livewire::actingAs($this->user)
            ->test(ClientIndex::class)
            ->assertOk()
            ->assertSee('Client notifikasi')
            ->assertSee('notifikasi')
            ->assertSee('ui_dev_1');
    });

    it('menandai route yang outbound-nya mati', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client mati',
            'is_active' => true,
        ]);

        $route = (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => deviceForClientUiTest('ui_dev_2')->device_id,
        ]);

        $route->fill(['outbound_enabled' => false])->save();

        Livewire::actingAs($this->user)
            ->test(ClientIndex::class)
            ->assertSee('(kirim mati)');
    });

    it('menolak akses tanpa permission', function () {
        $component = new ClientIndex;

        expect(fn () => $component->mount())
            ->toThrow(AuthorizationException::class);
    });
});

describe('form client: peta purpose ke device', function () {
    it('🔴 membuat client beserta route-nya', function () {
        $device = deviceForClientUiTest('ui_dev_3');

        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Client baru')
            ->set('type', WaraClient::TYPE_TOKEN)
            ->call('addRoute')
            ->set('routes.0.purpose', 'notifikasi')
            ->set('routes.0.device_id', $device->device_id)
            ->call('save')
            ->assertHasNoErrors();

        $client = WaraClient::query()->where('name', 'Client baru')->firstOrFail();

        expect($client->type)->toBe(WaraClient::TYPE_TOKEN)
            ->and(WaraRoute::query()
                ->where('client_id', $client->getKey())
                ->where('purpose', 'notifikasi')
                ->where('device_id', $device->device_id)
                ->exists())->toBeTrue();
    });

    it('🔴 menolak purpose yang bentrok dalam client yang sama', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client bentrok',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => deviceForClientUiTest('ui_dev_4')->device_id,
        ])->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('addRoute')
            ->set('routes.1.purpose', 'notifikasi')
            ->set('routes.1.device_id', deviceForClientUiTest('ui_dev_5')->device_id)
            ->call('save')
            ->assertHasErrors('routes');
    });

    it('🔴 purpose yang sama BOLEH dipakai client berbeda', function () {
        $device = deviceForClientUiTest('ui_dev_6');

        $a = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'A', 'is_active' => true,
        ]);
        $b = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'B', 'is_active' => true,
        ]);

        foreach ([$a, $b] as $client) {
            Livewire::actingAs($this->user)
                ->test(ClientForm::class, ['client' => $client])
                ->call('addRoute')
                ->set('routes.0.purpose', 'notifikasi')
                ->set('routes.0.device_id', $device->device_id)
                ->call('save')
                ->assertHasNoErrors();
        }

        expect(WaraRoute::query()->where('purpose', 'notifikasi')->count())->toBe(2);
    });

    it('menghapus route yang dihapus di form', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client hapus', 'is_active' => true,
        ]);

        $route = (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => deviceForClientUiTest('ui_dev_7')->device_id,
        ]);

        $route->fill(['outbound_enabled' => false])->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('removeRoute', 0)
            ->call('save')
            ->assertHasNoErrors();

        expect(WaraRoute::query()->where('client_id', $client->getKey())->count())->toBe(0);
    });

    it('🔴 route milik client lain tidak bisa diambil lewat form', function () {
        $device = deviceForClientUiTest('ui_dev_8');

        $saya = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Saya', 'is_active' => true,
        ]);
        $lain = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client lain', 'is_active' => true,
        ]);

        $routeLain = (new WaraRoute)->forceFill([
            'client_id' => $lain->getKey(),
            'purpose' => 'rahasia',
            'device_id' => $device->device_id,
        ]);

        $routeLain->fill(['outbound_enabled' => false])->save();

        // Form client "Saya" dijalankan, tapi baris route-nya pakai id milik
        // client lain. Baris itu harus DIABAIKAN, bukan "diambil".
        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $saya])
            ->set('routes', [[
                'id' => $routeLain->getKey(),
                'purpose' => 'curang',
                'device_id' => $device->device_id,
                'outbound_enabled' => true,
                'inbound_webhook_enabled' => false,
            ]])
            ->call('save')
            ->assertHasNoErrors();

        // Route milik client lain harus utuh dan tidak berubah.
        $curang = WaraRoute::query()->where('purpose', 'curang')->count();

        expect($routeLain->fresh()->purpose)->toBe('rahasia')
            ->and($routeLain->fresh()->client_id)->toBe($lain->getKey())
            ->and($routeLain->fresh()->outbound_enabled)->toBeFalse()
            ->and($curang)->toBe(0);
    });

    it('memvalidasi format purpose', function () {
        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Client format')
            ->call('addRoute')
            ->set('routes.0.purpose', 'Purpose Dengan Spasi!')
            ->call('save')
            ->assertHasErrors('routes.0.purpose');
    });
});

describe('form client: endpoint webhook', function () {
    it('menyimpan endpoint dengan secret terenkripsi', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client endpoint', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('showEndpointCreator')
            ->set('endpointUrl', 'https://contoh.test/webhook')
            ->set('endpointSecret', 'secret-yang-cukup-panjang-32-karakter')
            ->set('endpointEvents', 'message, message.ack')
            ->set('endpointEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $endpoint = WaraWebhookEndpoint::query()->where('client_id', $client->getKey())->firstOrFail();

        expect($endpoint->url)->toBe('https://contoh.test/webhook')
            ->and($endpoint->events)->toBe(['message', 'message.ack'])
            ->and($endpoint->enabled)->toBeTrue();

        // Secret harus terenkripsi di database, bukan disimpan polos.
        $raw = DB::table('wara_webhook_endpoints')
            ->where('id', $endpoint->getKey())
            ->value('secret');

        expect($raw)->not->toBe('secret-yang-cukup-panjang-32-karakter');
    });

    it('🔴 secret TIDAK pernah dikirim ke browser', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client rahasia', 'is_active' => true,
        ]);

        $endpoint = new WaraWebhookEndpoint;
        $endpoint->forceFill(['client_id' => $client->getKey(), 'url' => 'https://rahasia.test/webhook']);
        $endpoint->forceFill(['secret' => 'secret-yang-cukup-panjang-32-karakter']);
        $endpoint->fill(['enabled' => true])->save();

        $html = Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->html();

        expect($html)->not->toContain('secret-yang-cukup-panjang-32-karakter')
            ->and($html)->toContain('secret terisi');
    });

    it('🔴 menolak url http ke host publik (anti SSRF)', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client ssrf', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('showEndpointCreator')
            ->set('endpointUrl', 'http://publik.example.com/webhook')
            ->set('endpointSecret', 'secret-yang-cukup-panjang-32-karakter')
            ->call('save');

        // Endpoint tidak boleh tersimpan.
        expect(WaraWebhookEndpoint::query()->where('client_id', $client->getKey())->count())->toBe(0);
    });

    it('🔴 menolak secret yang terlalu pendek', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client secret pendek', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('showEndpointCreator')
            ->set('endpointUrl', 'https://contoh.test/webhook')
            ->set('endpointSecret', 'pendek')
            ->call('save')
            ->assertHasErrors('endpointSecret');

        expect(WaraWebhookEndpoint::query()->where('client_id', $client->getKey())->count())->toBe(0);
    });

    it('url http ke localhost tetap diizinkan untuk pengembangan', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client lokal', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->call('showEndpointCreator')
            ->set('endpointUrl', 'http://localhost:8080/webhook')
            ->set('endpointSecret', 'secret-yang-cukup-panjang-32-karakter')
            ->call('save')
            ->assertHasNoErrors();

        expect(WaraWebhookEndpoint::query()->where('client_id', $client->getKey())->count())->toBe(1);
    });

    it('hapus endpoint hanya milik client itu', function () {
        $saya = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Pemilik', 'is_active' => true,
        ]);
        $lain = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Bukan pemilik', 'is_active' => true,
        ]);

        $endpointLain = new WaraWebhookEndpoint;
        $endpointLain->forceFill(['client_id' => $lain->getKey(), 'url' => 'https://milik-lain.test/hook']);
        $endpointLain->forceFill(['secret' => 'secret-yang-cukup-panjang-32-karakter']);
        $endpointLain->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $saya])
            ->call('removeEndpoint', $endpointLain->getKey());

        expect(WaraWebhookEndpoint::query()->whereKey($endpointLain->getKey())->exists())->toBeTrue();
    });
});
