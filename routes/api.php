<?php

use Bale\Wara\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Wara
|--------------------------------------------------------------------------
|
| Hanya webhook receiver. Endpoint ini dipanggil service Go, bukan frontend.
| Middleware throttle menjaga agar request abusing tidak membanjiri receiver.
|
*/

Route::post('api/wara/webhook', WebhookController::class)
    ->middleware('throttle:120,1')
    ->name('wara.webhook');
