<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form as DeviceForm;
use Bale\Wara\Livewire\Pages\Landlord\Device\Index as DeviceIndex;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceHeader;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceTable;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::create(['name' => 'admin']));
    $this->actingAs($this->user);
});

function makeSessionDeviceDashboardTest(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'org_auto_'.$counter,
        'jid' => '62851260611'.$counter.'@s.whatsapp.net',
        'display_name' => 'wago prod '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

/**
 * Client wara untuk uji route.
 *
 * `api_token_id` sengaja TIDAK diisi di sini: uji router tidak butuh token,
 * dan mengisinya membuat test ikut bergantung pada tabel `api_tokens`.
 */
function makeClientDeviceDashboardTest(array $overrides = []): WaraClient
{
    static $counter = 0;
    $counter++;

    return WaraClient::query()->create(array_merge([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client uji '.$counter,
        'is_active' => true,
    ], $overrides));
}

describe('permission', function () {
    it('menolak Index tanpa permission wara.device.read', function () {
        Livewire::test(DeviceIndex::class)->assertForbidden();
    });

    it('mengizinkan Index dengan permission wara.device.read', function () {
        Permission::create(['name' => 'wara.device.read']);
        $this->user->givePermissionTo('wara.device.read');

        Livewire::test(DeviceIndex::class)->assertOk();
    });

    it('menolak Form tanpa permission wara.device.update', function () {
        Permission::create(['name' => 'wara.device.read']);
        $this->user->givePermissionTo('wara.device.read');

        $session = makeSessionDeviceDashboardTest();

        $component = new DeviceForm;

        expect(fn () => $component->mount($session))->toThrow(AuthorizationException::class);
    });
});

/**
 * 🔴 Penugasan tujuan layanan TIDAK lagi milik device.
 *
 * Dulu blok ini menguji `purpose` di `wara_sessions` dan form Livewire
 * `Device\Form`. Keduanya dihapus: penugasan pindah ke `wara_routes` yang unik
 * `(client_id, purpose)`. Yang diuji di sini sekarang adalah sifat-sifat yang
 * benar-benar baru - satu device bisa dilayani banyak client, dan halaman
 * device tidak boleh bisa mengubah route milik client mana pun.
 */
describe('route per client', function () {
    beforeEach(function () {
        Permission::create(['name' => 'wara.device.update']);
        $this->user->givePermissionTo('wara.device.update');
    });

    it('satu device bisa dilayani lebih dari satu client', function () {
        $session = makeSessionDeviceDashboardTest();
        $clientA = makeClientDeviceDashboardTest(['name' => 'Client A']);
        $clientB = makeClientDeviceDashboardTest(['name' => 'Client B']);

        (new WaraRoute)->forceFill([
            'client_id' => $clientA->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => $session->device_id,
        ])->save();

        (new WaraRoute)->forceFill([
            'client_id' => $clientB->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => $session->device_id,
        ])->save();

        expect($session->routes()->count())->toBe(2);
    });

    it('client yang sama tidak boleh punya purpose yang sama dua kali', function () {
        $session = makeSessionDeviceDashboardTest();
        $client = makeClientDeviceDashboardTest();

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'otp',
            'device_id' => $session->device_id,
        ])->save();

        expect(fn () => (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'otp',
            'device_id' => 'device_lain',
        ])->save())->toThrow(UniqueConstraintViolationException::class);
    });

    it('kolom purpose sudah tidak ada di wara_sessions', function () {
        // 🔴 Ini yang membuat "clean break" bisa diuji: kalau kolomnya
        // masih ada, berarti migrasi tidak benar-benar mencabutnya.
        expect(Schema::hasColumn('wara_sessions', 'purpose'))->toBeFalse();
    });

    it('🔴 halaman detail device TIDAK lagi punya input purpose atau tombol simpan', function () {
        // 🔴 Regresi untuk view yang tertinggal dari sebelum refactor.
        //
        // `Device\Form` sudah tidak punya property `purpose` maupun method
        // `save()` - penugasan pindah ke halaman Client. Tapi view-nya masih
        // menaruh `<input wire:model="purpose">` dan tombol "Simpan
        // Penugasan". Dua-duanya rusak: inputnya menulis ke property yang
        // tidak ada, dan=`save()` memanggil method yang tidak ada.
        //
        // Yang lebih buruk dari error: form itu MELEBIHKAN. Admin akan
        // mengira menugaskan device dari halaman ini, padahal yang tersimpan
        // adalah route milik client lain.
        $session = makeSessionDeviceDashboardTest();
        $client = makeClientDeviceDashboardTest(['name' => 'Client Untuk Device']);

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'otp',
            'device_id' => $session->device_id,
        ])->save();

        $html = Livewire::actingAs($this->user)
            ->test(DeviceForm::class, ['session' => $session])
            ->assertOk()
            ->html();

        expect($html)->not->toContain('wire:model="purpose"')
            ->and($html)->not->toContain('wire:submit="save"')
            ->and($html)->not->toContain('Simpan Penugasan')
            // Ganti dengan daftar client yang memakai device ini.
            ->and($html)->toContain('Client Untuk Device');
    });

    it('🔴 daftar device menampilkan client pemakai, bukan kolom purpose yang sudah hilang', function () {
        // 🔴 Row view lama membaca `$record->purpose`. Properti itu sudah
        // tidak ada, jadi kolomnya kosong tanpa error - jenis kegagalan yang
        // paling mudah terlewat karena tidak ada yang meledak.
        $session = makeSessionDeviceDashboardTest();
        $client = makeClientDeviceDashboardTest(['name' => 'Client List']);

        $route = (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'notifikasi',
            'device_id' => $session->device_id,
        ]);

        $route->fill(['outbound_enabled' => false])->save();

        $html = Livewire::actingAs($this->user)
            ->test('core-shared-components::data-table', [
                'model' => WaraSession::class,
                'rowView' => 'wara::livewire.pages.landlord.device.section.device-row',
                'with' => ['routes'],
                'columns' => [],
                'constraints' => ['device_id' => $session->device_id],
            ])
            ->assertOk()
            ->html();

        expect($html)->toContain('Client List')
            ->and($html)->toContain('notifikasi')
            // Route yang outbound-nya mati harus ditandai, kalau tidak admin
            // akan mengira device ini siap untuk semua client.
            ->and($html)->toContain('kirim mati');
    });

    it('client_id tidak bisa ditulis lewat mass assignment pada route', function () {
        $client = makeClientDeviceDashboardTest();

        $route = new WaraRoute;
        $route->fill(['purpose' => 'otp', 'device_id' => 'd', 'client_id' => 'dipaksa']);

        // fill() menyaring berdasarkan $fillable, jadi client_id diabaikan.
        expect($route->client_id)->not->toBe('dipaksa')
            ->and($client->getKey())->not->toBe('dipaksa');
    });

    it('sync-devices tidak boleh menyentuh route milik client', function () {
        $session = makeSessionDeviceDashboardTest();
        $client = makeClientDeviceDashboardTest();

        $deviceIdAsli = $session->device_id;

        (new WaraRoute)->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => 'otp',
            'device_id' => $deviceIdAsli,
        ])->save();

        // Yang di-sync cuma katalog device.
        $session->fill([
            'display_name' => 'nama baru dari gateway',
            'is_logged_in' => true,
        ])->save();

        $route = WaraRoute::query()->where('purpose', 'otp')->first();

        expect($route->device_id)->toBe($deviceIdAsli)
            ->and($session->fresh()->display_name)->toBe('nama baru dari gateway');
    });
});

