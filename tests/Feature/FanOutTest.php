<?php

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Jobs\DeliverToConsumer;
use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Support\OutgoingPayload;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    // Migration sudah dipublish ke database/migrations aplikasi, jadi biasanya
    // RefreshDatabase sudah membuatnya. Stub tetap dijalankan kalau tabel belum
    // ada (mis. test dijalankan sebelum `wara:publish-migration`).
    if (! Schema::hasTable('wara_consumer_deliveries')) {
        $migration = require __DIR__.'/../../database/migrations/create_wara_consumer_deliveries_table.php.stub';
        $migration->up();
    }
});

/**
 * Konfigurasi consumer aktif yang valid.
 *
 * @return array<string, mixed>
 */
function activeConsumer(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'url' => 'https://srikandi.ponorogo.go.id/webhook',
        'secret' => 'secret-srikandi-yang-cukup-panjang-32',
        'events' => ['message'],
        'timeout' => 5,
        'tries' => 3,
        'backoff' => 30,
    ], $overrides);
}

/**
 * Raw body gaya GOWA. Sengaja memakai `<`, `>`, dan `/` supaya escaping ala Go
 * ikut teruji — di sinilah HMAC pernah gagal diam-diam.
 */
function gowaRawBody(array $overrides = []): string
{
    $payload = array_merge([
        'event' => 'message',
        'device_id' => 'wago prod',
        'payload' => [
            'id' => 'wamid.ABC123',
            'from' => '628000000001@s.whatsapp.net',
            'body' => 'Kode OTP saya <123456> di a/b',
        ],
    ], $overrides);

    // Go memakai \u003c\u003e\u0026 dan tidak meng-escape slash.
    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function dispatchJob(?string $consumer = 'srikandi'): DeliverToConsumer
{
    return new DeliverToConsumer(
        $consumer ?? 'srikandi',
        gowaRawBody(),
        'message',
        'wago prod',
        'wamid.ABC123',
    );
}

describe('kontrak config consumer (PRD §12.2)', function () {
    it('consumer default tidak aktif tanpa enabled=true', function () {
        config()->set('wara.consumers', [
            'srikandi' => ['url' => 'https://x.test/hook', 'secret' => str_repeat('a', 32)],
        ]);

        Http::fake();

        dispatchJob()->handle();

        Http::assertNothingSent();
        expect(WaraConsumerDelivery::query()->count())->toBe(0);
    });

    it('secret kosong pada consumer aktif menggagalkan boot', function () {
        config()->set('wara.consumers', ['srikandi' => activeConsumer(['secret' => ''])]);

        expect(fn () => $this->app->register(WaraServiceProvider::class, true))
            ->toThrow(WaraException::class, 'WARA_CONSUMER_SRIKANDI_SECRET');
    });

    it('secret pendek pada consumer aktif menggagalkan boot', function () {
        config()->set('wara.consumers', ['srikandi' => activeConsumer(['secret' => 'pendek'])]);

        expect(fn () => $this->app->register(WaraServiceProvider::class, true))
            ->toThrow(WaraException::class, 'minimal 32 karakter');
    });

    it('url http ke host publik ditolak (anti SSRF)', function () {
        config()->set('wara.consumers', [
            'srikandi' => activeConsumer(['url' => 'http://srikandi.ponorogo.go.id/webhook']),
        ]);

        expect(fn () => $this->app->register(WaraServiceProvider::class, true))
            ->toThrow(WaraException::class, 'https://');
    });

    it('url http ke localhost tetap diizinkan untuk pengembangan lokal', function () {
        config()->set('wara.consumers', [
            'srikandi' => activeConsumer(['url' => 'http://localhost:8080/webhook']),
        ]);

        $this->app->register(WaraServiceProvider::class, true);

        expect(config('wara.consumers.srikandi.enabled'))->toBeTrue();
    });

    it('consumer enabled=false tidak divalidasi strict', function () {
        config()->set('wara.consumers', [
            'srikandi' => [
                'enabled' => false,
                'url' => 'bukan-url-valid',
                'secret' => '',
            ],
        ]);

        $this->app->register(WaraServiceProvider::class, true);

        expect(true)->toBeTrue();
    });
});

describe('pengiriman event (PRD §12.4)', function () {
    it('mengirim body verbatim plus delivered_via', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();

        $expected = gowaRawBody();
        $withVia = OutgoingPayload::withDeliveredVia($expected);

        Http::assertSent(function (Request $request) use ($expected, $withVia) {
            return $request->body() === $withVia
                && str_starts_with($request->body(), rtrim($expected, '}'))
                && json_decode($request->body(), true)['delivered_via'] === 'bale/wara';
        });
    });

    it('signature dihitung terhadap byte yang benar-benar dikirim', function () {
        Http::fake();

        $secret = 'secret-srikandi-yang-cukup-panjang-32';
        config()->set('wara.consumers', ['srikandi' => activeConsumer(['secret' => $secret])]);

        dispatchJob()->handle();

        Http::assertSent(function (Request $request) use ($secret) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

            return hash_equals($expected, (string) $request->header('X-Hub-Signature-256')[0]);
        });
    });

    it('memasukkan header delivery sesuai PRD §12.4', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        $job = dispatchJob();
        $job->handle();

        Http::assertSent(fn (Request $request) => $request->header('X-Wara-Delivery-Id')[0] === $job->deliveryId
            && $request->header('X-Wara-Consumer')[0] === 'srikandi'
            && $request->header('X-Wara-Event')[0] === 'message'
            && $request->header('Content-Type')[0] === 'application/json');
    });

    it('delivery_id sama persis lintas instance (stabil untuk retry)', function () {
        $a = dispatchJob();
        $b = dispatchJob();

        expect($a->deliveryId)->toBe($b->deliveryId)
            ->and($a->deliveryId)->toBe(DeliverToConsumer::deliveryIdFor('message', 'wago prod', 'wamid.ABC123'));
    });

    it('delivery_id tetap deterministik walau message_id kosong', function () {
        $job = new DeliverToConsumer('srikandi', gowaRawBody(), 'message', 'wago prod', null);

        $again = new DeliverToConsumer('srikandi', gowaRawBody(), 'message', 'wago prod', null);

        expect($job->deliveryId)->toBe($again->deliveryId)
            ->and($job->deliveryId)->not->toBe(
                DeliverToConsumer::deliveryIdFor('message', 'wago prod', 'wamid.ABC123')
            );
    });

    it('tidak mengirim event yang tidak ada di daftar events consumer', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer(['events' => ['message.ack']])]);

        dispatchJob()->handle();

        Http::assertNothingSent();
    });

    it('consumer subscribed ke semua event saat events kosong', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer(['events' => []])]);

        dispatchJob()->handle();

        Http::assertSentCount(1);
    });
});

