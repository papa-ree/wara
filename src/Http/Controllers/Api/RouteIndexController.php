<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/wara/routes` - daftar route milik client pemanggil.
 *
 * 🔴 Token HANYA boleh melihat route client-nya sendiri.
 *
 * `client_id` TIDAK pernah diambil dari payload. Kalau boleh, satu token bisa
 * menulis route client lain dan kemudian mengirim pesan memakai device yang
 * bukan haknya - itu jalur pintas melewati seluruh tujuan router.
 */
class RouteIndexController
{
    public function __invoke(Request $request): JsonResponse
    {
        $client = SendClientResolver::for($request);

        $routes = $client->routes()
            ->get()
            ->map(fn (WaraRoute $route): array => [
                'purpose' => $route->purpose,
                'device_id' => $route->device_id,
                'outbound_enabled' => $route->outbound_enabled,
                'inbound_webhook_enabled' => $route->inbound_webhook_enabled,
                'device_ready' => WaraSession::query()
                    ->where('device_id', $route->device_id)
                    ->value('is_logged_in') === true,
            ]);

        return response()->json([
            'client' => ['id' => $client->getKey(), 'name' => $client->name],
            'routes' => $routes,
        ]);
    }
}
