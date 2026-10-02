<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceHeader;
use Bale\Wara\Livewire\Pages\Landlord\Log\Section\LogTable;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.offline_alert_after_minutes', 10);

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('wara-admin', 'web'));
    $this->actingAs($this->user);
});

function makeDevice(array $overrides = []): WaraSession
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

describe('peringatan §8.3', function () {
    it('device sehat tidak masuk daftar perhatian', function () {
        makeDevice();

        $component = new DeviceHeader;

        expect($component->needsReattention())->toHaveCount(0)
            ->and($component->recentlyUnhealthy())->toHaveCount(0);
    });

    it('device yang sudah lama tidak siap menjadi peringatan', function () {
        makeDevice([
            'is_logged_in' => false,
            'is_connected' => false,
            'last_seen_at' => now()->subHour(),
        ]);

        $component = new DeviceHeader;

        expect($component->needsReattention())->toHaveCount(1);
    });

    it('device yang baru tidak siap masih dalam masa tenggang', function () {
        makeDevice(['is_logged_in' => false, 'is_connected' => false, 'last_seen_at' => now()]);

        $component = new DeviceHeader;

        expect($component->needsReattention())->toHaveCount(0)
            ->and($component->recentlyUnhealthy())->toHaveCount(1);
    });

    it('device yang hilang dari gateway tidak dihitung sebagai peringatan', function () {
        makeDevice([
            'is_present' => false,
            'is_logged_in' => false,
            'is_connected' => false,
            'last_seen_at' => now()->subHour(),
        ]);

        $component = new DeviceHeader;

        expect($component->needsReattention())->toHaveCount(0)
            ->and($component->recentlyUnhealthy())->toHaveCount(0);
    });

    it('ambang ditentukan config offline_alert_after_minutes', function () {
        config()->set('wara.offline_alert_after_minutes', 60);

        makeDevice([
            'is_logged_in' => false,
            'is_connected' => false,
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $component = new DeviceHeader;

        // 30 menit < ambang 60 menit, jadi masih tenggang.
        expect($component->needsReattention())->toHaveCount(0)
            ->and($component->recentlyUnhealthy())->toHaveCount(1);
    });
});

describe('tampilan halaman device', function () {
    it('menampilkan peringatan untuk device yang perlu dipasang ulang', function () {
        makeDevice([
            'is_logged_in' => false,
            'is_connected' => false,
            'last_seen_at' => now()->subHour(),
        ]);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Device perlu dipasang ulang'))
            ->assertSee(__('Gateway tidak melaporkan alasan disconnect, jadi ini belum berarti akun diblokir.'));
    });

    it('menampilkan catatan tenggang untuk device yang baru tidak siap', function () {
        makeDevice(['is_logged_in' => false, 'is_connected' => false, 'last_seen_at' => now()]);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Device sedang tidak siap'))
            ->assertSee(__('Masih dalam masa tenggang sebelum ditandai sebagai perlu dipasang ulang.'));
    });

    it('tidak menampilkan peringatan saat semua device sehat', function () {
        makeDevice();

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Device perlu dipasang ulang'))
            ->assertDontSee(__('Device sedang tidak siap'));
    });

    it('dropdown device pada halaman log memuat device dari katalog', function () {
        makeDevice(['display_name' => 'Aduan Agent']);

        $component = Livewire::test(LogTable::class);

        // Dropdown berada di dalam slot data-table, jadi yang diperiksa adalah
        // property yang jadi sumber datanya.
        expect($component->get('devices')->pluck('display_name')->all())
            ->toContain('Aduan Agent');
    });
});

describe('permission', function () {
    it('tiga permission wara di-seed oleh wara:install', function () {
        $this->artisan('wara:install')->assertSuccessful();

        $names = Permission::query()
            ->where('name', 'like', 'wara.%')
            ->pluck('name')
            ->all();

        expect($names)->toContain('wara.device.read', 'wara.device.update', 'wara.log.read');
    });
});
