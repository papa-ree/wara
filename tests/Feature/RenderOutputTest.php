<?php

use App\Models\User;
use Bale\Wara\Livewire\Pages\Landlord\Device\Section\DeviceHeader;
use Bale\Wara\Livewire\Pages\Landlord\Log\Section\LogTable;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($user);

    $this->otpDevice = WaraSession::query()->create([
        'device_id' => 'Aduan Agent',
        'jid' => '628000000001@s.whatsapp.net',
        'display_name' => 'Papa',
        'is_present' => true, 'is_connected' => true, 'is_logged_in' => true,
        'purpose' => 'otp',
        'last_seen_at' => now(),
    ]);

    $this->notifDevice = WaraSession::query()->create([
        'device_id' => 'wago prod',
        'jid' => '6285126061182@s.whatsapp.net',
        'display_name' => 'Nawasara Agent',
        'is_present' => true, 'is_connected' => true, 'is_logged_in' => true,
        'purpose' => 'notifikasi',
        'last_seen_at' => now(),
    ]);
});

/**
 * Mount data-table sebagai komponen root.
 *
 * Filter di halaman log milik `data-table`, bukan `LogTable`. `Livewire::test()
 * ->set()` hanya bisa mengubah state komponen root, jadi `data-table` di-mount
 * langsung di sini agar filternya bisa benar-benar diuji — lewat filter,
 * bukan lewat assertion pada markup parent.
 */
function mountDataTable(array $extra = [])
{
    return Livewire::test('core-shared-components::data-table', array_merge([
        'model' => WaraLog::class,
        'rowView' => 'wara::livewire.pages.landlord.log.section.log-row',
        'columns' => [
            ['key' => 'body', 'label' => 'Isi Pesan'],
            ['key' => 'device_id', 'label' => 'Device Pengirim'],
        ],
    ], $extra));
}

function seedMixedDeviceRows(): void
{
    WaraLog::query()->create([
        'direction' => 'out', 'device_id' => 'wago prod',
        'body' => 'ada device', 'status' => 'accepted',
    ]);
    WaraLog::query()->create([
        'direction' => 'in', 'device_id' => null,
        'body' => 'tanpa device', 'status' => 'received',
    ]);
}

describe('penyaringan NULL lewat sentinel', function () {
    it('sentinel "null" menghasilkan whereNull device_id', function () {
        seedMixedDeviceRows();

        $table = mountDataTable()->set('activeFilters.device_id', 'null');

        $table->assertSee('tanpa device')
            ->assertDontSee('ada device');
    });

    it('nilai kosong berarti semua device', function () {
        seedMixedDeviceRows();

        $table = mountDataTable()->set('activeFilters.device_id', '');

        $table->assertSee('tanpa device')
            ->assertSee('ada device');
    });

    it('sentinel "null" tidak menyaring kolom lain', function () {
        seedMixedDeviceRows();

        $table = mountDataTable()->set('activeFilters.device_id', 'null');

        $table->assertSee('tanpa device')
            ->assertDontSee('ada device');
    });

    it('nama device biasa tetap dicocokkan sebagai nilai biasa', function () {
        seedMixedDeviceRows();

        $table = mountDataTable()->set('activeFilters.device_id', 'wago prod');

        $table->assertSee('ada device')
            ->assertDontSee('tanpa device');
    });

    it('sentinel "null" menghasilkan whereNull di SQL', function () {
        seedMixedDeviceRows();

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'wara_logs')) {
                $queries[] = $query->sql;
            }
        });

        mountDataTable()->set('activeFilters.device_id', 'null');

        expect($queries)->not->toBeEmpty()
            ->and(collect($queries)->contains(fn ($s) => str_contains($s, 'is null')))->toBeTrue();
    });
});

describe('dropdown filter lain', function () {
    it('filter arah menyaring kolom direction', function () {
        WaraLog::query()->create([
            'direction' => 'out', 'device_id' => 'wago prod',
            'body' => 'pesan keluar', 'status' => 'accepted',
        ]);
        WaraLog::query()->create([
            'direction' => 'in', 'device_id' => 'wago prod',
            'body' => 'pesan masuk', 'status' => 'received',
        ]);

        $table = mountDataTable()->set('activeFilters.direction', 'in');

        $table->assertSee('pesan masuk')
            ->assertDontSee('pesan keluar');
    });

    it('filter status menyaring kolom status', function () {
        WaraLog::query()->create([
            'direction' => 'out', 'device_id' => 'wago prod',
            'body' => 'ditolak', 'status' => 'rejected',
        ]);
        WaraLog::query()->create([
            'direction' => 'out', 'device_id' => 'wago prod',
            'body' => 'diterima', 'status' => 'accepted',
        ]);

        $table = mountDataTable()->set('activeFilters.status', 'rejected');

        $table->assertSee('ditolak')
            ->assertDontSee('diterima');
    });
});

