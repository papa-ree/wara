<?php

use Bale\Api\Services\TokenManager;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraRoute;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.webhook_secret', 'rahasia-webhook');
    config()->set('wara.default_device_id', '');

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response([
        'id' => 'msg-1',
        'status' => 'accepted',
    ], 200)]);
});

function sessionForApiTest(array $overrides = []): WaraSession
{
    static $counter = 0;
    $counter++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'api_dev_'.$counter,
        'jid' => '6289900000'.$counter.'@s.whatsapp.net',
        'display_name' => 'Device api '.$counter,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

/**
 * Client + route, DAN token yang dipetakan ke client itu.
 *
 * 🔴 Ini inti dari seluruh refactor: token harus bisa dipetakan ke client.
 * Sebelum ada `wara_clients.api_token_id` dan `api_tokens.bale_id`, tidak ada
 * cara menghubungkan keduanya - dan route akan mustahil bekerja.
 *
 * @param  list<string>  $scopes
 * @return array{client: WaraClient, session: WaraSession, header: array<string, string>}
 */
function clientWithTokenApiTest(
    string $purpose = 'notifikasi',
    ?string $deviceId = null,
    array $scopes = ['wara.send'],
    array $sessionOverrides = [],
): array {
    $session = sessionForApiTest($deviceId === null ? [] : array_merge(['device_id' => $deviceId], $sessionOverrides));

    $issued = app(TokenManager::class)->issue('Token api', $scopes);

    // 🔴 `api_token_id` di luar $fillable. Itu disengaja: kolom itu menentukan
    // "siapa" client ini, dan tidak boleh datang dari payload mana pun.
    // Kalau test memakai create(), nilainya diam-diam NULL dan seluruh test
    // ini hanya akan menguji jalan 403.
    $client = new WaraClient;
    $client->forceFill([
        'type' => WaraClient::TYPE_TOKEN,
        'api_token_id' => $issued['model']->getKey(),
        'name' => 'Client token',
        'is_active' => true,
    ])->save();

    (new WaraRoute)->forceFill([
        'client_id' => $client->getKey(),
        'purpose' => $purpose,
        'device_id' => $session->device_id,
    ])->save();

    return [
        'client' => $client,
        'session' => $session,
        'header' => ['Authorization' => 'Bearer '.$issued['plain']],
    ];
}

describe('POST /api/v1/wara/send', function () {
    it('🔴 mengirim memakai device dari route client', function () {
        $ctx = clientWithTokenApiTest('notifikasi');

        $this->postJson('/api/v1/wara/send', [
            'to' => '0812 3456 7890',
            'message' => 'halo',
        ], $ctx['header'])->assertOk()
            ->assertJson(['status' => 'sent', 'purpose' => 'notifikasi']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Device-Id', $ctx['session']->device_id));
    });

    it('🔴 menolak token yang tidak dipetakan ke client', function () {
        $issued = app(TokenManager::class)->issue('Token liar', ['wara.send']);

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], ['Authorization' => 'Bearer '.$issued['plain']])
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('🔴 menolak client yang dinonaktifkan walau route-nya ada', function () {
        $ctx = clientWithTokenApiTest();
        $ctx['client']->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], $ctx['header'])->assertForbidden();

        Http::assertNothingSent();
    });

    it('🔴 client dengan dua route wajib menyebut purpose', function () {
        $ctx = clientWithTokenApiTest('notifikasi');
        $lain = sessionForApiTest();

        (new WaraRoute)->forceFill([
            'client_id' => $ctx['client']->getKey(),
            'purpose' => 'otp',
            'device_id' => $lain->device_id,
        ])->save();

        $response = $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], $ctx['header'])->assertStatus(422);

        expect($response->getContent())->toContain('Sebutkan purpose-nya');

        Http::assertNothingSent();
    });

    it('purpose eksplisit memilih route yang tepat', function () {
        $ctx = clientWithTokenApiTest('notifikasi');
        $lain = sessionForApiTest();

        (new WaraRoute)->forceFill([
            'client_id' => $ctx['client']->getKey(),
            'purpose' => 'otp',
            'device_id' => $lain->device_id,
        ])->save();

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
            'purpose' => 'otp',
        ], $ctx['header'])->assertOk()->assertJson(['purpose' => 'otp']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Device-Id', $lain->device_id));
    });

    it('route yang device-nya offline gagal dengan 422, bukan fallback diam-diam', function () {
        $ctx = clientWithTokenApiTest('notifikasi', 'api_dev_rusak', ['wara.send'], [
            'is_logged_in' => false,
            'is_connected' => false,
        ]);

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], $ctx['header'])->assertStatus(422);

        Http::assertNothingSent();
    });

    it('🔴 route dengan outbound dimatikan gagal keras', function () {
        $ctx = clientWithTokenApiTest();

        WaraRoute::query()
            ->where('client_id', $ctx['client']->getKey())
            ->update(['outbound_enabled' => false]);

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], $ctx['header'])->assertStatus(422);

        Http::assertNothingSent();
    });

    it('tanpa scope wara.send ditolak', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.read']);

        $this->postJson('/api/v1/wara/send', [
            'to' => '08123456789',
            'message' => 'halo',
        ], $ctx['header'])->assertStatus(403);

        Http::assertNothingSent();
    });

    it('validasi menolak nomor dan pesan kosong', function () {
        $ctx = clientWithTokenApiTest();

        $this->postJson('/api/v1/wara/send', ['message' => 'halo'], $ctx['header'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->postJson('/api/v1/wara/send', ['to' => '08123456789'], $ctx['header'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    });
});

describe('GET /api/v1/wara/routes', function () {
    it('hanya mengembalikan route milik client sendiri', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.read']);

        $clientLain = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client lain',
            'is_active' => true,
        ]);

        (new WaraRoute)->forceFill([
            'client_id' => $clientLain->getKey(),
            'purpose' => 'rahasia',
            'device_id' => sessionForApiTest()->device_id,
        ])->save();

        $response = $this->getJson('/api/v1/wara/routes', $ctx['header'])->assertOk();

        $purposes = array_column($response->json('routes'), 'purpose');

        expect($purposes)->toBe(['notifikasi'])
            ->and($purposes)->not->toContain('rahasia');
    });

    it('menampilkan device_ready supaya klien tahu route-nya belum kepakai', function () {
        $ctx = clientWithTokenApiTest('notifikasi', 'api_dev_kurang', ['wara.routes.read'], [
            'is_logged_in' => false,
        ]);

        $this->getJson('/api/v1/wara/routes', $ctx['header'])
            ->assertOk()
            ->assertJson(['routes' => [['device_ready' => false]]]);
    });

    it('tanpa scope wara.routes.read ditolak', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.send']);

        $this->getJson('/api/v1/wara/routes', $ctx['header'])->assertStatus(403);
    });
});

