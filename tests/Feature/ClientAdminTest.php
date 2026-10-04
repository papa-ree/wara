<?php

use App\Models\User;
use Bale\Api\Services\TokenManager;
use Bale\Wara\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Wara\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Wara\Livewire\Pages\Landlord\Client\Section\ClientTable;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Bale\Wara\Support\DeviceRouter;
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

        // Label route yang outbound-nya mati ada di `sr-only` (untuk pembaca layar)
        // plus tanda `×` yang terlihat. Selector teks alone tidak bisa
        // membedakan keduanya, jadi yang diperiksa labelnya.
        Livewire::actingAs($this->user)
            ->test(ClientIndex::class)
            ->assertSee('kirim mati');
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
        $token = app(TokenManager::class)->issue('Client baru');

        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Client baru')
            ->set('type', WaraClient::TYPE_TOKEN)
            ->set('apiTokenId', $token['model']->getKey())
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

/*
|--------------------------------------------------------------------------
| Test yang ditambahkan saat UI client dibangun ulang
|--------------------------------------------------------------------------
|
| Semua ini menguji perilaku yang TIDAK ada sebelumnya: hapus client, ikat
| api token, dan diagnosis kesiapan. Tiga hal itu adalah tempat paling rawan
| gagal diam-diam, jadi masing-masing punya test sendiri.
|
*/

describe('hapus client', function () {
    beforeEach(function () {
        // 🔴 Permission HARUS di-grant ke user, bukan cuma dibuat.
        //
        // `beforeEach` luar sudah selesai sebelum blok ini jalan, jadi
        // `givePermissionTo()` di sana tidak mungkin tahu permission ini ada.
        // Kalau hanya `firstOrCreate`, `performDelete()` menolak dengan
        // Forbidden, dan test "menghapus client" gagal dengan pesan yang
        // menyesatkan: data tidak terhapus, padahal penyebabnya permission.
        Permission::firstOrCreate(['name' => 'wara.client.delete'], ['guard_name' => 'web']);
        $this->user->givePermissionTo('wara.client.delete');
    });

    it('🔴 menghapus client beserta route dan endpoint-nya', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Client dihapus', 'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => deviceForClientUiTest('del_dev_1')->device_id,
        ])->save();

        $endpoint = new WaraWebhookEndpoint;
        $endpoint->forceFill(['client_id' => $client->getKey(), 'url' => 'https://dihapus.test/hook']);
        $endpoint->forceFill(['secret' => 'secret-yang-cukup-panjang-32-karakter']);
        $endpoint->save();

        Livewire::actingAs($this->user)
            ->test(ClientTable::class)
            ->call('deleteItem', $client->getKey());

        expect(WaraClient::query()->whereKey($client->getKey())->exists())->toBeFalse()
            ->and(WaraRoute::query()->where('client_id', $client->getKey())->exists())->toBeFalse()
            ->and(WaraWebhookEndpoint::query()->where('client_id', $client->getKey())->exists())->toBeFalse();
    });

    it('🔴 TIDAK menghapus client yang tidak ada, dan tidak melempar error', function () {
        // 🔴 Dihitung dari SEBELUM, bukan dari angka nol.
        //
        // Migrasi `move_otp_route_to_srikandi_client` milik package lain ikut
        // jalan di `RefreshDatabase`, jadi `wara_clients` sudah berisi client
        // Srikandi sejak awal. Menyebut nol akan gagal bukan karena kode salah,
        // tapi karena ada data dari migrasi lain - dan yang terperiksa
        // ("tidak ada yang ikut terhapus") jadi tidak terperiksa sama sekali.
        $before = WaraClient::query()->count();

        Livewire::actingAs($this->user)
            ->test(ClientTable::class)
            ->call('deleteItem', '00000000-0000-4000-8000-000000000000');

        expect(WaraClient::query()->count())->toBe($before);
    });

    it('🔴 menolak hapus tanpa permission delete', function () {
        // Permission-nya dicabut dari user, bukan hanya tidak pernah diberikan.
        // Kalau hanya tidak diberikan, test ini lulus karena alasan yang salah:
        // dia lulus di `beforeEach` luar yang tidak menyentuh permission ini.
        $this->user->revokePermissionTo('wara.client.delete');
        $this->user->refresh();

        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Dilindungi', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientTable::class)
            ->call('deleteItem', $client->getKey())
            ->assertForbidden();

        expect(WaraClient::query()->whereKey($client->getKey())->exists())->toBeTrue();
    });
});

