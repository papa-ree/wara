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
 * lama lagi ada yang lupa memakai `api_token_id` dan berakhir meloloskan
 * `client_id` dari payload.
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

        // 🔴 HANYA `api_token_id` YANG DICARI, DAN ITU SENGAJA.
        //
        // Versi sebelumnya punya cabang kedua: kalau token tidak terikat
        // `api_token_id`, ia membaca `$token->bale_id` dan mencari client
        // berdasarkan organisasi. Cabang itu SUDAH DIHAPUS bersama kolomnya.
        //
        // Alasannya bukan sekadar "kolomnya tidak ada". Kalau identitas masih
        // boleh dicari lewat organisasi, maka satu token bisa sampai ke client
        // mana saja milik organisasi itu - termasuk client yang tidak pernah
        // menunjuk token ini. Itu membatalkan seluruh alasan `wara_clients`
        // ada: pemetaan harus SATU token ke SATU client.
        //
        // Jalur lain untuk sampai ke client - lewat `client_id` di payload -
        // sudah tertutup di dua tempat: `WaraClient::$fillable` tidak memuat
        // `client_id`, dan `SendController` tidak pernah membaca field itu.
        $client = app(DeviceRouter::class)->clientForApiTokenId((string) $token->getKey());

        if ($client !== null && $client->isUsable()) {
            return $client;
        }

        abort(403, 'Token ini tidak dipetakan ke client Wara. Hubungi admin untuk mendaftarkannya.');
    }
}
