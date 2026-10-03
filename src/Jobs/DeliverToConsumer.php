<?php

namespace Bale\Wara\Jobs;

use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Models\WaraWebhookEndpoint;
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
 *  - **Tidak mengirim apa pun ke endpoint `enabled=false`.**
 *  - **Signature dihitung terhadap byte yang benar-benar dikirim.** Body dikirim
 *    lewat `withBody()` dengan string yang persis sama yang di-HMAC, bukan
 *    array yang akan di-encode ulang oleh HTTP client.
 *
 * 🔴 Konfigurasi diambil dari `wara_webhook_endpoints`, bukan dari
 * `config('wara.consumers')` yang sudah dihapus. Konsekuensinya: admin bisa
 * menambah client dan endpoint-nya dari UI tanpa menyentuh file config, dan
 * on/off-nya bisa berbeda per package - bukan global.
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

    /**
     * UUID endpoint tujuan.
     *
     * 🔴 Dulu kolom ini berisi NAMA consumer yang diambil dari
     * `config('wara.consumers')`. Sekarang isinya UUID
     * `wara_webhook_endpoints.id`.
     *
     * Kolom di `wara_consumer_deliveries` tetap bernama `consumer` supaya tidak
     * perlu migrasi tambahan - tapi isinya sudah bukan "consumer" dalam arti lama,
     * dan kolom itu dip migrating lewat job ini saja.
     */
    public string $endpointId;

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
        string $endpointId,
        string $rawBody,
        ?string $event = null,
        ?string $deviceId = null,
        ?string $messageId = null,
    ) {
        $this->endpointId = $endpointId;
        $this->rawBody = $rawBody;
        $this->event = $event;
        $this->deviceId = $deviceId;
        $this->messageId = $messageId;

        // 🔴 Config retry SELALU datang dari config paket, bukan dari baris
        // endpoint. Endpointistered lewat UI, jadi `tries`/`backoff` yang bisa
        // ikut diubah dari sana akan jadi Surface area yang tidak perlu.
        $this->tries = (int) config('wara.fanout.tries', 3);
        $this->backoff = (int) config('wara.fanout.backoff', 30);

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
        $endpoint = WaraWebhookEndpoint::query()->find($this->endpointId);

        // Endpoint dihapus setelah job diantrekan. Berhenti diam, bukan retry:
        // tidak ada lagi tujuan yang sah untuk dikirim.
        if ($endpoint === null) {
            Log::info('Fan-out Wara: endpoint sudah tidak ada, pengiriman dibatalkan.', [
                'endpoint_id' => $this->endpointId,
                'delivery_id' => $this->deliveryId,
            ]);

            return;
        }

        // 🔴 Guardrail dicek ULANG di sini, bukan hanya saat dispatch: job
        // yang sudah terlanjur tersimpan di queue tidak boleh melewati
        // keputusan admin yang baru dibuat setelah job itu masuk antrean.
        if (! $endpoint->isActive()) {
            return;
        }

        if (! WaraWebhookEndpoint::subscribesTo($endpoint->events, $this->event)) {
            return;
        }

        $url = $endpoint->assertUrlAllowed();
        $secret = $endpoint->assertSecretUsable();

        $timeout = (int) config('wara.fanout.timeout', 5);

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
                'X-Wara-Endpoint' => $this->endpointId,
                'X-Wara-Event' => $this->event ?? 'unknown',
            ])
                ->timeout($timeout)
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
                'endpoint_id' => $this->endpointId,
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
                    // 🔴 Nama KOLOMNYA masih `consumer`, isinya yang berubah jadi
                    // UUID endpoint. Menukar keduanya di sini tidak akan
                    // melempar error yang tidak sengaja: `catch (QueryException)` di
                    // bawah menelan "no such column" dan job berhenti diam.
                    'consumer' => $this->endpointId,
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
            // 🔴 HANYA pelanggaran unique yang aman ditelan. Itu memang balapan:
            // worker lain sudah membuat kunci yang sama lebih dulu.
            //
            // Error skema (kolom hilang, nilainya tidak muat) harus DILEMPAR,
            // bukan diperlakukan sebagai "sudah dikIrirmat". Menelannya membuat
            // fan-out mati total tanpa satu baris pun di log.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            Log::info('Fan-out Wara: delivery key bentrok saat insert, dilewati.', [
                'endpoint_id' => $this->endpointId,
                'delivery_id' => $this->deliveryId,
            ]);

            return null;
        }

        if ($delivery->status === 'sent') {
            return null;
        }

        return $delivery;
    }

    /**
     * Apakah ini pelanggaran unique index, bukan error skema?
     *
     * SQLite dan MySQL memakai kode yang berbeda, jadi keduanya dicek. TUJUANNYA
     * satu: jangan sampai error yang bukan balapan ikut ditelan.
     */
    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();

        // SQLSTATE 23000 = integrity constraint violation.
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
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