describe('form client: ikat api token', function () {
    it('🔴 menolak client tipe token tanpa api token', function () {
        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Tanpa token')
            ->set('type', WaraClient::TYPE_TOKEN)
            ->call('save')
            ->assertHasErrors('apiTokenId');

        expect(WaraClient::query()->where('name', 'Tanpa token')->exists())->toBeFalse();
    });

    it('🔴 menyimpan api_token_id untuk client tipe token', function () {
        $token = app(TokenManager::class)->issue('Token untuk client');

        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Client bertoken')
            ->set('type', WaraClient::TYPE_TOKEN)
            ->set('apiTokenId', $token['model']->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $client = WaraClient::query()->where('name', 'Client bertoken')->firstOrFail();

        expect($client->api_token_id)->toBe($token['model']->getKey())
            ->and($client->isUsable())->toBeTrue();
    });

    it('🔴 menolak api token yang sudah dipakai client lain', function () {
        $token = app(TokenManager::class)->issue('Token terpakai');

        $pemilik = WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Pemilik token', 'is_active' => true,
        ]);
        $pemilik->forceFill(['api_token_id' => $token['model']->getKey()])->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'Client pencuri token')
            ->set('type', WaraClient::TYPE_TOKEN)
            ->set('apiTokenId', $token['model']->getKey())
            ->call('save')
            ->assertHasErrors('apiTokenId');

        expect($pemilik->fresh()->api_token_id)->toBe($token['model']->getKey());
    });

    it('🔴 membolehkan client menyimpan ulang token miliknya sendiri', function () {
        // 🔴 Regresi: kalau client yang sedang diedit ikut di daftar
        // "dipakai client lain", form menolak nilai yang tidak berubah
        // sama sekali dan client tidak bisa disimpan tanpa alasan.
        $token = app(TokenManager::class)->issue('Token milik sendiri');

        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Client lama', 'is_active' => true,
        ]);
        $client->forceFill(['api_token_id' => $token['model']->getKey()])->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->set('name', 'Client lama dirapikan')
            ->call('save')
            ->assertHasNoErrors();

        expect($client->fresh()->name)->toBe('Client lama dirapikan')
            ->and($client->fresh()->api_token_id)->toBe($token['model']->getKey());
    });

    it('🔴 Membersihkan api_token_id saat tipe diubah ke service', function () {
        // 🔴 Kalau tidak dibersihkan, `DeviceRouter::clientForApiTokenId()`
        // masih memetakan token itu ke client service, dan request dari
        // token tersebut mendarat ke client yang tidak memakainya.
        $token = app(TokenManager::class)->issue('Token tidak relevan');

        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Turun kelas', 'is_active' => true,
        ]);
        $client->forceFill(['api_token_id' => $token['model']->getKey()])->save();

        Livewire::actingAs($this->user)
            ->test(ClientForm::class, ['client' => $client])
            ->set('type', WaraClient::TYPE_SERVICE)
            ->call('save')
            ->assertHasNoErrors();

        expect($client->fresh()->api_token_id)->toBeNull();
    });
});

