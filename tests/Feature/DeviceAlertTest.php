<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceHeader;
use Bale\Wara\Livewire\Pages\Landlord\Log\Section\LogTable;
use Bale\Wara\Models\WaraLog;
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

/*
 * Peringatan sesi login habis (§8.2).
 *
 * Ini BUKAN masalah device. Sesi habis tidak ada hubungannya dengan pairing
 * atau koneksi — nomor masih aktif, tapi satu sesi login sudah menghabiskan
 * jatah percobaannya. Karena itu test-nya dipisah dari blok `peringatan §8.3`
 * di atas: kalau dicampur, test "tidak menampilkan peringatan saat semua
 * device sehat" akan punya dua arti yang berbeda.
 */
describe('peringatan sesi login habis', function () {
    /*
     * 🔴 `created_at` SENGAJA ditulis lewat query terpisah, bukan `create()`.
     *
     * Kolom itu tidak ada di `$fillable` `WaraLog`, jadi nilai yang diberikan
     * diabaikan tanpa error dan baris tetap ber-"sekarang". Dua test di bawah
     * bergantung pada umur baris — kalau ini diabaikan, keduanya akan lulus
     * dengan alasan salah: bukan karena ambangnya benar, tapi karena setiap baris
     * selalu dianggap baru.
     */
    function makeSessionLog(array $overrides = []): WaraLog
    {
        static $counter = 0;
        $counter++;

        $log = WaraLog::query()->create(array_merge([
            'direction' => 'out',
            'event' => 'message',
            'device_id' => 'Aduan Agent',
            'body' => 'percobaan OTP',
            'status' => 'accepted',
            'session_key' => 'sesi-'.$counter,
            'attempt' => 3,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $log->forceFill(['created_at' => $overrides['created_at']])->save();
            $log->touch();

            // 🔴 Diverifikasi, bukan diasumsikan. Kalau `forceFill` diam-diam
            // tidak bekerja, test di bawah kembali lulus untuk alasan salah.
            expect($log->fresh()->created_at->timestamp)
                ->toBe($overrides['created_at']->timestamp);
        }

        return $log->fresh();
    }

    it('🔴 banner muncul saat sesi menyentuh batas', function () {
        makeSessionLog(['attempt' => 3, 'session_key' => 'sesi-habis']);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Sesi login habis percobaan'))
            ->assertSee('sesi-habis');
    });

    it('🔴 banner tidak muncul saat semua device sehat dan tidak ada sesi habis', function () {
        // 🔴 Test ini yang paling penting: tanpa data sesi, banner HARUS hilang.
        // Kalau guard di `scopeExhausted()` atau di `DeviceHeader` dihapus,
        // banner akan muncul untuk setiap halaman device yang dibuka.
        makeDevice();

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'))
            ->assertDontSee(__('Device perlu dipasang ulang'));
    });

    it('🔴 attempt di bawah batas tidak memicu banner', function () {
        makeSessionLog(['attempt' => 1, 'session_key' => 'sesi-aman']);
        makeSessionLog(['attempt' => 2, 'session_key' => 'sesi-aman-2']);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));
    });

    it('🔴 sesi lama di luar ambang tidak memicu banner', function () {
        // `created_at` yang controlling, bukan `attempt`: sesi ini memang habis
        // secara historis, tapi sudah auto-unblock sehingga tidak ada yang bisa
        // dilakukan admin padanya sekarang.
        makeSessionLog([
            'attempt' => 3,
            'session_key' => 'sesi-lama',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));
    });

    it('🔴 ambang ditentukan config exhausted_alert_after_minutes', function () {
        config()->set('wara.exhausted_alert_after_minutes', 5);

        makeSessionLog([
            'attempt' => 3,
            'session_key' => 'sesi-10-menit',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));

        config()->set('wara.exhausted_alert_after_minutes', 30);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Sesi login habis percobaan'));
    });

    it('🔴 config nol mematikan banner sepenuhnya', function () {
        config()->set('wara.exhausted_alert_after_minutes', 0);

        makeSessionLog(['attempt' => 3, 'session_key' => 'sesi-dimatkan']);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));
    });

    it('🔴 batas configured nol mematikan banner, bukan membuatnya universal', function () {
        // 🔴 `WARA_MAX_ATTEMPTS_PER_SESSION=0` berarti TIDAK ADA BATAS. Kalau
        // `scopeExhausted()` tidak menjaganya, `attempt >= 0` membuat SETIAP
        // baris log terbaca "habis" dan banner muncul tanpa henti.
        config()->set('wara.max_attempts_per_session', 0);

        makeSessionLog(['attempt' => 1, 'session_key' => 'sesi-batas-mati-1']);
        makeSessionLog(['attempt' => 7, 'session_key' => 'sesi-batas-mati-2']);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));
    });

    it('🔴 banner menyebut device pemanggil dan jendela yang tersisa', function () {
        config()->set('wara.session_window_seconds', 1800);

        makeSessionLog(['attempt' => 3, 'session_key' => 'sesi-info', 'device_id' => 'Aduan Agent']);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('device :device', ['device' => 'Aduan Agent']))
            ->assertSee(__('Percobaan berikutnya untuk sesi ini ditolak sampai jendela :minutes menit lewat. Sesi baru tetap berjalan normal.', ['minutes' => 30]));
    });

    it('🔴 satu sesi dengan beberapa percobaan hanya ditampilkan sekali', function () {
        // 🔴 Fixture-nya harus benar-benar menghasilkan DUPLIKAT. Versi test
        // sebelumnya memakai attempt 1/2/3 untuk satu sesi — itu tidak pernah
        // menguji apa pun, karena `scopeExhausted()` sudah memfilter
        // `attempt >= max`, jadi hanya baris attempt 3 yang lolos. `groupBy`
        // bisa dihapus sepuasnya dan test tetap hijau.
        //
        // Dua baris dengan attempt 3 untuk sesi yang sama itu nyata: kalau cache
        // penghitung sesi hilang (flush, ganti store, restart Redis), attempt
        // mulai lagi dari 1 sementara baris attempt 3 yang lama masih ada.
        makeSessionLog(['attempt' => 3, 'session_key' => 'sesi-ganda']);
        makeSessionLog(['attempt' => 3, 'session_key' => 'sesi-ganda']);

        $html = Livewire::test(DeviceHeader::class)->html();

        expect(substr_count($html, 'sesi-ganda'))->toBe(1);
    });

    it('🔴 baris log tanpa session_key tidak masuk banner', function () {
        makeSessionLog(['attempt' => 3, 'session_key' => null]);

        Livewire::test(DeviceHeader::class)
            ->assertDontSee(__('Sesi login habis percobaan'));
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
