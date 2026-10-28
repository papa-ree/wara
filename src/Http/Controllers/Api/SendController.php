<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Exceptions\CooldownException;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraTemplate;
use Bale\Wara\WaraManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
 *
 * Dua bentuk payload:
 *   - `message` (+ `to`)          -> teks bebas, perilaku lama.
 *   - `template` (+ `parameters`) -> template APPROVED dari katalog lokal.
 *
 * Template hanya boleh dipakai kalau statusnya APPROVED. Pemeriksaan dilakukan
 * di server, bukan hanya di UI, supaya draft tidak bisa dikirim lewat API.
 *
 * 🔴 Penolakan template memakai `error_code: template_required` (PRD #49). Itu
 * BUKAN berarti teks wajib diganti template - teks bebas tetap sah. Kode ini
 * hanya memberi pemanggil cara membedakan "template Anda ditolak" dari gagal
 * device atau cooldown tanpa membaca string pesan.
 */
class SendController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:4096', 'required_without:template'],
            'template' => ['nullable', 'string', 'max:191', 'required_without:message'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:1024'],
            'header_media_url' => ['nullable', 'url', 'max:2048'],
            'purpose' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
        ]);

        $client = SendClientResolver::for($request);
        $purpose = ResolvePurpose::for($client, $validated['purpose'] ?? null);

        $template = null;

        if (filled($validated['template'] ?? null)) {
            $template = $this->resolveTemplate((string) $validated['template']);

            if ($template === null) {
                return response()->json([
                    'status' => 'failed',
                    'client' => $client->name,
                    'purpose' => $purpose,
                    'error_code' => 'template_required',
                    'error' => __('Template tidak ditemukan, ambigu, atau belum disetujui.'),
                ], 422);
            }
        }

        try {
            $manager = app(WaraManager::class);

            $result = $template === null
                ? $manager->sendMessage(
                    $validated['to'],
                    (string) $validated['message'],
                    $purpose,
                    null,
                    null,
                    null,
                    $client,
                )
                : $manager->sendTemplate(
                    $validated['to'],
                    $template,
                    (array) ($validated['parameters'] ?? []),
                    $validated['header_media_url'] ?? null,
                    $purpose,
                    null,
                    null,
                    $client,
                );
        } catch (ValidationException $e) {
            // Template lama yang bentuk komponennya tidak lagi valid. Jangan
            // bocorkan detail internal; cukup 422 dengan pesan pertama.
            return response()->json([
                'status' => 'failed',
                'client' => $client->name,
                'purpose' => $purpose,
                'error' => collect($e->errors())->flatten()->first(),
            ], 422);
        } catch (CooldownException $e) {
            // 429 + `Retry-After`: request sah, tapi nomor tujuan masih dalam
            // cooldown anti-ban. Dulu di sini prosesnya tidur sampai 61 detik
            // dan mati oleh `max_execution_time = 60`; sekarang pemanggil yang
            // menunggu, bukan worker PHP.
            return response()
                ->json([
                    'status' => 'cooldown',
                    'client' => $client->name,
                    'purpose' => $purpose,
                    'retry_after' => $e->retryAfter,
                    'error' => $e->getMessage(),
                ], 429)
                ->header('Retry-After', (string) $e->retryAfter);
        } catch (WaraException $e) {
            // 422: permintaannya sah, tapi kondisinya belum siap (device
            // offline, route belum dipetakan, parameter kurang). Bukan 403 -
            // tokennya benar.
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
            'template' => $template?->name,
            'message_id' => $result->messageId,
        ]);
    }

    /**
     * Cari template APPROVED berdasarkan UUID atau nama.
     *
     * Nama hanya unik per pemilik, jadi nama yang dipakai lebih dari satu
     * pemilik sengaja ditolak (ambigu) alih-alih memilih salah satu diam-diam.
     */
    protected function resolveTemplate(string $reference): ?WaraTemplate
    {
        $query = WaraTemplate::query()->approved();

        if (Str::isUuid($reference)) {
            return $query->whereKey($reference)->first();
        }

        $matches = $query->where('name', $reference)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