describe('diagnosis kesiapan client', function () {
    it('🔴 menandai client token tanpa token sebagai belum bisa melayani traffic', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Belum diikat', 'is_active' => true,
        ]);

        $issues = $client->blockingReadinessIssues();

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['key'])->toBe('api_token_id')
            ->and($client->isUsable())->toBeFalse()
            ->and($client->readinessState())->toBe('unbound');
    });

    it('🔴 client service tanpa route TIDAK dianggap masalah', function () {
        // 🔴 "Belum dipetakan" adalah kondisi yang normal dan disengaja -
        // client baru memang belum punya route. Menandainya sebagai masalah
        // akan mendorong admin memperbaiki sesuatu yang tidak salah.
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Sudah sah', 'is_active' => true,
        ]);

        expect($client->blockingReadinessIssues())->toBe([])
            ->and($client->isUsable())->toBeTrue()
            ->and($client->readinessState())->toBe('ready');
    });

    it('🔴 client nonaktif dilaporkan tidak aktif, bukan unbound', function () {
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Nonaktif', 'is_active' => false,
        ]);

        expect($client->blockingReadinessIssues()[0]['key'])->toBe('is_active')
            ->and($client->readinessState())->toBe('inactive');
    });

    it('🔴 halaman daftar menampilkan diagnosis untuk client yang perlu perhatian', function () {
        WaraClient::query()->create([
            'type' => WaraClient::TYPE_TOKEN, 'name' => 'Perlu perhatian', 'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(ClientIndex::class)
            ->assertSee('perlu perhatian');
    });
});

/*
 * Tipe `tenant` pernah ada di `WaraClient::TYPES` dan karena itu muncul di
 * dropdown form Client. Itu bug yang paling lama bertahan, karena tidak pernah
 * gagal di layer mana pun: validasi `in:` menerimanya, kolom DB diam saja,
 * dan halaman admin menampilkannya sebagai pilihan yang wajar.
 *
 * Client `tenant` baru ketahuan benar-benar rusak saat ada yang memakainya -
 * karena TIDAK ADA jalur resolusi yang mencarinya. `SendClientResolver` hanya
 * melihat `api_token_id`, `defaultServiceClient()` hanya melihat `service`.
 *
 * Test di bawah mengunci dua hal yang berbeda, supaya tidak hanya satu yang
 * dijaga: model tidak menawarkan `tenant`, dan form yang dirender juga tidak.
 */
describe('tipe client tenant dibuang', function () {
    it('🔴 model hanya menawarkan token dan service', function () {
        expect(WaraClient::TYPES)->toBe([WaraClient::TYPE_TOKEN, WaraClient::TYPE_SERVICE])
            ->and(WaraClient::TYPES)->not->toContain('tenant')
            ->and(defined(WaraClient::class.'::TYPE_TENANT'))->toBeFalse();
    });

    it('🔴 form client tidak menampilkan opsi tenant', function () {
        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->assertOk()
            ->assertSee('<option value="token">token</option>', escape: false)
            ->assertSee('<option value="service">service</option>', escape: false)
            ->assertDontSee('<option value="tenant">', escape: false);
    });

    it('🔴 form menolak tipe tenant saat dikirim', function () {
        Livewire::actingAs($this->user)
            ->test(ClientForm::class)
            ->set('name', 'ClientTenant')
            ->set('type', 'tenant')
            ->set('is_active', true)
            ->call('save')
            ->assertHasErrors('type');

        // 🔴 Harus benar-benar tidak ada di DB - bukan hanya error validasi
        // yang muncul di layar lalu tetap menyimpan baris.
        expect(WaraClient::query()->where('name', 'ClientTenant')->exists())->toBeFalse();
    });

    it('🔴 client bertipe tenant yang sudah terlanjur ada akan gagal saat mengirim', function () {
        // 🔴 Menutup jalur lain: kalau migrasi lama pernah berjalan di
        // database yang sudah ada, baris `tenant` bisa sudah terlanjur ada
        // dan router tidak boleh diam-diam memperlakukannya sebagai tipe sah.
        $client = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE, 'name' => 'Terlanjur ada', 'is_active' => true,
        ]);

        // Bypass fillable supaya kita bisa menyimulasikan baris lama.
        $client->forceFill(['type' => 'tenant'])->save();

        $resolved = app(DeviceRouter::class)->defaultServiceClient();

        // 🔴 Yang diuji bukan "router mengembalikan null" - database test
        // punya client `service` lain (dari migrasi). Yang diuji: baris
        // `tenant` TIDAK PERNAH menjadi hasil resolusi, walau ia ada, aktif,
        // dan muncul lebih dulu di `wara_clients`.
        expect($client->fresh()->type)->toBe('tenant')
            ->and(WaraClient::TYPES)->not->toContain('tenant')
            ->and($resolved?->getKey())->not->toBe($client->getKey());
    });
});
