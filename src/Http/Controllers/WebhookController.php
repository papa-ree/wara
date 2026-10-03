<?php

namespace Bale\Wara\Http\Controllers;

use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Events\WaraIncomingMessage;
use Bale\Wara\Jobs\DeliverToConsumer;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\ConsumerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receiver webhook gateway (PRD §6).
 *
 * Endpoint ini terbuka untuk internet, jadi verifikasi HMAC adalah satu-satunya
 * penjaganya. Tanpa itu, siapa pun bisa menyamar sebagai gateway.
 */
class WebhookController
{
    /**
     * Event yang punya arti untuk Bale (PRD §6.2).
     */
    protected const EVENT_MESSAGE = 'message';

    protected const EVENT_MESSAGE_ACK = 'message.ack';

    public function __invoke(Request $request, WaraGateway $gateway): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! $gateway->verifyWebhook($rawBody, $signature)) {
            Log::warning('Webhook Wara ditolak: signature tidak cocok.', [
                'ip' => $request->ip(),
            ]);

            abort(403, 'Invalid signature');
        }

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');
        $rawDeviceId = $this->stringOrNull($payload['device_id'] ?? null);
        $deviceId = $this->resolveDeviceId($gateway, $rawDeviceId);
        $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        $logged = match ($event) {
            self::EVENT_MESSAGE => $this->recordIncoming($deviceId, $data),
            self::EVENT_MESSAGE_ACK => $this->recordAck($deviceId, $data),
            // Event lain tetap dicatat supaya ada jejak, tapi logikanya diabaikan.
            default => $this->recordOther($event, $deviceId, $data),
        };

        // Fan-out ke consumer aktif (PRD §12.3).
        //
        // Dispatch dilakukan SESUDAH baris wara_logs tersimpan, dan dibungkus
        // try/catch supaya kegagalan antrean tidak mengubah 200 menjadi 500.
        // GOWA punya timeout webhook: 500 membuat GOWA mengulang, sementara
        // bukti inbound sudah tersimpan — hasilnya event diproses berulang
        // tanpa ada yang terkirim ke consumer.
        //
        // Job menerima RAW body, bukan array hasil decode, supaya isi yang
        // dikirim ke consumer identik byte-wise dengan yang GOWA kirim
        // (PRD §12.4). Lihat OutgoingPayload.
        $this->dispatchToConsumers($rawBody, $event, $deviceId, $data);

        // Event Laravel untuk consumer dalam proses yang sama (TOPIK §7).
        //
        // Dispatch di LUAR transaksi dan SESUDAH wara_logs tersimpan, dengan
        // try/catch:
        //   - gagal di dalam try/catch => bukti inbound tetap ada di wara_logs;
        //   - 500 => GOWA mengirim ulang event yang sama tanpa ada efek apa pun.
        $this->dispatchIncomingEvent($rawBody, $event, $deviceId, $data);

        return response()->json([
            'ok' => true,
            'event' => $event,
            'recorded' => $logged,
        ]);
    }

    /**
     * Pancarkan `WaraIncomingMessage` ke semua listener dalam proses.
     *
     * Kegagalan listener TIDAK boleh mengubah status HTTP. Baris `wara_logs`
     * sudah tersimpan dan itu buktinya, sedangkan 500 membuat GOWA mengulang
     * event yang sama — listener yang listener-nya exception akan dipanggil
     * berulang dengan hasil tetap sama.
     *
     * @param  array<string, mixed>  $data  isi `payload` dari event
     */
    protected function dispatchIncomingEvent(
        string $rawBody,
        string $event,
        ?string $deviceId,
        array $data,
    ): void {
        if ($event !== self::EVENT_MESSAGE || $deviceId === null) {
            return;
        }

        try {
            WaraIncomingMessage::dispatch(
                $deviceId,
                $event,
                $this->stringOrNull($data['id'] ?? $data['message_id'] ?? null),
                $this->stripJid($this->stringOrNull($data['from'] ?? null)),
                $this->stringOrNull($data['chat_id'] ?? null),
                $this->stringOrNull($data['body'] ?? null),
                $rawBody,
                $this->resolveReceivedAt($data),
            );
        } catch (\Throwable $e) {
            Log::error('Wara: gagal dispatch WaraIncomingMessage.', [
                'event' => $event,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Waktu penerimaan menurut gateway, kalau gateway mengirimkannya.
     *
     * Sengaja dikembalikan sebagai string: nilai ini milik gateway, bukan
     * milik server kita, jadi tidak boleh disetel ulang ke `now()`.
     */
    protected function resolveReceivedAt(array $data): ?string
    {
        $receivedAt = $this->stringOrNull($data['timestamp'] ?? null);

        if ($receivedAt !== null) {
            return $receivedAt;
        }

        return $this->stringOrNull($data['created_at'] ?? null);
    }

    /**
     * 🔴 Samakan identitas device untuk pesan MASUK.
     *
     * Untuk pesan keluar kita sendiri yang memilih device, jadi `device_id`
     * yang kita tulis sudah benar (nama device, mis. `wago prod`).
     *
     * Untuk pesan masuk, GOWA mengisi `device_id` dengan JID akun yang
     * MENERIMA pesan. Itu membuat kolom `wara_logs.device_id` berarti dua hal
     * berbeda tergantung arah, sehingga query "pesan masuk untuk device X"
     * selalu salah: `device_id` tidak pernah sama dengan
     * `wara_sessions.device_id`.
     *
     * Terbukti di data: 761 baris inbound memakai JID, sementara
     * `wara_sessions.device_id` berisi nama device. Keduanya sebenarnya
     * pasangan yang sama, tapi tidak ada yang mengetahuinya.
     *
     * `wara_sessions.jid` menyimpan nomor device itu, jadi JID bisa dipetakan
     * balik ke nama device. Kalau tidak ada device yang cocok, hasilnya `null` -
     * lebih baik kosong daripada menyimpan identitas palsukan yang tidak akan
     * pernah cocok dengan tabel mana pun.
     *
     * Bentuk JID adalah detail gateway, jadi mengenali JID TIDAK ditulis di
     * sini; kita bertanya ke gateway lewat kontrak.
     */
    protected function resolveDeviceId(WaraGateway $gateway, ?string $rawDeviceId): ?string
    {
        if ($rawDeviceId === null || $rawDeviceId === '') {
            return null;
        }

        if (! $gateway->isDeviceJid($rawDeviceId)) {
            // Sudah nama device (outbound, atau gateway yang mengirim nama).
            return $rawDeviceId;
        }

        return WaraSession::query()
            ->where('jid', $rawDeviceId)
            ->value('device_id');
    }

    /**
     * Pesan masuk — inilah tempat balasan operator (kode OTP/captcha) tiba.
     */
    protected function recordIncoming(?string $deviceId, array $data): bool
    {
        WaraLog::query()->create([
            'direction' => 'in',
            'event' => self::EVENT_MESSAGE,
            'device_id' => $deviceId,
            'chat_id' => $this->stringOrNull($data['chat_id'] ?? null),
            'phone' => $this->stripJid($this->stringOrNull($data['from'] ?? null)),
            'message_id' => $this->stringOrNull($data['id'] ?? $data['message_id'] ?? null),
            'body' => $this->stringOrNull($data['body'] ?? null),
            'status' => 'received',
        ]);

        return true;
    }

    /**
     * Bukti pesan keluar sampai (`delivered` / `read`).
     *
     * PENTING (PRD §8.1): webhook ini adalah bukti terkirim, bukan jaminan.
     */
    protected function recordAck(?string $deviceId, array $data): bool
    {
        WaraLog::query()->create([
            'direction' => 'in',
            'event' => self::EVENT_MESSAGE_ACK,
            'device_id' => $deviceId,
            'message_id' => $this->stringOrNull($data['message_id'] ?? $data['id'] ?? null),
            'status' => $this->stringOrNull($data['receipt_type'] ?? null),
        ]);

        return true;
    }

    protected function recordOther(string $event, ?string $deviceId, array $data): bool
    {
        WaraLog::query()->create([
            'direction' => 'in',
            'event' => $event !== '' ? $event : 'unknown',
            'device_id' => $deviceId,
            'status' => 'ignored',
        ]);

        return true;
    }

    /**
     * Kirim event ke setiap consumer yang aktif dan tertarik (PRD §12.3).
     *
     * Kegagalan enqueue tidak boleh mengubah status HTTP: baris `wara_logs`
     * sudah tersimpan dan itu buktinya, sedangkan 500 akan memicu GOWA
     * mengirim ulang event yang sama.
     *
     * @param  array<string, mixed>  $data  isi `payload` dari event
     */
    protected function dispatchToConsumers(
        string $rawBody,
        string $event,
        ?string $deviceId,
        array $data,
    ): void {
        $messageId = $this->stringOrNull(
            $data['id'] ?? $data['message_id'] ?? null
        );

        $consumers = config('wara.consumers', []);

        if (! is_array($consumers)) {
            return;
        }

        foreach ($consumers as $name => $rawConfig) {
            $config = ConsumerConfig::normalize(is_array($rawConfig) ? $rawConfig : []);

            if (! ConsumerConfig::isEnabled($config)) {
                continue;
            }

            if (! ConsumerConfig::subscribesTo($config['events'], $event !== '' ? $event : null)) {
                continue;
            }

            try {
                DeliverToConsumer::dispatch(
                    (string) $name,
                    $rawBody,
                    $event !== '' ? $event : null,
                    $deviceId,
                    $messageId,
                );
            } catch (\Throwable $e) {
                Log::error('Fan-out Wara: gagal mengantrekan event ke consumer.', [
                    'consumer' => $name,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Buang sufiks JID supaya kolom `phone` konsisten format internasional.
     */
    protected function stripJid(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $atPosition = strpos($value, '@');

        return $atPosition === false ? $value : substr($value, 0, $atPosition);
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
