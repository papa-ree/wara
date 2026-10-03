<?php

use Bale\Wara\Http\Controllers\Api\RouteIndexController;
use Bale\Wara\Http\Controllers\Api\RouteUpsertController;
use Bale\Wara\Http\Controllers\Api\SendController;
use Bale\Wara\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Wara
|--------------------------------------------------------------------------
|
| Receiver webhook dan REST API per client.
|
| Autentikasi memakai middleware group `bale.api` milik `bale/api`: token Bearer
| + throttle + hardening. Tidak ada tabel API key khusus Wara - token dikelola
| lewat UI token yang sudah ada, bersama scope dan masa berlakunya.
|
| 🔴 Scope `wara.routes.write` sengaja dipisah dari `wara.send`. Satu token
| boleh diberi wewenang mengirim pesan tanpa diberi wewenang mengubah routing;
| dua hal itu risikonya berbeda, dan menyatukannya membuat token scraper
| memegang wewenang lebih banyak dari yang seharusnya.
|
| 🔴 SETIAP endpoint REST mengambil client dari token pemanggil lewat
| `SendClientResolver`. Tidak ada satu pun yang menerima `client_id` dari
| payload; lihat docblock controller itu untuk alasannya.
|
*/

Route::post('api/wara/webhook', WebhookController::class)
    ->middleware('throttle:120,1')
    ->name('wara.webhook');

Route::middleware('bale.api')->prefix('api/v1/wara')->name('api.v1.wara.')->group(function () {
    Route::post('send', SendController::class)
        ->middleware('scope:wara.send')
        ->name('send');

    Route::get('routes', RouteIndexController::class)
        ->middleware('scope:wara.routes.read')
        ->name('routes.index');

    Route::put('routes', RouteUpsertController::class)
        ->middleware('scope:wara.routes.write')
        ->name('routes.upsert');
});
