<?php

use Bale\Wara\Http\Controllers\ClientController;
use Bale\Wara\Http\Middleware\EnsureWaraService;
use Bale\Wara\Livewire\Pages\Guest\Docs\Index as GuestDocsIndex;
use Bale\Wara\Livewire\Pages\Guest\Template\Form as GuestTemplateForm;
use Bale\Wara\Livewire\Pages\Guest\Template\Index as GuestTemplateIndex;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Index as AnalyticsIndex;
use Bale\Wara\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Wara\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form;
use Bale\Wara\Livewire\Pages\Landlord\Device\Index;
use Bale\Wara\Livewire\Pages\Landlord\Docs\Index as DocsIndex;
use Bale\Wara\Livewire\Pages\Landlord\Log\Index as LogIndex;
use Bale\Wara\Livewire\Pages\Landlord\Status\Index as StatusIndex;
use Bale\Wara\Livewire\Pages\Landlord\Template\Index as TemplateIndex;
use Bale\Wara\Livewire\Pages\Landlord\Template\Review as TemplateReview;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wara Web Routes (Landlord)
|--------------------------------------------------------------------------
|
| Dashboard device WhatsApp. Penugasan `purpose` hanya bisa dilakukan lewat
| halaman ini — kolomnya sengaja tidak ada di $fillable model (PRD §7).
|
*/

Route::middleware(['web', 'auth'])->prefix('wara')->name('wara.')->group(function () {
    Route::get('/client', ClientIndex::class)
        ->name('client.index')
        ->middleware('can:wara.client.read');

    Route::get('/client/create', ClientForm::class)
        ->name('client.create')
        ->middleware('can:wara.client.update');

    Route::get('/client/{client}/edit', ClientForm::class)
        ->name('client.edit')
        ->middleware('can:wara.client.update');

    // 🔴 Jalur simpan HTML biasa (bukan Livewire). ClientForm tetap dipakai
    // untuk RENDER halaman, tapi submit form mem-POST kesini supaya bekerja
    // di browser tanpa aksi Livewire — sebagian deployment tidak pernah
    // mengirim `wire:*`. Lihat `ClientController` untuk alasan lengkapnya.
    Route::post('/client', [ClientController::class, 'store'])
        ->name('client.store')
        ->middleware('can:wara.client.update');

    Route::put('/client/{client}', [ClientController::class, 'update'])
        ->name('client.update')
        ->middleware('can:wara.client.update');

    Route::get('/device', Index::class)
        ->name('device.index')
        ->middleware('can:wara.device.read');

    Route::get('/device/{session}/edit', Form::class)
        ->name('device.edit')
        ->middleware('can:wara.device.update');

    // 🔴 `can:wara.log.read` SENGAJA dipinjam untuk Analitik, bukan permission
    // baru seperti `wara.analytics.read`. Permission baru berarti satu baris
    // baru di tabel permission + satu langkah grant untuk tiap admin yang
    // sekarang sudah bisa melihat log — dan halaman ini tidak membocorkan apa
    // pun yang tidak sudah terlihat di sana. Hanya agregat.
    Route::get('/analitik', AnalyticsIndex::class)
        ->name('analytics.index')
        ->middleware('can:wara.log.read');

    Route::get('/log', LogIndex::class)
        ->name('log.index')
        ->middleware('can:wara.log.read');

    Route::get('/template', TemplateIndex::class)
        ->name('template.index')
        ->middleware('can:wara.template.read');

    Route::get('/template/{template}', TemplateReview::class)
        ->name('template.review')
        ->middleware('can:wara.template.review');

    Route::get('/docs', DocsIndex::class)
        ->name('docs.index')
        ->middleware('can:wara.client.read');

    Route::get('/status', StatusIndex::class)
        ->name('status.index')
        ->middleware('can:wara.status.read');
});

/*
|--------------------------------------------------------------------------
| Panel Guest Wara
|--------------------------------------------------------------------------
|
| Diakses operator organisasi penyewa. Akses ditentukan relasi layanan
| `wara`, bukan permission admin (PRD §16.5).
|
| 🔴 Tidak ada route kirim pesan di sini. Panel guest hanya menyusun dan
| melihat status template; pengiriman hanya lewat API.
|
*/

Route::middleware(['web', 'auth', EnsureWaraService::class])
    ->prefix('guest/wara')
    ->name('wara.guest.')
    ->group(function () {
        Route::get('/template', GuestTemplateIndex::class)
            ->name('template.index');

        Route::get('/template/create', GuestTemplateForm::class)
            ->name('template.create');

        Route::get('/template/{template}/edit', GuestTemplateForm::class)
            ->name('template.edit');

        // Panduan API ringkas. Isinya hanya endpoint + payload; scope token dan
        // detail gateway sengaja tidak ditampilkan (lihat komponennya).
        Route::get('/docs', GuestDocsIndex::class)
            ->name('docs.index');
    });