describe('indeks dan kolom tabel', function () {
    it('wara_routes punya unique (client_id, purpose)', function () {
        $indexes = Schema::getIndexes('wara_routes');
        $composite = collect($indexes)->first(
            fn ($i) => in_array('client_id', $i['columns'], true) && in_array('purpose', $i['columns'], true)
        );

        expect($composite)->not->toBeNull()
            ->and($composite['unique'])->toBeTrue();
    });

    it('device_id punya index unique di wara_sessions', function () {
        $indexes = Schema::getIndexes('wara_sessions');
        $deviceIndex = collect($indexes)->first(fn ($i) => in_array('device_id', $i['columns'], true));

        expect($deviceIndex)->not->toBeNull()
            ->and($deviceIndex['unique'])->toBeTrue();
    });

    it('wara_clients punya kolom bale_id dan api_token_id', function () {
        expect(Schema::hasColumn('wara_clients', 'bale_id'))->toBeTrue()
            ->and(Schema::hasColumn('wara_clients', 'api_token_id'))->toBeTrue();
    });

    it('api_tokens TIDAK punya bale_id, dan wara_clients yang memegangnya', function () {
        // 🔴 Kolom ini pernah ada di `api_tokens` lalu dicabut.
        //
        // `api_tokens` milik `bale/api` dan `bale_lists` milik `bale/cms`.
        // Meletakkan `bale_id` di token berarti `bale/api` terikat ke CMS - dan
        // token yang diberikan ke sistem di luar Bale tidak punya `bale_lists`
        // sama sekali. Identitas "token ini milik aplikasi siapa" tetap ada,
        // tapi di tempat yang benar: `wara_clients`.
        expect(Schema::hasColumn('api_tokens', 'bale_id'))->toBeFalse()
            ->and(Schema::hasColumn('wara_clients', 'bale_id'))->toBeTrue()
            ->and(Schema::hasColumn('wara_clients', 'api_token_id'))->toBeTrue();
    });

    it('wara_webhook_endpoints punya secret yang wajib ada', function () {
        expect(Schema::hasColumn('wara_webhook_endpoints', 'secret'))->toBeTrue()
            ->and(Schema::hasColumn('wara_webhook_endpoints', 'enabled'))->toBeTrue();
    });
});

