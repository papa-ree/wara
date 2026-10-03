<?php

namespace Bale\Wara\Gateways;

use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Data\DeviceInfo;
use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceState;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\SendResult;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\GatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Adapter GOWA. SATU-SATUNYA kelas yang boleh menyebut nama field GOWA,
 * `@s.whatsapp.net`, dan `X-Hub-Signature-256` (PRD §11.2).
 *
 * Kalau salah satu dari ketiga hal itu muncul di kelas lain, batasnya bocor.
 */
class GowaGateway implements WaraGateway
{
    /** Format JID device — detail gateway, tidak boleh keluar dari sini. */
    protected const JID_SUFFIX = '@s.whatsapp.net';

    /** Header penanda device — wajib pada setiap call (PRD §6.3). */
    protected const DEVICE_HEADER = 'X-Device-Id';

    /** Header signature HMAC (PRD §6.1). */
    protected const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /** Kode error yang harus diterjemahkan jadi exception domain. */
    protected const ERR_DEVICE_REQUIRED = 'DEVICE_ID_REQUIRED';

    protected const ERR_DEVICE_NOT_FOUND = 'DEVICE_NOT_FOUND';

    public function sendText(string $deviceId, string $phone, string $text): SendResult
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->post('/send/message', [
                'phone' => $phone.self::JID_SUFFIX,
                'message' => $text,
            ]);

        $this->throwForError($response->status(), $response->body(), $deviceId);

        $decoded = $this->decode($response->body());

