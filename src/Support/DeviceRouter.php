<?php

namespace Bale\Wara\Support;

use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolusi `client + purpose -> device`.
 *
 * 🔴 INI YANG MENGGANTIKAN `wara_sessions.purpose` YANG UNIK GLOBAL.
 *
 * Kolom lama menjawab pertanyaan "device mana untuk purpose ini?" secara
 * global, tanpa pernah menanyakan "client mana yang bertanya?". Router
 * seperti itu tidak bisa bekerja: begitu ada dua client, keduanya berebut
 * device yang sama dan tidak ada cara tahu siapa berhak atas apa.
 *
 * Pertanyaan yang dijawab di sini adalah "client A, purpose `notifikasi` ->
 * device mana?", dan jawabannya diambil dari `wara_routes` yang unik
 * `(client_id, purpose)`.
 *
 * Tidak ada fallback ke device lain. Device yang offline harus menggagalkan
 * pengiriman dengan nama yang jelas, bukan diam-diam digantikan - nomor
 * pengirim adalah bagian dari yang dilihat operator di WhatsApp.
 */
final class DeviceRouter
{
    /**
     * Route untuk satu client + purpose.
     *
     * Null berarti client itu tidak punya route untuk purpose tersebut, dan
     * itu kondisi yang harus dilaporkan, bukan diisi dengan device sembarangan.
     */
    public function routeFor(WaraClient $client, string $purpose): ?WaraRoute
    {
        return WaraRoute::query()
            ->where('client_id', $client->getKey())
            ->where('purpose', $purpose)
            ->first();
    }

    /**
     * 🔴 Device pengirim untuk `client + purpose`.
     *
     * Route yang menunjuk device yang tidak ada atau outbound-nya dimatikan
     * dianggap tidak ada: mengabaikannya dan memakai device lain akan
     * mengirim pesan dari nomor yang salah.
     */
    public function resolveDeviceId(WaraClient $client, string $purpose): string
    {
        $route = $this->routeFor($client, $purpose);

        if ($route === null) {
            throw DeviceNotFoundException::forPurpose($client, $purpose);
        }

        if (! $route->allowsOutbound()) {
            throw DeviceNotFoundException::outboundDisabled($client, $purpose, $route->device_id);
        }

        $session = WaraSession::query()->where('device_id', $route->device_id)->first();

        if ($session === null) {
            throw DeviceNotFoundException::forSessionId($route->device_id);
        }

        return $session->device_id;
    }

    /**
     * Session pengirim, lengkap dengan atribut kesiapannya.
     */
    public function resolveSession(WaraClient $client, string $purpose): WaraSession
    {
        $this->resolveDeviceId($client, $purpose);

        $route = $this->routeFor($client, $purpose);

        /** @var WaraSession $session */
        $session = WaraSession::query()->where('device_id', $route->device_id)->firstOrFail();

        return $session;
    }

    /**
     * Client yang diwakili oleh sebuah api token.
     *
     * Ini yang menutup celah paling fundamental di skema lama: tanpa ini,
     * `POST /send` tidak punya cara tahu pemanggilnya client mana.
     */
    public function clientForApiTokenId(string $apiTokenId): ?WaraClient
    {
        return WaraClient::query()
            ->where('api_token_id', $apiTokenId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Client yang diwakili oleh nilai `bale_id` (organisasi penyewa).
     */
    public function clientForBaleId(string $baleId): ?WaraClient
    {
        return WaraClient::query()
            ->where('bale_id', $baleId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Client service bawaan, dipakai kalau pemanggil tidak punya client.
     *
     * Ini satu-satunya jalur yang tidak eksplisit, dan hanya untuk client
     * internal bale - bukan untuk traffic dari token luar.
     */
    public function defaultServiceClient(): ?WaraClient
    {
        return WaraClient::query()
            ->where('type', WaraClient::TYPE_SERVICE)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Client yang berhak menerima event inbound untuk sebuah device.
     *
     * 🔴 Ini menggantikan iterate `config('wara.consumers')`. Sekarang
     * Orkestrator nyata: route dengan `inbound_webhook_enabled` yang device
     * nya sama dengan device penerima. Dua client boleh memakai device yang
     * sama untuk `notifikasi`, dan keduanya akan menerima event.
     *
     * @return Collection<int, WaraRoute>
     */
    public function inboundRoutesForDevice(string $deviceId, string $purpose): Collection
    {
        return WaraRoute::query()
            ->where('device_id', $deviceId)
            ->where('purpose', $purpose)
            ->where('inbound_webhook_enabled', true)
            ->get();
    }

    /**
     * Purpose yang device ini layani, untuk keperluan inbound.
     *
     * @return array<int, string>
     */
    public function purposesForDevice(string $deviceId): array
    {
        return WaraRoute::query()
            ->where('device_id', $deviceId)
            ->pluck('purpose')
            ->all();
    }
}
