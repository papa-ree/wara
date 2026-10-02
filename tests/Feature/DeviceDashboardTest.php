<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form as DeviceForm;
use Bale\Wara\Livewire\Pages\Landlord\Device\Index as DeviceIndex;
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

describe('penugasan purpose', function () {
    beforeEach(function () {
        Permission::create(['name' => 'wara.device.update']);
        $this->user->givePermissionTo('wara.device.update');
    });

    it('menyimpan purpose ke device', function () {
        $session = makeSessionDeviceDashboardTest();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('purpose', 'otp')
            ->call('save')
            ->assertHasNoErrors();

        expect($session->fresh()->purpose)->toBe('otp');
    });

    it('menyimpan purpose null untuk melepas penugasan', function () {
        $session = makeSessionDeviceDashboardTest();
        $session->forceFill(['purpose' => 'otp'])->save();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('purpose', null)
            ->call('save')
            ->assertHasNoErrors();

        expect($session->fresh()->purpose)->toBeNull();
    });

    it('menolak purpose yang sudah dilayani device lain', function () {
        $pertama = makeSessionDeviceDashboardTest();
        $pertama->forceFill(['purpose' => 'otp'])->save();

        $lain = makeSessionDeviceDashboardTest();

        Livewire::test(DeviceForm::class, ['session' => $lain])
            ->set('purpose', 'otp')
            ->call('save')
            ->assertHasErrors('purpose');

        expect($lain->fresh()->purpose)->toBeNull();
    });

    it('memvalidasi format purpose', function () {
        $session = makeSessionDeviceDashboardTest();

        Livewire::test(DeviceForm::class, ['session' => $session])
            ->set('purpose', ' Purpose Dengan Spasi! ')
            ->call('save')
            ->assertHasErrors('purpose');

        expect($session->fresh()->purpose)->toBeNull();
    });

    it('purpose tidak bisa ditulis lewat mass assignment', function () {
        $session = makeSessionDeviceDashboardTest();

        // fill() menyaring berdasarkan $fillable, jadi purpose diabaikan.
        $session->fill(['purpose' => 'otp', 'display_name' => 'nama baru']);
        $session->save();

        expect($session->fresh()->purpose)->toBeNull()
            ->and($session->fresh()->display_name)->toBe('nama baru');
    });

    it('sync-devices tidak boleh menimpa purpose milik admin', function () {
        $session = makeSessionDeviceDashboardTest();
        $session->forceFill(['purpose' => 'otp'])->save();

        // -fillable interplay: sync memakai fill() dengan purpose tidak ada.
        $session->fill([
            'device_id' => 'org_2',
            'display_name' => 'nama baru dari gateway',
            'is_logged_in' => true,
        ])->save();

        expect($session->fresh()->purpose)->toBe('otp')
            ->and($session->fresh()->display_name)->toBe('nama baru dari gateway');
    });
});

describe('indeks dan kolom tabel', function () {
    it('purpose punya index unique', function () {
        $indexes = Schema::getIndexes('wara_sessions');
        $purposeIndex = collect($indexes)->first(fn ($i) => in_array('purpose', $i['columns'], true));

        expect($purposeIndex)->not->toBeNull()
            ->and($purposeIndex['unique'])->toBeTrue();
    });

    it('device_id punya index unique', function () {
        $indexes = Schema::getIndexes('wara_sessions');
        $deviceIndex = collect($indexes)->first(fn ($i) => in_array('device_id', $i['columns'], true));

        expect($deviceIndex)->not->toBeNull()
            ->and($deviceIndex['unique'])->toBeTrue();
    });

    it('purpose ganda ditolak database', function () {
        $a = makeSessionDeviceDashboardTest();
        $a->forceFill(['purpose' => 'otp'])->save();

        $b = makeSessionDeviceDashboardTest();

        expect(fn () => $b->forceFill(['purpose' => 'otp'])->save())
            ->toThrow(UniqueConstraintViolationException::class);
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
