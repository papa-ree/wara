<?php

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Jobs\DeliverToConsumer;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraWebhookEndpoint;
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

function makeClientFanOutTest(string $name = 'Client uji'): WaraClient
{
    return WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => $name,
        'is_active' => true,
    ]);
}

function makeRouteFanOutTest(WaraClient $client, string $deviceId = 'wago prod'): WaraRoute
{
    $route = new WaraRoute;

    $route->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => 'notifikasi',
        'device_id' => $deviceId,
        'inbound_webhook_enabled' => true,
    ])->save();

    return $route;
}

/**
 * 🔴 Endpoint NYATA di database, bukan array config.
 *
 * Dulu test ini memakai `config()->set('wara.consumers', ...)`. Sekarang
 * konfigurasi webhook hidup di `wara_webhook_endpoints` - inilah bentuk yang
 * dipakai produksi, dan mengujinya lewat config hanya akan menguji jalan yang
 * sudah tidak ada.
 *
 * `secret` TIDAK bisa lewat `fill()` karena tidak ada di `$fillable` - dan itu
 * disengaja: secret adalah output dari form admin, bukan input yang boleh
 * datang dari mana saja.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeEndpointFanOutTest(array $overrides = []): WaraWebhookEndpoint
{
    $client = makeClientFanOutTest('Client '.uniqid());
    makeRouteFanOutTest($client);

    $endpoint = new WaraWebhookEndpoint;

    $endpoint->forceFill(array_merge([
        'client_id' => $client->getKey(),
        'url' => 'https://srikandi.ponorogo.go.id/webhook',
        'events' => ['message'],
        'enabled' => true,
    ], $overrides));

    $endpoint->forceFill([
        'secret' => array_key_exists('secret', $overrides)
            ? $overrides['secret']
            : 'secret-srikandi-yang-cukup-panjang-32',
    ]);

    $endpoint->save();

    return $endpoint;
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

    // Go memakai < > & dan tidak meng-escape slash.
    return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function dispatchJob(?WaraWebhookEndpoint $endpoint = null): DeliverToConsumer
{
    $endpoint ??= makeEndpointFanOutTest();

    return new DeliverToConsumer(
        (string) $endpoint->getKey(),
        gowaRawBody(),
        'message',
        'wago prod',
        'wamid.ABC123',
    );
}

/**
 * 🔴 Kontrak endpoint, bukan kontrak config.
 *
 * Yang diuji di sini: endpoint default tidak aktif, secret kosong/pendek
 * ditolak, dan `http://` ke host publik ditolak (anti SSRF).
 *
 * Bedanya dari dulu: validasi TIDAK lagi jalan saat boot. Endpoint diisi lewat
 * UI admin, jadi tidak ada file config yang bisa dibaca di boot - dan melakukan
 * query database di boot juga berbahaya karena `php artisan migrate` bisa jalan
 * sebelum tabelnya ada. Guardrail-nya dipanggil dua kali: saat admin menyimpan
 * endpoint, dan lagi di dalam job saat benar-benar mengirim.
 */
