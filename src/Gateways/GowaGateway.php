<?php

namespace Bale\Wara\Gateways;

use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Data\ChatMessage;
use Bale\Wara\Data\ChatSummary;
use Bale\Wara\Data\DeviceInfo;
use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceState;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\DownloadedMedia;
use Bale\Wara\Data\PairingCode;
use Bale\Wara\Data\QrLogin;
use Bale\Wara\Data\SendResult;
use Bale\Wara\Data\WebhookConfig;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\GatewayException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    public function sendText(
        string $deviceId,
        string $phone,
        string $text,
        ?string $replyToMessageId = null,
    ): SendResult {
        $payload = [
            'phone' => $phone.self::JID_SUFFIX,
            'message' => $text,
        ];

        if ($replyToMessageId !== null) {
            $payload['reply_message_id'] = $replyToMessageId;
        }

        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->post('/send/message', $payload);

        $this->throwForError($response->status(), $response->body(), $deviceId);

        $decoded = $this->decode($response->body());

        return SendResult::accepted(
            messageId: $this->stringOrNull(data_get($decoded, 'results.message_id')
                ?? data_get($decoded, 'message_id')),
            status: 'accepted'
        );
    }

    public function sendImage(
        string $deviceId,
        string $phone,
        string $imageUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult {
        return $this->sendMedia('/send/image', 'image_url', $imageUrl, $deviceId, $phone, $caption, $replyToMessageId);
    }

    public function sendVideo(string $deviceId, string $phone, string $videoUrl, ?string $caption = null): SendResult
    {
        return $this->sendMedia('/send/video', 'video_url', $videoUrl, $deviceId, $phone, $caption);
    }

    public function sendDocument(
        string $deviceId,
        string $phone,
        string $fileUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult {
        return $this->sendMedia('/send/file', 'file_url', $fileUrl, $deviceId, $phone, $caption, $replyToMessageId);
    }

    public function sendAudio(
        string $deviceId,
        string $phone,
        string $audioUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult {
        return $this->sendMedia('/send/audio', 'audio_url', $audioUrl, $deviceId, $phone, $caption, $replyToMessageId);
    }

    /**
     * Dua langkah: minta GOWA menyimpan media pesan, lalu ambil byte-nya.
     *
     * Endpoint download membalas JSON berisi `file_url` (URL statis GOWA yang
     * sudah berbasis host request kita), bukan stream. URL itu tidak pernah
     * keluar dari kelas ini (PRD A11.2).
     */
    public function downloadMedia(string $deviceId, string $messageId, string $phone): ?DownloadedMedia
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->get('/message/'.rawurlencode($messageId).'/download?phone='.rawurlencode($phone));

        $this->throwForError($response->status(), $response->body(), $deviceId);

        $decoded = $this->decode($response->body());
        $results = (array) ($decoded['results'] ?? $decoded);
        $url = $this->stringOrNull($results['file_url'] ?? null);

        if ($url === null) {
            return null;
        }

        $file = Http::timeout((int) config('wara.timeout', 20))->get($url);

        if (! $file->successful()) {
            throw GatewayException::requestFailed($url, $file->status());
        }

        $contents = $file->body();
        $filename = $this->stringOrNull($results['filename'] ?? null)
            ?? basename((string) parse_url($url, PHP_URL_PATH));

        return new DownloadedMedia(
            contents: $contents,
            filename: $filename !== '' ? $filename : 'media',
            size: (int) ($results['file_size'] ?? strlen($contents)),
        );
    }

    public function markRead(string $deviceId, string $messageId, string $phone): void
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->post('/message/'.rawurlencode($messageId).'/read', [
                'phone' => $phone.self::JID_SUFFIX,
            ]);

        $this->throwForError($response->status(), $response->body(), $deviceId);
    }

    public function revokeMessage(string $deviceId, string $messageId, string $phone): void
    {
        $this->messageAction($deviceId, $messageId, $phone, 'revoke');
    }

    public function deleteMessage(string $deviceId, string $messageId, string $phone): void
    {
        $this->messageAction($deviceId, $messageId, $phone, 'delete');
    }

    /** Endpoint aksi per pesan berbagi bentuk request yang sama. */
    protected function messageAction(string $deviceId, string $messageId, string $phone, string $action): void
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->post('/message/'.rawurlencode($messageId).'/'.$action, [
                'phone' => $phone.self::JID_SUFFIX,
            ]);

        $this->throwForError($response->status(), $response->body(), $deviceId);
    }

    public function chats(string $deviceId, int $limit = 100, int $offset = 0): Collection
    {
        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->get('/chats', ['limit' => $limit, 'offset' => $offset]);

        $this->throwForError($response->status(), $response->body(), $deviceId);

        return $this->rows($this->decode($response->body()))
            ->map(fn (array $row) => new ChatSummary(
                phone: $this->phoneFromJid($this->stringOrNull($row['jid'] ?? null)),
                name: $this->stringOrNull($row['name'] ?? null),
                lastMessageAt: $this->toDate($row['last_message_time'] ?? null),
            ));
    }

    public function chatMessages(
        string $deviceId,
        string $phone,
        ?DateTimeInterface $since = null,
        int $limit = 100,
        int $offset = 0,
    ): Collection {
        $query = ['limit' => $limit, 'offset' => $offset];

        if ($since !== null) {
            $query['start_time'] = CarbonImmutable::instance($since)->toIso8601String();
        }

        $response = $this->request()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->get('/chat/'.rawurlencode($phone.self::JID_SUFFIX).'/messages', $query);

        $this->throwForError($response->status(), $response->body(), $deviceId);

        return $this->rows($this->decode($response->body()))
            ->map(fn (array $row) => new ChatMessage(
                id: (string) ($row['id'] ?? ''),
                body: (string) ($row['content'] ?? ''),
                timestamp: $this->toDate($row['timestamp'] ?? null),
                isFromMe: (bool) ($row['is_from_me'] ?? false),
                mediaType: $this->stringOrNull($row['media_type'] ?? null),
                mediaName: $this->stringOrNull($row['filename'] ?? null),
            ));
    }

    /**
     * Endpoint media GOWA memakai `multipart/form-data`, bukan JSON.
     *
     * Kita mengirim URL (`*_url`), bukan binary: file sudah disimpan di disk
     * Bale (konvensi bale-core), jadi gateway yang mengunduh.
     */
    protected function sendMedia(
        string $path,
        string $urlField,
        string $mediaUrl,
        string $deviceId,
        string $phone,
        ?string $caption,
        ?string $replyToMessageId = null,
    ): SendResult {
        $fields = [
            'phone' => $phone.self::JID_SUFFIX,
            $urlField => $mediaUrl,
            'caption' => (string) ($caption ?? ''),
        ];

        // GOWA mem-bind field form; `reply_message_id` kosong akan ditolak.
        if ($replyToMessageId !== null) {
            $fields['reply_message_id'] = $replyToMessageId;
        }

        $response = $this->request(json: false)
            ->asMultipart()
            ->withHeaders([self::DEVICE_HEADER => $deviceId])
            ->post($path, $fields);

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

    public function addDevice(?string $sessionId = null, ?WebhookConfig $webhook = null): DeviceInfo
    {
        $payload = filled($sessionId) ? ['device_id' => $sessionId] : [];

        if ($webhook !== null) {
            $payload += $this->webhookPayload($webhook);
        }

        $response = $this->request()->post('/devices', $payload);

        $this->throwForError($response->status(), $response->body());

        $decoded = $this->decode($response->body());

        return $this->toDeviceInfo((array) ($decoded['results'] ?? $decoded));
    }

    public function loginQr(string $sessionId): QrLogin
    {
        $response = $this->request()->get('/devices/'.rawurlencode($sessionId).'/login');

        $this->throwForError($response->status(), $response->body(), $sessionId);

        $decoded = $this->decode($response->body());
        $results = (array) ($decoded['results'] ?? $decoded);

        $qrLink = $this->stringOrNull($results['qr_link'] ?? null);

        if ($qrLink === null) {
            throw GatewayException::malformedResponse($this->endpoint(), 'respons login tidak memuat QR');
        }

        return new QrLogin(
            deviceId: (string) ($results['device_id'] ?? $sessionId),
            expiresInSeconds: (int) ($results['qr_duration'] ?? 0),
            imageDataUri: $this->toDataUri($qrLink),
        );
    }

    public function loginWithCode(string $sessionId, string $phone): PairingCode
    {
        $response = $this->request()
            ->post('/devices/'.rawurlencode($sessionId).'/login/code?phone='.rawurlencode($phone));

        $this->throwForError($response->status(), $response->body(), $sessionId);

        $decoded = $this->decode($response->body());
        $results = (array) ($decoded['results'] ?? $decoded);

        $code = $this->stringOrNull($results['pair_code'] ?? null);

        if ($code === null) {
            throw GatewayException::malformedResponse($this->endpoint(), 'respons pairing tidak memuat kode');
        }

        return new PairingCode(
            deviceId: (string) ($results['device_id'] ?? $sessionId),
            code: $code,
        );
    }

    public function reconnect(string $sessionId): void
    {
        $response = $this->request()->post('/devices/'.rawurlencode($sessionId).'/reconnect');

        $this->throwForError($response->status(), $response->body(), $sessionId);
    }

    public function logout(string $sessionId): void
    {
        $response = $this->request()->post('/devices/'.rawurlencode($sessionId).'/logout');

        $this->throwForError($response->status(), $response->body(), $sessionId);
    }

    public function removeDevice(string $sessionId): void
    {
        $response = $this->request()->delete('/devices/'.rawurlencode($sessionId));

        $this->throwForError($response->status(), $response->body(), $sessionId);
    }

    public function getWebhook(string $sessionId): WebhookConfig
    {
        $response = $this->request()->get('/devices/'.rawurlencode($sessionId).'/webhook');

        $this->throwForError($response->status(), $response->body(), $sessionId);

        $decoded = $this->decode($response->body());

        return $this->toWebhookConfig((array) ($decoded['results'] ?? $decoded));
    }

    public function setWebhook(string $sessionId, WebhookConfig $webhook): WebhookConfig
    {
        $response = $this->request()
            ->patch('/devices/'.rawurlencode($sessionId).'/webhook', $this->webhookPayload($webhook));

        $this->throwForError($response->status(), $response->body(), $sessionId);

        $decoded = $this->decode($response->body());

        return $this->toWebhookConfig((array) ($decoded['results'] ?? $decoded));
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

    protected function request(bool $json = true): PendingRequest
    {
        $endpoint = rtrim((string) config('wara.endpoint'), '/');

        if ($endpoint === '') {
            throw GatewayException::unreachable('(WARA_ENDPOINT kosong)');
        }

        $request = Http::baseUrl($endpoint)
            ->timeout((int) config('wara.timeout', 20))
            ->withBasicAuth(...$this->basicAuthParts());

        // `asJson()` juga menulis header Content-Type. Kalau request multipart
        // memakainya, GOWA mem-parse body sebagai JSON dan menolak boundary `--`.
        return $json ? $request->asJson() : $request;
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

    /**
     * Baris `results.data` pada respons chat GOWA.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(array $decoded): Collection
    {
        $rows = data_get($decoded, 'results.data');

        if (! is_array($rows)) {
            return collect();
        }

        return collect($rows)->map(fn ($row) => (array) $row)->values();
    }

    /**
     * Nomor dari JID percakapan; NULL untuk grup/newsletter/broadcast.
     */
    protected function phoneFromJid(?string $jid): ?string
    {
        if ($jid === null) {
            return null;
        }

        $at = strpos($jid, '@');
        $suffix = $at === false ? '' : strtolower(substr($jid, $at));

        if ($suffix !== '' && $suffix !== self::JID_SUFFIX) {
            return null;
        }

        $user = $at === false ? $jid : substr($jid, 0, $at);
        $colon = strpos($user, ':');

        if ($colon !== false) {
            $user = substr($user, 0, $colon);
        }

        $user = trim($user);

        return $user === '' ? null : $user;
    }

    protected function toDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
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

    protected function endpoint(): string
    {
        return rtrim((string) config('wara.endpoint'), '/');
    }

    /**
     * Unduh gambar QR di sisi server dan ubah jadi data-URI (keputusan #29).
     *
     * 🔴 Kalau URL gateway dikirim ke browser, dua hal rusak sekaligus:
     * invariant §11.2 (host gateway bocor) dan deployment dengan jaringan
     * tertutup (browser admin tidak bisa menjangkau host itu).
     */
    protected function toDataUri(string $link): string
    {
        if (str_starts_with($link, 'data:')) {
            return $link;
        }

        if (! str_starts_with($link, 'http://') && ! str_starts_with($link, 'https://')) {
            $link = $this->endpoint().'/'.ltrim($link, '/');
        }

        $response = Http::timeout((int) config('wara.timeout', 20))->get($link);

        if (! $response->successful() || $response->body() === '') {
            throw GatewayException::requestFailed($link, $response->status());
        }

        return 'data:image/png;base64,'.base64_encode($response->body());
    }

    /**
     * @return array<string, mixed>
     */
    protected function webhookPayload(WebhookConfig $webhook): array
    {
        $payload = [
            'webhook_url' => (string) ($webhook->url ?? ''),
            // GOWA menerima daftar event sebagai satu string berkoma, bukan
            // array; array ditolak dengan 400 dan konfigurasi tidak tersimpan.
            'webhook_events' => implode(',', $webhook->events),
            'webhook_insecure_skip_verify' => $webhook->insecureSkipVerify,
        ];

        // 🔴 Secret kosong berarti "jangan diubah". Mengirim string kosong
        // akan MENGHAPUS secret yang sudah terpasang di gateway, dan webhook
        // penerima langsung menolak semua event tanpa error yang terlihat.
        if (filled($webhook->secret)) {
            $payload['webhook_secret'] = $webhook->secret;
        }

        return $payload;
    }

    protected function toWebhookConfig(array $results): WebhookConfig
    {
        $events = $results['webhook_events'] ?? [];

        if (is_string($events)) {
            $events = array_filter(array_map('trim', explode(',', $events)), fn ($event) => $event !== '');
        }

        return new WebhookConfig(
            url: $this->stringOrNull($results['webhook_url'] ?? null),
            secret: $this->stringOrNull($results['webhook_secret'] ?? null),
            events: is_array($events) ? array_values(array_map('strval', $events)) : [],
            insecureSkipVerify: (bool) ($results['webhook_insecure_skip_verify'] ?? false),
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