describe('tabel log terender dengan data nyata', function () {
    beforeEach(function () {
        // Bentuk data persis seperti yang terjadi saat tes ke GOWA sungguhan.
        WaraLog::query()->create([
            'direction' => 'out', 'event' => 'message', 'device_id' => 'Aduan Agent',
            'phone' => '628000000001', 'message_id' => '3EB0886B59ED2D0EEB928C',
            'body' => 'Kode OTP Anda: *111111*', 'status' => 'accepted',
            'session_key' => 'demo-sesi', 'attempt' => 1,
        ]);

        WaraLog::query()->create([
            'direction' => 'out', 'event' => 'message', 'device_id' => 'wago prod',
            'phone' => '628000000001', 'message_id' => null,
            'body' => 'Kode OTP Anda: *778899*', 'status' => 'rejected',
            'session_key' => 'demo-sesi', 'attempt' => 4,
        ]);

        WaraLog::query()->create([
            'direction' => 'in', 'event' => 'message', 'device_id' => 'wago prod',
            'phone' => '628000000001', 'message_id' => 'wamid.incoming',
            'body' => 'Kode OTP saya 334455', 'status' => 'received',
        ]);

        WaraLog::query()->create([
            'direction' => 'in', 'event' => 'message.ack', 'device_id' => 'wago prod',
            'phone' => null, 'message_id' => '3EB0CCB6DA15162CA260B9', 'status' => 'delivered',
        ]);

        WaraLog::query()->create([
            'direction' => 'in', 'event' => 'message', 'device_id' => null,
            'phone' => '628777666555', 'message_id' => 'wamid.unknown',
            'body' => 'Pesan dari device tak dikenal', 'status' => 'received',
        ]);
    });

    it('menampilkan device pengirim dan arah untuk tiap baris', function () {
        Livewire::test(LogTable::class)
            ->assertSee('Aduan Agent')
            ->assertSee('wago prod');
    });

    it('membedakan status diterima, ditolak, dan sampai', function () {
        Livewire::test(LogTable::class)
            ->assertSee(__('Diterima gateway'))
            ->assertSee(__('Ditolak gateway'))
            ->assertSee(__('Sampai'));
    });

    it('menyembunyikan kode OTP pada pesan keluar', function () {
        Livewire::test(LogTable::class)
            ->assertSee('Kode OTP Anda: *11**11*')
            ->assertSee('Kode OTP Anda: *77**99*')
            ->assertDontSee('*111111*')
            ->assertDontSee('*778899*');
    });

    it('menandai pesan tanpa device secara eksplisit', function () {
        Livewire::test(LogTable::class)
            ->assertSee(__('Tanpa device'))
            ->assertSee('Pesan dari device tak dikenal');
    });

    it('menampilkan placeholder untuk pesan tanpa isi seperti message.ack', function () {
        Livewire::test(LogTable::class)->assertSee(__('(tanpa isi)'));
    });

    it('menampilkan nomor tujuan pada baris pesan keluar', function () {
        Livewire::test(LogTable::class)->assertSee('628000000001');
    });

    it('isi pesan tidak didorong keluar kotak oleh whitespace Blade', function () {
        // Regresi: elemen <p> memakai `whitespace-pre-line`, dan Blade
        // comment di dalamnya meninggalkan baris baru yang `pre-line`
        // hitung sebagai line break. Teks terdorong ke baris ke-3 lalu
        // terpotong `line-clamp-2` — di browser kolom ini hanya tampil "...".
        //
        // Test ini WAJIB memeriksa HTML mentah: `innerText` dan accessibility
        // tree tetap memuat teks yang terklip, jadi keduanya selalu hijau
        // walau teksnya tidak terlihat. Itu sebabnya bug ini bisa lolos
        // dari seluruh suite dan baru ketahuan saat verifikasi browser.
        $html = Livewire::test(LogTable::class)->html();

        preg_match(
            '/<p class="[^"]*whitespace-pre-line line-clamp-2">(.*?)<\/p>/s',
            $html,
            $matches
        );

        expect($matches)->not->toBeEmpty('elemen <p> isi pesan tidak ditemukan');

        // Isi harus mulai langsung dengan teks — tanpa spasi/newline sisa.
        expect($matches[1])->not->toMatch('/^\s/');
    });

    it('isi pesan tidak memuat teks komentar Blade yang bocor', function () {
        // Regresi kedua: menulis penanda `{{--` di dalam komentar Blade
        // membuat kompilasi menutup komentar lebih awal, sehingga sisanya
        // dirender sebagai teks biasa.
        $html = Livewire::test(LogTable::class)->html();

        expect($html)->not->toContain('tapi baris baru di sekitarnya')
            ->and($html)->not->toContain('--}}');
    });

    it('tidak membocorkan JID plaintext chat_id di kolom device', function () {
        // Regresi: kolom device dulu `chat_id ?? phone`, sehingga baris masuk
        // menampilkan JID lengkap walau `phone` sudah ternormalisasi dan
        // terenkripsi. Enkripsi `phone` jadi sia-sia di layar.
        WaraLog::query()->create([
            'direction' => 'in',
            'event' => 'message',
            'device_id' => 'wago prod',
            'chat_id' => '628000000001@s.whatsapp.net',
            'phone' => '628000000001',
            'body' => 'Kode OTP saya 334455',
            'status' => 'received',
        ]);

        $html = Livewire::test(LogTable::class)->html();

        // Nomornya tampil (dari phone) …
        expect($html)->toContain('628000000001')
            // … tapi JID plaintext-nya tidak pernah bocor ke layar.
            ->and($html)->not->toContain('628000000001@s.whatsapp.net');
    });

    it('tetap menampilkan chat_id bila phone kosong', function () {
        WaraLog::query()->create([
            'direction' => 'in',
            'event' => 'message',
            'device_id' => 'wago prod',
            'chat_id' => '628777666555@s.whatsapp.net',
            'phone' => null,
            'body' => 'tanpa nomor',
            'status' => 'received',
        ]);

        Livewire::test(LogTable::class)
            ->assertSee('628777666555@s.whatsapp.net');
    });

    it('menyediakan daftar device dari katalog', function () {
        $component = Livewire::test(LogTable::class);

        expect($component->get('devices')->pluck('display_name')->all())
            ->toContain('Papa', 'Nawasara Agent');
    });

    it('tidak menyediakan lagi state filter di komponen LogTable', function () {
        // Filter sekarang milik data-table; state di LogTable sudah dihapus.
        expect(property_exists(LogTable::class, 'emptyDeviceFilter'))->toBeFalse()
            ->and(method_exists(LogTable::class, 'constraints'))->toBeFalse();
    });
});

