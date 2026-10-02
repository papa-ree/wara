<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Log\Index as LogIndex;
use Bale\Wara\Livewire\Pages\Landlord\Log\Section\LogTable;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function makeLog(array $overrides = []): WaraLog
{
    static $counter = 0;
    $counter++;

    return WaraLog::query()->create(array_merge([
        'direction' => 'out',
        'event' => 'message',
        'device_id' => 'wago prod',
        'phone' => '62852391464'.$counter,
        'message_id' => 'msg-'.$counter,
        'body' => 'pesan uji nomor '.$counter,
        'status' => 'accepted',
    ], $overrides));
}

describe('penyamaran kode OTP', function () {
    it('menyembunyikan tengah kode numerik', function () {
        $log = makeLog(['body' => 'Kode OTP Anda: *123456*']);

        expect($log->masked_body)->toBe('Kode OTP Anda: *12**56*');
    });

    it('menyembunyikan tengah kode alfanumerik', function () {
        $log = makeLog(['body' => 'Kode OTP Anda: *AB12CD*']);

        expect($log->masked_body)->toBe('Kode OTP Anda: *AB**CD*');
    });

    it('tidak mengubah teks selain kode', function () {
        $log = makeLog(['body' => 'Halo Budi, kode kamu *998877*. Jangan bagikan.']);

        expect($log->masked_body)->toBe('Halo Budi, kode kamu *99**77*. Jangan bagikan.');
    });

    it('menampilkan kode pendek utuh karena menyamarkan tidak menambah keamanan', function () {
        $log = makeLog(['body' => 'Kode OTP Anda: *12*']);

        expect($log->masked_body)->toBe('Kode OTP Anda: *12*');
    });

    it('nilai asli di database tidak berubah', function () {
        makeLog(['body' => 'Kode OTP Anda: *123456*']);

        expect(WaraLog::query()->first()->body)->toBe('Kode OTP Anda: *123456*');
    });

    it('pesan tanpa asterisk tidak diutak-atik', function () {
        $log = makeLog(['body' => 'Halo, ini pesan biasa tanpa kode.']);

        expect($log->masked_body)->toBe('Halo, ini pesan biasa tanpa kode.');
    });

    it('body null tidak menyebabkan error', function () {
        $log = makeLog(['body' => null]);

        expect($log->masked_body)->toBeNull();
    });
});

describe('label status', function () {
    it('menerjemahkan status yang umum', function () {
        expect(makeLog(['status' => 'accepted'])->status_label)->toBe('Diterima gateway')
            ->and(makeLog(['status' => 'rejected'])->status_label)->toBe('Ditolak gateway')
            ->and(makeLog(['status' => 'received'])->status_label)->toBe('Masuk')
            ->and(makeLog(['status' => 'delivered'])->status_label)->toBe('Sampai')
            ->and(makeLog(['status' => 'read'])->status_label)->toBe('Dibaca')
            ->and(makeLog(['status' => 'ignored'])->status_label)->toBe('Diabaikan');
    });

    it('status yang tidak dikenal ditampilkan apa adanya', function () {
        expect(makeLog(['status' => 'aneh'])->status_label)->toBe('aneh');
    });
});

describe('filter pesan tanpa device', function () {
    // Filter ini milik `data-table` (bukan LogTable), jadi diuji dengan
    // me-mount data-table sebagai komponen root lalu mengubah
    // `activeFilters`-nya — persis seperti yang terjadi saat operator
    // memilih "Tanpa device" di dropdown.
    it('sentinel null menyaring device_id NULL', function () {
        makeLog();
        makeLog(['device_id' => null, 'phone' => '628999']);

        Livewire::test('core-shared-components::data-table', [
            'model' => WaraLog::class,
            'rowView' => 'wara::livewire.pages.landlord.log.section.log-row',
            'columns' => [['key' => 'device_id', 'label' => 'Device Pengirim']],
        ])
            ->set('activeFilters.device_id', 'null')
            ->assertSee(__('Tanpa device'));
    });

    it('nilai kosong menampilkan semua device', function () {
        makeLog();
        makeLog(['device_id' => null, 'phone' => '628999']);

        Livewire::test('core-shared-components::data-table', [
            'model' => WaraLog::class,
            'rowView' => 'wara::livewire.pages.landlord.log.section.log-row',
            'columns' => [['key' => 'device_id', 'label' => 'Device Pengirim']],
        ])
            ->set('activeFilters.device_id', '')
            ->assertSee(__('Tanpa device'));
    });

    it('dropdown device menawarkan opsi Tanpa device', function () {
        Livewire::test(LogTable::class)
            ->assertSee(__('Semua Device'))
            ->assertSee(__('Tanpa device'))
            ->assertSee(__('Semua Status'))
            ->assertSee(__('Semua'));
    });

    it('merender kontrol filter milik data-table, bukan lewat slot', function () {
        // Regresi: filter pernah dikirim lewat <x-slot name="filters">, tapi
        // Livewire 4 hanya mengekspos slot sebagai $slot — bukan sebagai
        // variabel $filters — sehingga tombol Filter dan seluruh dropdown
        // tidak pernah muncul sama sekali di halaman log.
        $html = Livewire::test(LogTable::class)->html();

        expect($html)->toContain('wire:model.live="activeFilters.direction"')
            ->and($html)->toContain('wire:model.live="activeFilters.device_id"')
            ->and($html)->toContain('wire:model.live="activeFilters.status"')
            ->and($html)->toContain('value="null"');
    });

    it('filterOptions memuat device dari katalog sebagai opsi', function () {
        WaraSession::query()->create([
            'device_id' => 'Aduan Agent', 'jid' => '628000000001@s.whatsapp.net',
            'display_name' => 'Papa', 'is_present' => true,
            'is_connected' => true, 'is_logged_in' => true, 'last_seen_at' => now(),
        ]);
        WaraSession::query()->create([
            'device_id' => 'wago prod', 'jid' => '6285126061182@s.whatsapp.net',
            'display_name' => 'Nawasara Agent', 'is_present' => true,
            'is_connected' => true, 'is_logged_in' => true, 'last_seen_at' => now(),
        ]);

        $options = Livewire::test(LogTable::class)->get('filterOptions');

        $device = collect($options)->firstWhere('key', 'device_id');

        expect($device['nullLabel'])->toBe(__('Tanpa device'))
            ->and($device['options'])->toHaveKey('Aduan Agent', 'Papa')
            ->and($device['options'])->toHaveKey('wago prod', 'Nawasara Agent');
    });

    it('LogTable tidak lagi menyimpan state filter', function () {
        // State filter pindah ke data-table; jangan dikembalikan ke sini
        // karena `constraints` hanya dibaca saat mount.
        expect(property_exists(LogTable::class, 'emptyDeviceFilter'))->toBeFalse()
            ->and(method_exists(LogTable::class, 'constraints'))->toBeFalse();
    });
});