describe('kontrak endpoint webhook', function () {
    it('endpoint default tidak aktif tanpa enabled=true', function () {
        $endpoint = makeEndpointFanOutTest(['enabled' => false]);

        Http::fake();

        dispatchJob($endpoint)->handle();

        Http::assertNothingSent();
        expect(WaraConsumerDelivery::query()->count())->toBe(0);
    });

    it('secret kosong pada endpoint aktif ditolak', function () {
        $endpoint = makeEndpointFanOutTest(['secret' => '']);

        expect(fn () => $endpoint->assertSecretUsable())
            ->toThrow(WaraException::class, 'minimal 32 karakter');
    });

    it('secret pendek pada endpoint aktif ditolak', function () {
        $endpoint = makeEndpointFanOutTest(['secret' => 'pendek']);

        expect(fn () => $endpoint->assertSecretUsable())
            ->toThrow(WaraException::class, 'minimal 32 karakter');
    });

    it('endpoint aktif dengan secret kosong tidak mengirim apa pun', function () {
        $endpoint = makeEndpointFanOutTest(['secret' => '']);

        Http::fake();

        expect(fn () => dispatchJob($endpoint)->handle())
            ->toThrow(WaraException::class);

        Http::assertNothingSent();
    });

    it('url http ke host publik ditolak (anti SSRF)', function () {
        $endpoint = makeEndpointFanOutTest(['url' => 'http://srikandi.ponorogo.go.id/webhook']);

        expect(fn () => $endpoint->assertUrlAllowed())
            ->toThrow(WaraException::class, 'https://');
    });

    it('url http ke localhost tetap diizinkan untuk pengembangan lokal', function () {
        $endpoint = makeEndpointFanOutTest(['url' => 'http://localhost:8080/webhook']);

        expect($endpoint->assertUrlAllowed())->toBe('http://localhost:8080/webhook');
    });

    it('url kosong ditolak dengan pesan jelas', function () {
        $endpoint = makeEndpointFanOutTest(['url' => '']);

        expect(fn () => $endpoint->assertUrlAllowed())
            ->toThrow(WaraException::class, 'URL webhook');
    });

    it('endpoint disabled tidak divalidai strict', function () {
        // Endpoint yang belum dinyalakan boleh punya url/secret kosong -
        // itulah default-nya, dan memaksa semuanya sejak awal akan membuat
        // admin tidak bisa menyimpan endpoint setengah jadi.
        $endpoint = makeEndpointFanOutTest(['enabled' => false, 'url' => 'bukan-url-valid', 'secret' => '']);

        expect($endpoint->isDeliverable())->toBeFalse();
    });
});

describe('pengiriman event (PRD §12.4)', function () {
    it('mengirim body verbatim plus delivered_via', function () {
        Http::fake();

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
        $endpoint = makeEndpointFanOutTest(['secret' => $secret]);

        dispatchJob($endpoint)->handle();

        Http::assertSent(function (Request $request) use ($secret) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

            return hash_equals($expected, (string) $request->header('X-Hub-Signature-256')[0]);
        });
    });

    it('🔴 secret terenkripsi di database, tidak disimpan polos', function () {
        $secret = 'secret-srikandi-yang-cukup-panjang-32';
        $endpoint = makeEndpointFanOutTest(['secret' => $secret]);

        $raw = DB::table('wara_webhook_endpoints')
            ->where('id', $endpoint->getKey())
            ->value('secret');

        // Ciphertext Laravel, bukan string asli.
        expect($raw)->not->toBe($secret)
            ->and($endpoint->fresh()->secret)->toBe($secret);
    });

    it('memasukkan header delivery sesuai PRD §12.4', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest();
        $job = dispatchJob($endpoint);
        $job->handle();

        Http::assertSent(fn (Request $request) => $request->header('X-Wara-Delivery-Id')[0] === $job->deliveryId
            && $request->header('X-Wara-Endpoint')[0] === (string) $endpoint->getKey()
            && $request->header('X-Wara-Event')[0] === 'message'
            && $request->header('Content-Type')[0] === 'application/json');
    });

    it('delivery_id sama persis lintas instance (stabil untuk retry)', function () {
        $endpoint = makeEndpointFanOutTest();

        $a = dispatchJob($endpoint);
        $b = dispatchJob($endpoint);

        expect($a->deliveryId)->toBe($b->deliveryId)
            ->and($a->deliveryId)->toBe(DeliverToConsumer::deliveryIdFor('message', 'wago prod', 'wamid.ABC123'));
    });

    it('delivery_id tetap deterministik walau message_id kosong', function () {
        $endpoint = makeEndpointFanOutTest();
        $id = (string) $endpoint->getKey();

        $job = new DeliverToConsumer($id, gowaRawBody(), 'message', 'wago prod', null);
        $again = new DeliverToConsumer($id, gowaRawBody(), 'message', 'wago prod', null);

        expect($job->deliveryId)->toBe($again->deliveryId)
            ->and($job->deliveryId)->not->toBe(
                DeliverToConsumer::deliveryIdFor('message', 'wago prod', 'wamid.ABC123')
            );
    });

    it('tidak mengirim event yang tidak ada di daftar events endpoint', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest(['events' => ['message.ack']]);

        dispatchJob($endpoint)->handle();

        Http::assertNothingSent();
    });

    it('endpoint subscribed ke semua event saat events kosong', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest(['events' => []]);

        dispatchJob($endpoint)->handle();

        Http::assertSentCount(1);
    });

    it('endpoint yang sudah dihapus tidak membuat job gagal', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest();
        $job = dispatchJob($endpoint);

        $endpoint->delete();

        $job->handle();

        Http::assertNothingSent();
    });
});

