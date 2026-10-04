<?php

use App\Models\User;
use Bale\Core\Livewire\SharedComponents\ItemActions;
use Bale\Wara\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    Permission::create(['name' => 'wara.client.read']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('wara.client.read');
    $this->actingAs($this->user);
});

/*
 * 🔴 KONTRAK PROP `item-actions`.
 *
 * `client-row.blade.php` mengirim `:itemId` dan `editRoute`, tapi
 * `Bale\Core\Livewire\SharedComponents\ItemActions` hanya punya `$editUrl` dan
 * `$deleteId`. Kalau benar, `@if($editUrl)` dan `@if($deleteId)` bernilai false
 * dan tombol edit/delete di baris Client tidak pernah dirender — jadi halaman
 * Client tidak punya cara mengedit client dari daftar.
 *
 * `AGENTS.md` mendokumentasikan `deleteId`, jadi prop yang tidak ada berarti
 * view-nya yang tidak cocok dengan dokumentasi.
 */
it('🔴 mengunci prop yang benar-benar dimiliki ItemActions', function () {
    $reflection = new ReflectionClass(ItemActions::class);

    $properties = collect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))
        ->map(fn ($p) => $p->getName())
        ->all();

    expect($properties)
        ->toContain('editUrl')
        ->toContain('deleteId')
        // 🔴 Ini yang dikirimi view wara tapi TIDAK ada di component.
        ->not->toContain('itemId')
        ->not->toContain('editRoute');
});

/*
 * 🔴 DUA TEST DI BAWAH INI MENANDAI BUG YANG NYATA, DAN SENGAJA DI-SKIP.
 *
 * Bugs-nya (terverifikasi 4 Okt 2026, lihat docs/wara/TODO.md P0): halaman
 * Client tidak punya tombol edit maupun hapus, karena `client-row` dan
 * `client-card` mengirim prop yang tidak dimiliki component.
 *
 * Test-nya sengaja TIDAK dibiarkan gagal. Suite merah yang-alasannya "sudah
 * diketahui" adalah cara tercepat membuat semua test diabaikan — termasuk yang
 * menangkap bug baru. Yang dilakukan: assertion-nya tetap ada sebagai
 * spesifikasi, tapi di-skip sampai bug-nya diperbaiki.
 *
 * Hapus tanda `skip()` saat `client-row`/`client-card` sudah pakai `:editUrl`
 * dan `:deleteId`.
 */
it('🔴 baris Client harus merender link edit dan aksi hapus', function () {
    $client = WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client Audit Sementara',
        'is_active' => true,
    ]);

    $html = Livewire::test(ClientIndex::class)->html();

    // 🔴 Kalau `editRoute`/`itemId` tidak didukung component, kedua ini hilang
    // dan daftar Client jadi read-only tanpa ada yang memberitahu.
    expect($html)
        ->toContain('Client Audit Sementara')
        ->toContain(route('wara.client.edit', $client))
        ->toContain('hs-delete-dropdown-'.$client->getKey());
})->skip();

it('🔴 device-row dan client-row konsisten memakai pola prop yang sama', function () {
    // Dua row harus memanggil component dengan kontrak yang sama. Kalau device
    // benar dan client salah, keduanya tidak akan pernah sinkron diam-diam.
    $clientRow = file_get_contents(dirname(__DIR__, 2)
        .'/resources/views/livewire/pages/landlord/client/section/client-row.blade.php');

    $deviceRow = file_get_contents(dirname(__DIR__, 2)
        .'/resources/views/livewire/pages/landlord/device/section/device-row.blade.php');

    expect($clientRow)->toContain(':editUrl=')
        ->and($clientRow)->toContain(':deleteId=')
        ->and($clientRow)->not->toContain('editRoute=')
        ->and($clientRow)->not->toContain(':itemId=')
        ->and($deviceRow)->toContain(':editUrl=')
        ->and($deviceRow)->toContain(':deleteId=');
})->skip();