describe('halaman device', function () {
    it('menampilkan peringatan untuk device yang lama tidak siap', function () {
        config()->set('wara.offline_alert_after_minutes', 10);

        WaraSession::query()->create([
            'device_id' => 'device_lama', 'jid' => '6281@s.whatsapp.net',
            'display_name' => 'Device Mati', 'is_present' => true,
            'is_connected' => false, 'is_logged_in' => false,
            'last_seen_at' => now()->subHours(3),
        ]);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Device perlu dipasang ulang'))
            ->assertSee('Device Mati')
            // GOWA tidak melaporkan penyebab disconnect, jadi kalimat ini
            // wajib muncul agar operator tidak salah menyimpulkan akun diblokir.
            ->assertSee(__('Gateway tidak melaporkan alasan disconnect, jadi ini belum berarti akun diblokir.'));
    });

    it('menampilkan tenggang untuk device yang baru tidak siap', function () {
        config()->set('wara.offline_alert_after_minutes', 10);

        WaraSession::query()->create([
            'device_id' => 'device_baru', 'jid' => '6282@s.whatsapp.net',
            'display_name' => 'Device Baru Mati', 'is_present' => true,
            'is_connected' => false, 'is_logged_in' => false,
            'last_seen_at' => now(),
        ]);

        Livewire::test(DeviceHeader::class)
            ->assertSee(__('Device sedang tidak siap'))
            ->assertSee(__('Masih dalam masa tenggang sebelum ditandai sebagai perlu dipasang ulang.'));
    });
});
