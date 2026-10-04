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

/*
 * P1 docs/wara/TODO.md: `session_key` + `attempt` sudah tersimpan sejak PRD §8.2,
 * tapi tidak pernah sampai ke layar. Test di sini mengunci pemunculannya,
 * bukan hanya keberadaan datanya — `SendPacingTest` sudah membuktikan kolomnya
 * terisi, jadi mengulang itu di sini hanya menguji ulang hal yang sama.
 */
describe('sesi percobaan di halaman log', function () {
    it('🔴 attempt memakai batas dari config, bukan angka hardcode', function () {
        config()->set('wara.max_attempts_per_session', 3);

        expect(makeLog(['attempt' => 2, 'session_key' => 'sesi-a'])->session_attempt_label)
            ->toBe('2/3');

        // 🔴 Kalau angkanya dipatok di dalam view atau accessor, config di
        // environment tidak akan pernah mengubah apa yang tampil.
        config()->set('wara.max_attempts_per_session', 5);

        expect(makeLog(['attempt' => 2, 'session_key' => 'sesi-a'])->session_attempt_label)
            ->toBe('2/5');
    });

    it('🔴 baris tanpa session_key tidak punya label percobaan', function () {
        $log = makeLog(['session_key' => null, 'attempt' => null]);

        expect($log->session_attempt_label)->toBeNull()
            ->and($log->session_state)->toBe(WaraLog::SESSION_NONE);
    });

    it('🔴 baris dengan session_key tapi tanpa attempt diperlakukan sebagai none', function () {
        // Baris lama sebelum kolom `attempt` diisi. Menampilkan "0/3" untuknya
        // akan menyesatkan: terlihat seperti ada percobaan, padahal tidak ada
        // yang tercatat.
        $log = makeLog(['session_key' => 'sesi-lama', 'attempt' => null]);

        expect($log->session_attempt_label)->toBeNull()
            ->and($log->session_state)->toBe(WaraLog::SESSION_NONE);
    });

    it('🔴 attempt di bawah batas berstatus active', function () {
        config()->set('wara.max_attempts_per_session', 3);

        expect(makeLog(['session_key' => 'sesi-b', 'attempt' => 2])->session_state)
            ->toBe(WaraLog::SESSION_ACTIVE);
    });

    it('🔴 attempt yang menyentuh batas berstatus last, bukan active', function () {
        config()->set('wara.max_attempts_per_session', 3);

        expect(makeLog(['session_key' => 'sesi-c', 'attempt' => 3])->session_state)
            ->toBe(WaraLog::SESSION_LAST);
    });

    it('🔴 batas nol atau negatif tidak membuat semua baris jadi last', function () {
        // `WARA_MAX_ATTEMPTS_PER_SESSION=0` berarti gateway tidak boleh mengirim
        // sama sekali. Tanpa clamp, `attempt >= 0` membuat SETIAP baris — termasuk
        // yang `attempt`-nya null — terbaca sebagai percobaan terakhir.
        config()->set('wara.max_attempts_per_session', 0);

        expect(makeLog(['session_key' => 'sesi-d'])->maxSessionAttempts())->toBe(1)
            ->and(makeLog(['session_key' => 'sesi-d', 'attempt' => null])->session_state)
            ->toBe(WaraLog::SESSION_NONE);
    });

    it('🔴 kolom Percobaan muncul di header tabel', function () {
        $html = Livewire::test(LogTable::class)->html();

        expect($html)->toContain(__('Percobaan'));
    });

    it('🔴 attempt dan session_key ter-render di baris', function () {
        makeLog(['session_key' => 'sesi-render', 'attempt' => 2, 'body' => 'baris bersesi']);
        makeLog(['session_key' => null, 'attempt' => null, 'body' => 'baris tanpa sesi']);

        $html = Livewire::test(LogTable::class)->html();

        // 🔴 Regex, bukan `toContain('2/3')`. Badge-nya dirender sebagai teks
        // satu-satunya di antara dua tag, jadi pola `>\s*2/3\s*<` hanya cocok
        // pada badge. Kalau pakai `toContain`, class Tailwind seperti
        // `indigo-900/30` ikut memuat "0/3" dan assertion negatifnya bocor.
        expect($html)->toMatch('/>\s*2\/3\s*</')
            ->and($html)->toContain('sesi-render')
            ->and($html)->toContain('baris bersesi');
    });

    it('🔴 baris tanpa sesi tidak menampilkan angka percobaan', function () {
        makeLog(['session_key' => null, 'attempt' => null, 'body' => 'tanpa sesi sama sekali']);

        $html = Livewire::test(LogTable::class)->html();

        // Lihat catatan di test sebelumnya: `toContain('0/3')` salah positif
        // karena ada class `...-900/30` di markup tabel.
        expect($html)->toContain('tanpa sesi sama sekali')
            ->and($html)->not->toMatch('/>\s*\d+\/\d+\s*</');
    });

    it('🔴 session_key bisa dicari lewat kotak pencarian', function () {
        // 🔴 Dikasih nilai yang TIDAK muncul di kolom lain, supaya test ini benar
        // membuktikan pencarian lewat `session_key`. Kalau `searchable` cuma
        // berisi `message_id` dan `device_id`, baris ini tidak akan pernah muncul.
        makeLog(['session_key' => 'cari-saya-di-sesi', 'body' => 'isi pesan biasa']);
        makeLog(['session_key' => 'sesi-lain', 'body' => 'isi pesan lain']);

        // 🔴 `searchable` diambil dari file blade yang sungguhan, lalu dipakai
        // untuk me-mount data-table sebagai komponen root.
        //
        // Dua-duanya penting. `->set('query')` pada LogTable TIDAK bisa
        // dipakai: `query` milik komponen ANAK (data-table), jadi Livewire
        // melempar PublicPropertyNotFoundException. Dan menyalin array
        // `searchable` secara manual di test akan membuat test tetap hijau
        // setelah `session_key` dihapus dari file.
        $table = file_get_contents(dirname(__DIR__, 2)
            .'/resources/views/livewire/pages/landlord/log/section/log-table.blade.php');

        preg_match('/:searchable="\[(.*?)\]"/s', $table, $match);

        expect($match)->toHaveKey(1)
            ->and($match[1])->toContain('session_key');

        // 🔴 Kutip DAN spasi harus dibuang. Kalau tidak, nama kolomnya jadi
        // `'session_key'` (dengan tanda kutip ikut) dan `LIKE` tidak akan
        // pernah cocok — test gagal dengan pesan yang menyesatkan.
        $searchable = array_map(
            fn (string $column) => trim($column, " '\"\t\n\r"),
            explode(',', $match[1])
        );

        expect($searchable)->toBe(['message_id', 'device_id', 'session_key']);

        $found = Livewire::test('core-shared-components::data-table', [
            'model' => WaraLog::class,
            'rowView' => 'wara::livewire.pages.landlord.log.section.log-row',
            'columns' => [['key' => 'body', 'label' => 'Isi Pesan']],
            'searchable' => $searchable,
        ])
            ->set('query', 'cari-saya-di-sesi')
            ->html();

        expect($found)->toContain('isi pesan biasa')
            ->and($found)->not->toContain('isi pesan lain');
    });

    it('🔴 session_key tetap plaintext agar bisa dicari', function () {
        // Kontras dengan `phone`/`body` yang terenkripsi dan tidak bisa di-LIKE.
        // Kalau `session_key` ikut dienkripsi, filter di atas akan diam-diam
        // mengembalikan nol baris.
        $log = makeLog(['session_key' => 'sesi-plain', 'phone' => '628123456789']);

        $raw = DB::table('wara_logs')->where('id', $log->id)->first();

        expect($raw->session_key)->toBe('sesi-plain')
            ->and($raw->phone)->not->toContain('628123456789');
    });

    it('🔴 Jumlah sel sama dengan jumlah kolom', function () {
        // Header dan body harus punya jumlah sel sama. Kalau tidak, tabel akan
        // bergeser diam-diam: setiap baris punya satu kolom terlalu sedikit dan
        // badge Percobaan justru jatuh ke kolom Waktu.
        $row = file_get_contents(dirname(__DIR__, 2)
            .'/resources/views/livewire/pages/landlord/log/section/log-row.blade.php');

        $table = file_get_contents(dirname(__DIR__, 2)
            .'/resources/views/livewire/pages/landlord/log/section/log-table.blade.php');

        expect(substr_count($row, '<td '))->toBe(substr_count($table, "'key' =>"));
    });
});