        return SendResult::accepted(
            messageId: $this->stringOrNull(data_get($decoded, 'results.message_id')
                ?? data_get($decoded, 'message_id')),
            status: 'accepted'
        );
    }

    /**
     * Apakah nilai ini JID akun device milik GOWA?
     *
     * Polanya sengaja sempit: JID device selalu `<digit>@s.whatsapp.net`.
     * Longgarkan dan nama device yang kebetulan mengandung `@` akan salah
     * dikira JID - dan akibatnya `wara_logs.device_id` storing identitas yang
     * tidak cocok dengan tabel mana pun.
     *
     * `@c.us` sengaja TIDAK termasuk: itu bentuk kontak, bukan device.
     */
    public function isDeviceJid(string $value): bool
    {
        return (bool) preg_match('/^\d{8,15}'.preg_quote(self::JID_SUFFIX, '/').'$/', $value);
    }

    public function listDevices(): DeviceList
    {
        $response = $this->request()->get('/devices');

        $this->throwForError($response->status(), $response->body());

        $decoded = $this->decode($response->body());

        $devices = $this->extractDeviceRows($decoded);

        return new DeviceList($devices->map(fn (array $row) => $this->toDeviceInfo($row)));
    }

    public function deviceStatus(string $deviceId): DeviceStatus
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->get('/devices/'.rawurlencode($deviceId).'/status');

        $this->throwForError($response->status(), $response->body(), $deviceId);

        $decoded = $this->decode($response->body());
        $results = $decoded['results'] ?? $decoded;

        // Terverifikasi 29 Sep 2026: endpoint status membalas
        // `is_connected` / `is_logged_in` sebagai boolean, BUKAN field `state`
        // seperti katalog device. `state` tetap dibaca sebagai fallback.
        if (array_key_exists('is_logged_in', $results) || array_key_exists('is_connected', $results)) {
            $isLoggedIn = (bool) ($results['is_logged_in'] ?? false);
            $isConnected = (bool) ($results['is_connected'] ?? false);

            $state = match (true) {
                $isLoggedIn => DeviceState::LoggedIn,
                $isConnected => DeviceState::Connected,
                default => DeviceState::Disconnected,
            };
        } else {
            $state = $this->toState($this->stringOrNull($results['state'] ?? null));
        }

        return new DeviceStatus(
            sessionId: $deviceId,
            state: $state,
            jid: $this->stringOrNull($results['jid'] ?? null),
        );
    }

    /**
     * Verifikasi HMAC-SHA256 dari raw body (PRD §6.1).
     *
     * Secret kosong berarti receiver dinonaktifkan (keputusan #7) — event lokal
     * tidak boleh bocor ke production, jadi tolak semua request.
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        $secret = (string) config('wara.webhook_secret');

        if ($secret === '') {
            return false;
        }

        if ($signatureHeader === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        // hash_equals menahan timing attack.
        return hash_equals($expected, $signatureHeader);
    }

    protected function request(): PendingRequest
    {
        $endpoint = rtrim((string) config('wara.endpoint'), '/');

        if ($endpoint === '') {
            throw GatewayException::unreachable('(WARA_ENDPOINT kosong)');
        }

        return Http::baseUrl($endpoint)
            ->asJson()
            ->timeout((int) config('wara.timeout', 20))
            ->withBasicAuth(...$this->basicAuthParts());
    }

    /**
     * Basic auth `user:pass` (PRD §5). Kolom Gateway saja.
     *
     * @return array{0: string, 1: string}
     */
    protected function basicAuthParts(): array
    {
        $apiKey = (string) config('wara.api_key');

        if ($apiKey === '') {
            return ['', ''];
        }

        $parts = explode(':', $apiKey, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Terjemahkan error gateway menjadi exception domain.
     */
    protected function throwForError(int $status, string $body, ?string $deviceId = null): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $code = $this->errorCode($body);
        $url = rtrim((string) config('wara.endpoint'), '/');

        if ($code === self::ERR_DEVICE_NOT_FOUND && filled($deviceId)) {
            throw DeviceNotFoundException::forSessionId($deviceId);
        }

        if ($code === self::ERR_DEVICE_REQUIRED) {
            // Seharusnya tidak mungkin: manager selalu mengirim device id
            // eksplisit (PRD §6.3). Kalau muncul, itu bug di sisi Bale.
            throw GatewayException::malformedResponse(
                $url,
                'gateway meminta device id eksplisit, padahal WaraManager sudah mengirimnya'
            );
        }

        throw GatewayException::requestFailed($url, $status, $body);
    }

    protected function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw GatewayException::malformedResponse(
                rtrim((string) config('wara.endpoint'), '/'),
                'body bukan JSON yang valid'
            );
        }

        return $decoded;
    }

    /**
     * Katalog device bisa dibungkus `data`/`results` atau berupa array polos.
     */
    protected function extractDeviceRows(array $decoded): Collection
    {
        foreach (['data', 'results', 'devices'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return collect($decoded[$key])->map(fn ($row) => (array) $row)->values();
            }
        }

        return collect($decoded)->map(fn ($row) => (array) $row)->values();
    }

    protected function toDeviceInfo(array $row): DeviceInfo
    {
        return new DeviceInfo(
            // Terverifikasi 29 Sep 2026: katalog device GOWA memakai `id`
            // ("Aduan Agent", "wago prod") dan TIDAK punya field `device_id`.
            // `device_id` tetap dipertahankan sebagai fallback.
            sessionId: (string) ($row['id'] ?? $row['device_id'] ?? ''),
            jid: $this->stringOrNull($row['jid'] ?? null),
            displayName: $this->stringOrNull($row['display_name'] ?? $row['name'] ?? null),
            state: $this->toState($this->stringOrNull($row['state'] ?? null)),
        );
    }

    /**
     * Mapping state gateway -> enum internal. Nama gateway tidak boleh bocor.
     */
    protected function toState(?string $raw): DeviceState
    {
        return match (strtolower((string) $raw)) {
            'logged_in' => DeviceState::LoggedIn,
            'connected' => DeviceState::Connected,
            'connecting' => DeviceState::Connecting,
            default => DeviceState::Disconnected,
        };
    }

    protected function errorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $code = $decoded['code'] ?? $decoded['error'] ?? data_get($decoded, 'results.code');

        return is_string($code) ? $code : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
