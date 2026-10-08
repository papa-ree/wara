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
 * `Bale\Core\Livewire\SharedComponents\ItemActions` hanya punya `$editUrl` dan
 * `$deleteId` (plus opsional `deleteEvent`, `modelName`, `confirmMessage`,
 * `navigate`). View wara tidak boleh memakai `itemId`/`editRoute` — keduanya
 * tidak dikenal komponen, sehingga `@if($editUrl)`/`@if($deleteId)` bernilai
 * false dan tombol di baris tidak pernah dirender. Test ini mengunci bahwa
 * kontrak component tidak berubah melenceng dari dokumen AGENTS.md.
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
 * DUA TEST DI BAWAH INI mengunci perilaku T0 yang sudah diperbaiki
 * (lihat docs/wara/TODO.md P0 & PLAN-TEMA Fase T0, 8 Okt 2026).
 *
 * Sebelumnya `client-row` mengirim `:itemId`/`editRoute` dan `client-card`
 * `:editUrl` tanpa `:deleteId` — dua-duanya bukan kontrak `ItemActions`,
 * jadi tombol edit/delete tidak pernah dirender dan daftar Client read-only
 * diam-diam. Sekarang keduanya memakai `:editUrl` + `:deleteId`.
 *
 * Selain itu, item-actions baris client memakai `deleteEvent="deleteWaraClient"`
 * BUKAN `deleteItem`. `data-table` yang merender baris juga memasang
 * `#[On('deleteItem')]` dengan penghapusan generik (tanpa authorization,
 * tanpa cascade route/endpoint, tanpa transaksi). Livewire mengirim event ke
 * SEMUA komponen yang mendengarnya — kalau memakai `deleteItem`, data-table
 * menghapus client mentah-mentah lebih dulu dan override `performDelete()` di
 * `ClientTable` (authorize + transaksi + cascade) tidak pernah mengeksekusi.
 */
it('baris Client harus merender link edit dan aksi hapus', function () {
    $client = WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => 'Client Audit Sementara',
        'is_active' => true,
    ]);

    $html = Livewire::test(ClientIndex::class)->html();

    expect($html)
        ->toContain('Client Audit Sementara')
        ->toContain(route('wara.client.edit', $client))
        ->toContain('hs-delete-dropdown-'.$client->getKey());
});

it('device-row dan client-row konsisten memakai pola prop yang sama', function () {
    // Dua row harus memanggil component dengan kontrak yang sama. Device
    // sengaja TIDAK bisa dihapus (desain), jadi hanya client yang mengirim
    // `deleteId`.
    $clientRow = file_get_contents(dirname(__DIR__, 2)
        .'/resources/views/livewire/pages/landlord/client/section/client-row.blade.php');

    $deviceRow = file_get_contents(dirname(__DIR__, 2)
        .'/resources/views/livewire/pages/landlord/device/section/device-row.blade.php');

    expect($clientRow)->toContain(':editUrl=')
        ->and($clientRow)->toContain(':deleteId=')
        ->and($clientRow)->toContain('deleteEvent="deleteWaraClient"')
        ->and($clientRow)->not->toContain('editRoute=')
        ->and($clientRow)->not->toContain(':itemId=')
        ->and($clientRow)->not->toContain('deleteEvent="deleteItem"')
        ->and($deviceRow)->toContain(':editUrl=')
        ->and($deviceRow)->not->toContain(':deleteId=');
});