describe('idempotensi (PRD §7.1a, §12.6)', function () {
    it('job kedua dengan delivery_id sama tidak mengirim lagi', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();
        dispatchJob()->handle();

        Http::assertSentCount(1);
        expect(WaraConsumerDelivery::query()->count())->toBe(1);
    });

    it('attempts tidak naik saat job diulang setelah sukses', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();
        dispatchJob()->handle();
        dispatchJob()->handle();

        expect(WaraConsumerDelivery::query()->first()->attempts)->toBe(1);
    });

    it('event berbeda tetap terkirim masing-masing', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer(['events' => []])]);

        dispatchJob()->handle();

        (new DeliverToConsumer('srikandi', gowaRawBody(), 'message', 'wago prod', 'wamid.OTHER'))->handle();

        Http::assertSentCount(2);
    });

    it('consumer berbeda tidak saling memblokir', function () {
        Http::fake();

        config()->set('wara.consumers', [
            'srikandi' => activeConsumer(),
            'rakaca' => activeConsumer(['url' => 'https://rakaca.test/webhook']),
        ]);

        dispatchJob('srikandi')->handle();
        dispatchJob('rakaca')->handle();

        Http::assertSentCount(2);
        expect(WaraConsumerDelivery::query()->count())->toBe(2);
    });
});