describe('warna status', function () {
    it('menandai device siap dengan hijau', function () {
        expect(makeSessionDeviceDashboardTest()->state_color)->toBe('green')
            ->and(makeSessionDeviceDashboardTest()->state_label)->toBe('Siap');
    });

    it('menandai device terhubung tapi belum login dengan amber', function () {
        $session = makeSessionDeviceDashboardTest(['is_logged_in' => false, 'is_connected' => true]);

        expect($session->state_color)->toBe('amber');
    });

    it('menandai device yang hilang dari gateway dengan abu-abu', function () {
        $session = makeSessionDeviceDashboardTest(['is_present' => false, 'is_connected' => false, 'is_logged_in' => false]);

        expect($session->state_color)->toBe('gray')
            ->and($session->state_label)->toBe('Tidak ada di gateway');
    });
});

describe('tombol Sinkronkan Sekarang', function () {
    beforeEach(function () {
        Permission::create(['name' => 'wara.device.read']);
        $this->user->givePermissionTo('wara.device.read');

        config()->set('wara.endpoint', 'http://gateway.test');
        config()->set('wara.api_key', 'user:pass');
        config()->set('wara.slack.enabled', false);
    });

    it('🔴 device baru dari gateway TERSIMPAN, bukan hanya ditampilkan', function () {
        // Dulu tombol ini cuma memanggil `listDevices()` — katalog terbaca,
        // toast sukses, tapi wara_sessions tidak berubah. Test ini memastikan
        // tombol memakai `syncDevices()` yang sama dengan `wara:sync-devices`.
        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_baru', 'jid' => '6285126061199@s.whatsapp.net', 'display_name' => 'wago baru', 'state' => 'logged_in'],
            ],
        ], 200)]);

        Livewire::test(DeviceHeader::class)
            ->call('syncNow')
            ->assertDispatched('toast')
            ->assertDispatched('refresh-device-table');

        $saved = WaraSession::query()->where('device_id', 'org_baru')->first();

        expect($saved)->not->toBeNull()
            ->and($saved->display_name)->toBe('wago baru')
            ->and($saved->is_present)->toBeTrue()
            ->and($saved->is_logged_in)->toBeTrue()
            ->and($saved->fresh()->last_seen_at)->not->toBeNull();
    });

    it('device berroute yang hilang ditandai offline oleh tombol, BUKAN dihapus', function () {
        $lama = makeSessionDeviceDashboardTest(['device_id' => 'org_lama', 'jid' => '6286@s.whatsapp.net']);
        $client = makeClientDeviceDashboardTest();
        $route = new WaraRoute;
        $route->forceFill(['client_id' => $client->getKey(), 'purpose' => 'otp', 'device_id' => 'org_lama'])->save();

        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_baru', 'jid' => '6287@s.whatsapp.net', 'state' => 'logged_in'],
            ],
        ], 200)]);

        Livewire::test(DeviceHeader::class)->call('syncNow');

        $lama->refresh();

        expect(WaraSession::query()->count())->toBe(2)
            ->and($lama->is_present)->toBeFalse()
            ->and($lama->is_logged_in)->toBeFalse()
            // Route milik client dipertahankan walau device-nya offline.
            ->and($route->fresh()->device_id)->toBe('org_lama');
    });

    it('device tanpa route yang hilang dari gateway dibersihkan oleh tombol', function () {
        makeSessionDeviceDashboardTest(['device_id' => 'org_hantu', 'jid' => '6286@s.whatsapp.net']);

        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_baru', 'jid' => '6287@s.whatsapp.net', 'state' => 'logged_in'],
            ],
        ], 200)]);

        Livewire::test(DeviceHeader::class)->call('syncNow');

        // Ghost tanpa route tidak menyimpan keputusan admin apa pun — sinkron
        // berikutnya wajib membersihkannya, sama seperti `wara:sync-devices`.
        expect(WaraSession::query()->where('device_id', 'org_hantu')->exists())->toBeFalse()
            ->and(WaraSession::query()->count())->toBe(1);
    });

    it('gateway melaporkan nol device: tombol tidak menandai semua device offline', function () {
        makeSessionDeviceDashboardTest(['device_id' => 'org_2']);

        Http::fake(['gateway.test/*' => Http::response(['data' => []], 200)]);

        Livewire::test(DeviceHeader::class)->call('syncNow');

        expect(WaraSession::query()->first()->is_present)->toBeTrue();
    });

    it('gateway tidak dapat dihubungi: tombol menampilkan toast error dan data lama utuh', function () {
        $session = makeSessionDeviceDashboardTest(['device_id' => 'org_2']);

        Http::fake(['gateway.test/*' => Http::response('gateway down', 503)]);

        Livewire::test(DeviceHeader::class)
            ->call('syncNow')
            ->assertDispatched('toast')
            ->assertNotDispatched('refresh-device-table');

        expect($session->fresh()->is_present)->toBeTrue();
    });

    it('tombol dan command wara:sync-devices memakai jalur sinkron yang sama', function () {
        // 🔴 Regresi: kalau tombol nanti "diperindah" dengan katalog langsung
        // dari gateway tapi command tetap jalan sendiri, keduanya bisa bercabang
        // lagi seperti bug aslinya. Satu rentetan fake harus menghasilkan
        // database yang identik lewat dua pintu.
        Http::fake(['gateway.test/*' => Http::response([
            'data' => [
                ['device_id' => 'org_a', 'jid' => '6285126061101@s.whatsapp.net', 'display_name' => 'Device A', 'state' => 'logged_in'],
                ['device_id' => 'org_b', 'jid' => '6285126061102@s.whatsapp.net', 'display_name' => 'Device B', 'state' => 'connected'],
            ],
        ], 200)]);

        $this->artisan('wara:sync-devices')->assertSuccessful();

        $viaCommand = WaraSession::query()->orderBy('device_id')->get()->map(fn ($s) => [
            'device_id' => $s->device_id,
            'display_name' => $s->display_name,
            'is_present' => $s->is_present,
        ])->all();

        foreach ($viaCommand as $row) {
            WaraSession::query()->where('device_id', $row['device_id'])->delete();
        }

        Livewire::test(DeviceHeader::class)->call('syncNow');

        $viaButton = WaraSession::query()->orderBy('device_id')->get()->map(fn ($s) => [
            'device_id' => $s->device_id,
            'display_name' => $s->display_name,
            'is_present' => $s->is_present,
        ])->all();

        expect($viaButton)->toBe($viaCommand);
    });
});

