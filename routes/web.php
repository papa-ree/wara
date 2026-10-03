<?php

use Bale\Wara\Livewire\Pages\Landlord\Client\Form as ClientForm;
use Bale\Wara\Livewire\Pages\Landlord\Client\Index as ClientIndex;
use Bale\Wara\Livewire\Pages\Landlord\Device\Form;
use Bale\Wara\Livewire\Pages\Landlord\Device\Index;
use Bale\Wara\Livewire\Pages\Landlord\Log\Index as LogIndex;
use Bale\Wara\Livewire\Pages\Landlord\Status\Index as StatusIndex;
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

    Route::get('/device', Index::class)
        ->name('device.index')
        ->middleware('can:wara.device.read');

    Route::get('/device/{session}/edit', Form::class)
        ->name('device.edit')
        ->middleware('can:wara.device.update');

    Route::get('/log', LogIndex::class)
        ->name('log.index')
        ->middleware('can:wara.log.read');

    Route::get('/status', StatusIndex::class)
        ->name('status.index')
        ->middleware('can:wara.status.read');
});
