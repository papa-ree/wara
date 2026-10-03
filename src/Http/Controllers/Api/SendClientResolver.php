<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Models\WaraClient;
use Bale\Wara\Support\DeviceRouter;
use Illuminate\Http\Request;

/**
 * Satu-satunya cara mencari client dari token - dipakai bersama oleh SEMUA
 * endpoint Wara.
 *
 * Dipisah supaya aturan "token hanya boleh menyentuh client-nya sendiri" punya
 * satu implementasi. Kalau tiap controller punya versinya sendiri, tidak akan
 * lama lagi ada yang lupa memakai `bale_id` dan berakhir meloloskan `client_id`
 * dari payload.
 */
final class SendClientResolver
{
    public static function for(Request $request): WaraClient
    {
        // 🔴 Guard-nya `api-token`, BUKAN default.
        //
        // `AuthenticateApiToken` mendaftarkan token lewat
        // `auth('api-token')->setUser()`, jadi `$request->user()` tanpa
        // argumen - yang memakai guard `web` - akan selalu null di endpoint API.
        $token = $request->user('api-token') ?? auth('api-token')->user();

        if ($token === null) {
            abort(403, 'Token tidak terautentikasi.');
        }

        /** @var WaraClient|null $byToken */
        $byToken = app(DeviceRouter::class)->clientForApiTokenId((string) $token->getKey());

        if ($byToken !== null && $byToken->isUsable()) {
            return $byToken;
        }

        $baleId = $token->bale_id;

        if (filled($baleId)) {
            $byBale = app(DeviceRouter::class)->clientForBaleId((string) $baleId);

            if ($byBale !== null && $byBale->isUsable()) {
                return $byBale;
            }
        }

        abort(403, 'Token ini tidak dipetakan ke client Wara. Hubungi admin untuk mendaftarkannya.');
    }
}