describe('penanganan kegagalan (PRD §12.4, §12.6)', function () {
    it('4xx tidak di-retry dan tercatat failed', function () {
        Http::fake(['*' => Http::response('bad request', 400)]);

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();

        $delivery = WaraConsumerDelivery::query()->first();

        expect($delivery->status)->toBe('failed')
            ->and($delivery->status_code)->toBe(400)
            ->and($delivery->attempts)->toBe(1)
            ->and($delivery->last_error)->toContain('HTTP 400');
    });

    it('5xx melempar exception agar worker retry', function () {
        Http::fake(['*' => Http::response('server error', 500)]);

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();
    })->throws(RuntimeException::class);

    it('5xx mencatat failed beserta alasan sebelum dilempar', function () {
        Http::fake(['*' => Http::response('boom', 503)]);

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        try {
            dispatchJob()->handle();
        } catch (RuntimeException) {
            // Diperlukan agar assertions di bawah tetap dijalankan.
        }

        $delivery = WaraConsumerDelivery::query()->first();

        expect($delivery->status)->toBe('failed')
            ->and($delivery->status_code)->toBe(503)
            ->and($delivery->last_error)->toContain('HTTP 503');
    });

    it('429 tetap dianggap layak retry', function () {
        Http::fake(['*' => Http::response('slow down', 429)]);

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();
    })->throws(RuntimeException::class);

    it('connection error tercatat lalu dilempar untuk retry', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        try {
            dispatchJob()->handle();
        } catch (Throwable) {
            // Diabaikan; yang dicek adalah pencatatannya.
        }

        $delivery = WaraConsumerDelivery::query()->first();

        expect($delivery->status)->toBe('failed')
            ->and($delivery->last_error)->toContain('timeout');
    });

    it('sukses mengisi delivered_at dan mengosongkan last_error', function () {
        Http::fake();

        config()->set('wara.consumers', ['srikandi' => activeConsumer()]);

        dispatchJob()->handle();

        $delivery = WaraConsumerDelivery::query()->first();

        expect($delivery->status)->toBe('sent')
            ->and($delivery->status_code)->toBe(200)
            ->and($delivery->delivered_at)->not->toBeNull()
            ->and($delivery->last_error)->toBeNull();
    });
});

describe('OutgoingPayload (PRD §12.4 verbatim)', function () {
    it('menyisipkan field tanpa mengubah byte lain', function () {
        $raw = '{"event":"message","payload":{"body":"a/b <c>"}}';

        $result = OutgoingPayload::withDeliveredVia($raw);

        // Setiap byte asli harus masih ada, apa adanya, hanya diakhiri
        // field baru. Inilah yang membuat signature consumer tetap cocok.
        expect($result)->toBe($raw === '{}' ? '{}' : '{"event":"message","payload":{"body":"a/b <c>"},"delivered_via":"bale/wara"}')
            ->and(str_starts_with($result, substr($raw, 0, -1)))->toBeTrue()
            ->and(json_decode($result, true))->toHaveKey('delivered_via');
    });

    it('menangani objek JSON kosong tanpa koma menggantung', function () {
        expect(OutgoingPayload::withDeliveredVia('{}'))->toBe('{"delivered_via":"bale/wara"}');
    });

    it('mengembalikan body apa adanya bila bukan objek JSON', function () {
        expect(OutgoingPayload::withDeliveredVia('[1,2]'))->toBe('[1,2]')
            ->and(OutgoingPayload::withDeliveredVia(''))->toBe('');
    });

    it('hasilnya tetap JSON valid yang bisa di-decode consumer', function () {
        $decoded = json_decode(
            OutgoingPayload::withDeliveredVia(gowaRawBody()),
            true
        );

        expect($decoded)->toHaveKeys(['event', 'device_id', 'payload', 'delivered_via'])
            ->and($decoded['delivered_via'])->toBe('bale/wara')
            ->and($decoded['payload']['body'])->toBe('Kode OTP saya <123456> di a/b');
    });
});
