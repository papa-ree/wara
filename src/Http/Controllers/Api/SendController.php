<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\WaraManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/wara/send` - kirim pesan WhatsApp atas nama client.
 *
 * 🔴 INI YANG MEMAKAI `api_tokens.bale_id` DAN `wara_clients`.
 *
 * Sebelum router ada, tidak ada endpoint seperti ini sama sekali - dan itu
 * masalahnya: `POST /send` tidak punya cara tahu client mana yang memanggil,
 * jadi tidak punya cara tahu device mana yang harus dipakai. Skema barulah yang
 * membuatnya mungkin.
 *
 * Client dicocokkan dari token yang sedang dipakai, BERURUTAN:
 *   1. `wara_clients.api_token_id` - client yang identitasnya token itu.
 *   2. `api_tokens.bale_id` -> `wara_clients.bale_id` - client organisasi.
 *
 * Kalau tidak ada yang cocok, request DITOLAK. Tidak ada device fallback: pesan
 * yang terkirim dari nomor yang tidak diminta siapa pun lebih buruk daripada
 * pesan yang gagal terkirim.
 */
class SendController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:4096'],
            'purpose' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
        ]);

        $client = SendClientResolver::for($request);
        $purpose = ResolvePurpose::for($client, $validated['purpose'] ?? null);

        try {
            $result = app(WaraManager::class)->sendMessage(
                $validated['to'],
                $validated['message'],
                $purpose,
                null,
                null,
                null,
                $client,
            );
        } catch (WaraException $e) {
            // 422: permintaannya sah, tapi kondisinya belum siap (device
            // offline, route belum dipetakan). Bukan 403 - tokennya benar.
            return response()->json([
                'status' => 'failed',
                'client' => $client->name,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ], 422);
        }

        if (! $result->accepted) {
            return response()->json([
                'status' => 'rejected',
                'client' => $client->name,
                'purpose' => $purpose,
                'error_code' => $result->errorCode,
                'error' => $result->errorMessage,
            ], 502);
        }

        return response()->json([
            'status' => 'sent',
            'client' => $client->name,
            'purpose' => $purpose,
            'message_id' => $result->messageId,
        ]);
    }
}