describe('idempotensi (PRD §7.1a, §12.6)', function () {
    it('job kedua dengan delivery_id sama tidak mengirim lagi', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest();

        dispatchJob($endpoint)->handle();
        dispatchJob($endpoint)->handle();

        Http::assertSentCount(1);
        expect(WaraConsumerDelivery::query()->count())->toBe(1);
    });

    it('attempts tidak naik saat job diulang setelah sukses', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest();

        dispatchJob($endpoint)->handle();
        dispatchJob($endpoint)->handle();
        dispatchJob($endpoint)->handle();

        expect(WaraConsumerDelivery::query()->first()->attempts)->toBe(1);
    });

    it('event berbeda tetap terkirim masing-masing', function () {
        Http::fake();

        $endpoint = makeEndpointFanOutTest(['events' => []]);
        $id = (string) $endpoint->getKey();

        dispatchJob($endpoint)->handle();
        (new DeliverToConsumer($id, gowaRawBody(), 'message', 'wago prod', 'wamid.OTHER'))->handle();

        Http::assertSentCount(2);
    });

    it('🔴 endpoint berbeda tidak saling memblokir', function () {
        Http::fake();

        $srikandi = makeEndpointFanOutTest();
        $rakaca = makeEndpointFanOutTest(['url' => 'https://rakaca.test/webhook']);

        dispatchJob($srikandi)->handle();
        dispatchJob($rakaca)->handle();

        Http::assertSentCount(2);
        expect(WaraConsumerDelivery::query()->count())->toBe(2);
    });
});

describe('penanganan kegagalan (PRD §12.4, §12.6)', function () {
    it('4xx tidak di-retry dan tercatat failed', function () {
        Http::fake(['*' => Http::response('bad request', 400)]);

        dispatchJob()->handle();

        $delivery = WaraConsumerDelivery::query()->first();

        expect($delivery->status)->toBe('failed')
            ->and($delivery->status_code)->toBe(400)
            ->and($delivery->attempts)->toBe(1)
            ->and($delivery->last_error)->toContain('HTTP 400');
    });

    it('5xx melempar exception agar worker retry', function () {
        Http::fake(['*' => Http::response('server error', 500)]);

        dispatchJob()->handle();
    })->throws(RuntimeException::class);

    it('5xx mencatat failed beserta alasan sebelum dilempar', function () {
        Http::fake(['*' => Http::response('boom', 503)]);

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

        dispatchJob()->handle();
    })->throws(RuntimeException::class);

    it('connection error tercatat lalu dilempar untuk retry', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));

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
        expect($result)->toBe('{"event":"message","payload":{"body":"a/b <c>"},"delivered_via":"bale/wara"}')
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

    it('hasilnya tetap JSON valid yang bisa di-decode client', function () {
        $decoded = json_decode(
            OutgoingPayload::withDeliveredVia(gowaRawBody()),
            true
        );

        expect($decoded)->toHaveKeys(['event', 'device_id', 'payload', 'delivered_via'])
            ->and($decoded['delivered_via'])->toBe('bale/wara')
            ->and($decoded['payload']['body'])->toBe('Kode OTP saya <123456> di a/b');
    });
});