describe('PUT /api/v1/wara/routes', function () {
    it('membuat route untuk client sendiri', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.write']);
        $target = sessionForApiTest();

        $this->putJson('/api/v1/wara/routes', [
            'purpose' => 'laporan',
            'device_id' => $target->device_id,
        ], $ctx['header'])
            ->assertOk()
            ->assertJson(['purpose' => 'laporan', 'device_id' => $target->device_id]);

        expect(WaraRoute::query()->where('purpose', 'laporan')->exists())->toBeTrue();
    });

    it('🔴 mengabaikan client_id dari payload', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.write']);
        $clientLain = WaraClient::query()->create([
            'type' => WaraClient::TYPE_SERVICE,
            'name' => 'Client lain',
            'is_active' => true,
        ]);

        $this->putJson('/api/v1/wara/routes', [
            'purpose' => 'injeksi',
            'device_id' => sessionForApiTest()->device_id,
            'client_id' => $clientLain->getKey(),
        ], $ctx['header'])->assertOk();

        // Route harus milik client pemanggil, bukan yang dikirim di payload.
        expect(WaraRoute::query()->where('purpose', 'injeksi')->value('client_id'))
            ->toBe($ctx['client']->getKey())
            ->and(WaraRoute::query()->where('purpose', 'injeksi')->value('client_id'))
            ->not->toBe($clientLain->getKey());
    });

    it('device yang tidak terdaftar ditolak 422', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.write']);

        $response = $this->putJson('/api/v1/wara/routes', [
            'purpose' => 'laporan',
            'device_id' => 'device_hantu',
        ], $ctx['header'])->assertStatus(422);

        expect($response->getContent())->toContain('wara:sync-devices');
    });

    it('purpose ganda untuk client yang sama ditolak database', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.write']);

        $this->putJson('/api/v1/wara/routes', [
            'purpose' => 'notifikasi',
            'device_id' => sessionForApiTest()->device_id,
        ], $ctx['header'])->assertStatus(422);
    });

    it('tanpa scope wara.routes.write ditolak', function () {
        $ctx = clientWithTokenApiTest('notifikasi', null, ['wara.routes.read']);

        $this->putJson('/api/v1/wara/routes', [
            'purpose' => 'laporan',
            'device_id' => $ctx['session']->device_id,
        ], $ctx['header'])->assertStatus(403);
    });
});