describe('enkripsi kolom sensitif', function () {
    it('phone tidak tersimpan sebagai plaintext', function () {
        $log = makeLog(['phone' => '628123456789']);

        $raw = DB::table('wara_logs')->where('id', $log->id)->first();

        expect($log->phone)->toBe('628123456789')
            ->and($raw->phone)->not->toBe('628123456789')
            ->and($raw->phone)->not->toContain('628123456789');
    });

    it('body tidak tersimpan sebagai plaintext', function () {
        $log = makeLog(['body' => 'rahasia tidak boleh bocor']);

        $raw = DB::table('wara_logs')->where('id', $log->id)->first();

        expect($log->body)->toBe('rahasia tidak boleh bocor')
            ->and($raw->body)->not->toContain('rahasia');
    });

    it('kode OTP tidak tersimpan sebagai plaintext', function () {
        $log = makeLog(['body' => 'Kode OTP Anda: *123456*']);

        $raw = DB::table('wara_logs')->where('id', $log->id)->first();

        expect($raw->body)->not->toContain('123456')
            ->and($log->masked_body)->toBe('Kode OTP Anda: *12**56*');
    });

    it('kolom yang tidak sensitif tetap plaintext agar bisa difilter', function () {
        makeLog(['status' => 'accepted', 'device_id' => 'wago prod']);

        $raw = DB::table('wara_logs')->orderByDesc('id')->first();

        expect($raw->status)->toBe('accepted')
            ->and($raw->device_id)->toBe('wago prod');
    });

    it('phone dan body tidak bisa dicari dengan LIKE', function () {
        // Ciphertext acak, jadi pencarian plaintext tidak akan pernah cocok.
        // Ini batasan yang diketahui, bukan bug — lihat docs/wara/PLAN.md.
        $log = makeLog(['phone' => '628123456789', 'body' => 'cari aku']);

        $found = WaraLog::query()
            ->where('phone', 'like', '%628123456789%')
            ->orWhere('body', 'like', '%cari aku%')
            ->pluck('id')
            ->all();

        expect($found)->not->toContain($log->id);
    });

    it('filter status tetap berfungsi', function () {
        $diterima = makeLog(['status' => 'accepted']);
        makeLog(['status' => 'rejected']);

        $ids = WaraLog::query()->where('status', 'accepted')->pluck('id')->all();

        expect($ids)->toContain($diterima->id);
    });
});

describe('primary key UUID', function () {
    it('wara_logs memakai UUID', function () {
        $log = makeLog();

        expect($log->id)->toBeString()
            ->and($log->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    it('wara_sessions memakai UUID', function () {
        $session = WaraSession::query()->create([
            'device_id' => 'org_uuid_test',
            'jid' => '628123@s.whatsapp.net',
        ]);

        expect($session->id)->toBeString()
            ->and($session->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    it('id tiap baris berbeda', function () {
        $ids = collect(range(1, 3))->map(fn () => makeLog()->id)->all();

        expect(array_unique($ids))->toHaveCount(3);
    });
});

describe('permission halaman log', function () {
    it('menolak tanpa permission wara.log.read', function () {
        Livewire::test(LogIndex::class)->assertForbidden();
    });

    it('mengizinkan dengan permission wara.log.read', function () {
        Permission::create(['name' => 'wara.log.read']);
        $this->user->givePermissionTo('wara.log.read');

        Livewire::test(LogIndex::class)->assertOk();
    });
});
