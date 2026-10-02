<?php

namespace Bale\Wara\Jobs;

use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Support\ConsumerConfig;
use Bale\Wara\Support\OutgoingPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Teruskan satu event webhook GOWA ke aplikasi consumer (PRD §12.4).
 *
 * Sifat penting job ini:
 *
 *  - **Idempoten.** `deliveryId` dihitung dari `event|device_id|message_id`
 *    dan dihitung SEKALI di constructor, jadi nilainya sama persis di semua
 *    percobaan retry. Header `X-Wara-Delivery-Id` karena itu juga stabil,
 *    sehingga client bisa menolak duplikat (§12.4).
 *  - **Tidak mengirim apa pun ke consumer `enabled=false`.**
 *  - **Signature dihitung terhadap byte yang benar-benar dikirim.** Body dikirim
 *    lewat `withBody()` dengan string yang persis sama yang di-HMAC, bukan
 *    array yang akan di-encode ulang oleh HTTP client.
 */
class DeliverToConsumer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Dideklarasikan eksplisit: `Queueable` tidak mendeklarasikan `$tries` /
     * `$backoff`, jadi mengassign langsung akan membuat dynamic property
     * (deprecated di PHP 8.2+).
     */
    public int $tries = 3;

    public int $backoff = 30;

    public string $consumer;

    /**
     * Kunci idempotensi, hex sha256. Stabil lintas retry.
     */
    public string $deliveryId;

    /**
     * Raw body apa adanya seperti diterima dari GOWA.
     */
    public string $rawBody;

    public ?string $event;

    public ?string $deviceId;

    public ?string $messageId;

    public function __construct(
        string $consumer,
        string $rawBody,
        ?string $event = null,
        ?string $deviceId = null,
        ?string $messageId = null,
    ) {
        $config = ConsumerConfig::normalize(ConsumerConfig::resolve($consumer));

        $this->consumer = $consumer;
        $this->rawBody = $rawBody;
        $this->event = $event;
        $this->deviceId = $deviceId;
        $this->messageId = $messageId;
        $this->tries = $config['tries'];
        $this->backoff = $config['backoff'];

        $this->deliveryId = self::deliveryIdFor($event, $deviceId, $messageId);
    }

    /**
     * Kunci idempotensi event (PRD §12.4).
     *
     * Sengaja TIDAK memakai nilai acak untuk komponen yang kosong: kalau
     * message_id kosong dan kita mengarang nilai, dua pengiriman event yang
     * sama akan menghasilkan delivery_id berbeda — persis kebalikan dari
     * tujuan idempotensi.
     */
    public static function deliveryIdFor(?string $event, ?string $deviceId, ?string $messageId): string
    {
        return hash('sha256', implode('|', [
            $event ?? '',
            $deviceId ?? '',
            $messageId ?? '',
        ]));
    }

    public function handle(): void
    {
        $config = ConsumerConfig::normalize(ConsumerConfig::resolve($this->consumer));

        // Dois hal ini checked ulang di sini, bukan hanya saat boot: job yang
        // sudah terlanjur tersimpan di queue tidak boleh melewati guardrail
        // setelah config berubah.
        if (! ConsumerConfig::isEnabled($config)) {
            return;
        }

        if (! ConsumerConfig::subscribesTo($config['events'], $this->event)) {
            return;
        }

        $url = ConsumerConfig::assertUrlAllowed($this->consumer, $config['url']);
        $secret = ConsumerConfig::assertSecretUsable($this->consumer, $config['secret']);

        $delivery = $this->reserveDelivery();

        // Sudah pernah terkirim. Job yang di-retry setelah `sent` harus berhenti
        // diam dan TIDAK menambah `attempts` (PRD §7.1a).
        if ($delivery === null) {
            return;
        }

        $body = OutgoingPayload::withDeliveredVia($this->rawBody);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->markSending($delivery);

        try {
            $response = Http::withHeaders([
                'X-Hub-Signature-256' => $signature,
                'X-Wara-Delivery-Id' => $this->deliveryId,
                'X-Wara-Consumer' => $this->consumer,
                'X-Wara-Event' => $this->event ?? 'unknown',
            ])
                ->timeout($config['timeout'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            // Timeout / DNS / koneksi gagal: ini kondisi yang layak retry.
            $this->markFailed($delivery, null, $e->getMessage());

            throw $e;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => 'sent',
                'status_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ])->save();

            return;
        }

        $status = $response->status();
        $error = sprintf('HTTP %d: %s', $status, Str::limit($response->body(), 500));

        $this->markFailed($delivery, $status, $error);

        // 4xx = bug di sisi consumer; retry tidak akan menolongnya, dan
        // memaksakan retry hanya memperpanjang antrean. 429 tetap di-retry
        // karena memang sinyal "terlalu cepat".
        if ($status >= 400 && $status < 500 && $status !== 429) {
            Log::warning('Fan-out Wara: consumer menolak dengan 4xx, tidak di-retry.', [
                'consumer' => $this->consumer,
                'delivery_id' => $this->deliveryId,
                'status' => $status,
            ]);

            return;
        }

        throw new RuntimeException($error);
    }

    /**
     * Pastikan ada baris delivery untuk kunci ini.
     *
     * Mengembalikan `null` kalau event ini sudah pernah terkirim — itu sinyal
     * untuk berhenti diam.
     */
    protected function reserveDelivery(): ?WaraConsumerDelivery
    {
        try {
            $delivery = WaraConsumerDelivery::query()->firstOrCreate(
                [
                    'consumer' => $this->consumer,
                    'delivery_id' => $this->deliveryId,
                ],
                [
                    'event' => $this->event,
                    'device_id' => $this->deviceId,
                    'message_id' => $this->messageId,
                    'status' => 'queued',
                    'attempts' => 0,
                ],
            );
        } catch (QueryException $e) {
            // Another worker won the race and created the same key. Not an
            // error for us — just treat it as "already reserved".
            Log::info('Fan-out Wara: delivery key bentrok saat insert, dilewati.', [
                'consumer' => $this->consumer,
                'delivery_id' => $this->deliveryId,
            ]);

            return null;
        }

        if ($delivery->status === 'sent') {
            return null;
        }

        return $delivery;
    }

    protected function markSending(WaraConsumerDelivery $delivery): void
    {
        $delivery->increment('attempts');
        $delivery->forceFill(['status' => 'sending'])->save();
    }

    protected function markFailed(WaraConsumerDelivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'status' => 'failed',
            'status_code' => $status,
            'last_error' => Str::limit($error, 1000),
        ])->save();
    }
}
