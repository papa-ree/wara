<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form as DeviceForm;
use Bale\Wara\Livewire\Pages\Landlord\Device\Index as DeviceIndex;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
