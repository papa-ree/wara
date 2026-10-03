<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/wara/routes` - petakan purpose ke device milik client sendiri.
 *
 * 🔴 `client_id` TIDAK ADA di payload, dan itu disengaja.
 *
 * Kalau client_id boleh dikirim, satu token bisa menulis route untuk client lain
 * dan lalu mengirim pesan memakai device yang bukan haknya. Itu jalur pintas
 * yang melewati seluruh tujuan router, jadi client SELALU diambil dari token.
 */
class RouteUpsertController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
            'device_id' => ['required', 'string', 'max:191'],
            'outbound_enabled' => ['nullable', 'boolean'],
            'inbound_webhook_enabled' => ['nullable', 'boolean'],
        ]);

        $client = SendClientResolver::for($request);

        // Device harus benar-benar ada. Menerima device id karangan di sini
        // akan menghasilkan route yang selalu gagal saat dipakai, dan itu baru
        // ketahuan saat ada pesan yang sebenarnya penting.
        $session = WaraSession::query()
            ->where('device_id', $validated['device_id'])
            ->first();

        if ($session === null) {
            abort(422, sprintf(
                'Device "%s" tidak terdaftar. Jalankan: php artisan wara:sync-devices',
                $validated['device_id']
            ));
        }

        $route = new WaraRoute;

        // `client_id` di luar $fillable, jadi harus lewat forceFill - persis
        // seperti yang dilakukan form admin.
        $route->forceFill([
            'client_id' => $client->getKey(),
            'purpose' => $validated['purpose'],
            'device_id' => $session->device_id,
        ]);

        $route->fill([
            'outbound_enabled' => $request->boolean('outbound_enabled', true),
            'inbound_webhook_enabled' => $request->boolean('inbound_webhook_enabled'),
        ]);

        try {
            $route->save();
        } catch (UniqueConstraintViolationException) {
            // 🔴 `UNIQUE (client_id, purpose)` dilanggar. Ini 422, bukan 500:
            // permintaannya tidak salah, tapi sudah ada route dengan purpose
            // yang sama untuk client ini. Mengubahnya jadi 500 akan
            // membuat klien menganggap ada masalah server.
            abort(422, sprintf(
                'Client ini sudah punya route untuk purpose "%s". Perbarui route yang ada, atau pakai PUT dengan purpose lain.',
                $route->purpose
            ));
        }

        return response()->json([
            'purpose' => $route->purpose,
            'device_id' => $route->device_id,
            'outbound_enabled' => $route->outbound_enabled,
            'inbound_webhook_enabled' => $route->inbound_webhook_enabled,
        ]);
    }
}