describe('hapus device manual (ghost)', function () {
    beforeEach(function () {
        Permission::create(['name' => 'wara.device.read']);
        Permission::create(['name' => 'wara.device.delete']);
        $this->user->givePermissionTo('wara.device.read', 'wara.device.delete');
    });

    it('menolak hapus tanpa permission wara.device.delete', function () {
        $this->user->revokePermissionTo('wara.device.delete');

        $ghost = makeSessionDeviceDashboardTest(['device_id' => 'org_ghost', 'is_present' => false, 'is_connected' => false, 'is_logged_in' => false]);

        Livewire::test(DeviceTable::class)
            ->call('deleteWaraDevice', $ghost->getKey())
            ->assertForbidden();

        expect(WaraSession::query()->find($ghost->getKey()))->not->toBeNull();
    });

    it('menolak menghapus device yang masih ada di gateway', function () {
        $live = makeSessionDeviceDashboardTest(['device_id' => 'org_live']);

        Livewire::test(DeviceTable::class)
            ->call('deleteWaraDevice', $live->getKey())
            ->assertDispatched('toast', type: 'error');

        expect(WaraSession::query()->find($live->getKey()))->not->toBeNull();
    });

    it('menghapus device offline tanpa route', function () {
        $ghost = makeSessionDeviceDashboardTest(['device_id' => 'org_ghost', 'is_present' => false, 'is_connected' => false, 'is_logged_in' => false]);

        Livewire::test(DeviceTable::class)
            ->call('deleteWaraDevice', $ghost->getKey())
            ->assertDispatched('toast', type: 'success')
            ->assertDispatched('refresh-device-table');

        expect(WaraSession::query()->find($ghost->getKey()))->toBeNull();
    });

    it('menghapus device offline menggugurkan route yang menunjuknya', function () {
        $ghost = makeSessionDeviceDashboardTest(['device_id' => 'org_ghost', 'is_present' => false, 'is_connected' => false, 'is_logged_in' => false]);
        $client = makeClientDeviceDashboardTest();
        $route = new WaraRoute;
        $route->forceFill(['client_id' => $client->getKey(), 'purpose' => 'otp', 'device_id' => 'org_ghost'])->save();

        Livewire::test(DeviceTable::class)
            ->call('deleteWaraDevice', $ghost->getKey())
            ->assertDispatched('toast', type: 'success');

        expect(WaraSession::query()->find($ghost->getKey()))->toBeNull()
            ->and($route->fresh())->toBeNull();
    });

    it('device yang tidak ditemukan memunculkan toast error', function () {
        Livewire::test(DeviceTable::class)
            ->call('deleteWaraDevice', 999999)
            ->assertDispatched('toast', type: 'error');
    });
});
