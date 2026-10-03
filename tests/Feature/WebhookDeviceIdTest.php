<?php

/*
 * 🔴 Bug nyata: `wara_logs.device_id` berarti dua hal berbeda tergantung arah.
 *
 * Outbound -> kita yang memilih device, jadi nilainya nama device (`wago prod`).
 * Inbound  -> GOWA mengisi JID akun penerima (`6285126061182@s.whatsapp.net`).
 *
 * Akibatnya `wara_logs.device_id` tidak pernah sama dengan
 * `wara_sessions.device_id` untuk pesan masuk, sehingga query
 * "pesan masuk untuk device X" selalu kosong padahal device-nya jelas ada.
 *
 * Diperbaiki dengan memetakan JID balik ke nama device lewat `wara_sessions.jid`.
 */

namespace Bale\Wara\Tests\Feature;

use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.webhook_secret', 'rahasia-webhook');
    config()->set('wara.default_device_id', '');

    WaraSession::query()->create([
        'purpose' => 'notifikasi',
        'device_id' => 'wago prod',
        'jid' => '6285126061182@s.whatsapp.net',
        'status' => 'connected',
        'connected_at' => now(),
        'last_seen_at' => now(),
    ]);
});

function signBody(string $body, ?string $secret = 'rahasia-webhook'): string
{
    return 'sha256='.hash_hmac('sha256', $body, (string) $secret);
}

function kirim(array $payload): void
{
    $body = json_encode($payload);

    test()
        ->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => signBody($body),
        ])
        ->assertOk();
}

describe('normalisasi device_id pesan masuk', function () {
    it('memetakan JID inbound ke nama device', function () {
        kirim([
            'event' => 'message',
            'device_id' => '6285126061182@s.whatsapp.net',
            'payload' => [
                'chat_id' => '628111222333@s.whatsapp.net',
                'from' => '628111222333@s.whatsapp.net',
                'id' => 'msg-1',
                'body' => 'halo',
            ],
        ]);

        expect(WaraLog::query()->where('direction', 'in')->firstOrFail()->device_id)
            ->toBe('wago prod');
    });

    it('menyimpan NULL kalau JID tidak milik device mana pun', function () {
        kirim([
            'event' => 'message',
            'device_id' => '628999999999@s.whatsapp.net',
            'payload' => [
                'chat_id' => '628111222333@s.whatsapp.net',
                'id' => 'msg-2',
                'body' => 'halo',
            ],
        ]);

        // Bukan JID mentah: identitas palsu yang tidak akan pernah cocok
        // dengan wara_sessions.device_id.
        expect(WaraLog::query()->where('direction', 'in')->firstOrFail()->device_id)
            ->toBeNull();
    });

    it('membiarkan nama device apa adanya', function () {
        kirim([
            'event' => 'message',
            'device_id' => 'wago prod',
            'payload' => [
                'chat_id' => '628111222333@s.whatsapp.net',
                'id' => 'msg-3',
                'body' => 'halo',
            ],
        ]);

        expect(WaraLog::query()->where('direction', 'in')->firstOrFail()->device_id)
            ->toBe('wago prod');
    });

    it('tidak salah mengira nama device yang mengandung @ sebagai JID', function () {
        kirim([
            'event' => 'message',
            'device_id' => 'tim@cabang-1',
            'payload' => [
                'chat_id' => '628111222333@c.us',
                'id' => 'msg-4',
                'body' => 'halo',
            ],
        ]);

        expect(WaraLog::query()->where('direction', 'in')->firstOrFail()->device_id)
            ->toBe('tim@cabang-1');
    });

    it('device_id inbound sekarang bisa dicari dengan nama device', function () {
        kirim([
            'event' => 'message',
            'device_id' => '6285126061182@s.whatsapp.net',
            'payload' => [
                'chat_id' => '628111222333@s.whatsapp.net',
                'id' => 'msg-5',
                'body' => 'halo',
            ],
        ]);

        // Inilah query yang selalu kosong sebelum perbaikan.
        $found = WaraLog::query()
            ->where('direction', 'in')
            ->where('device_id', 'wago prod')
            ->count();

        expect($found)->toBe(1);
    });
});

/*
 * 🔴 Kontrol negatif. Kalau ini tidak benar, pemetaan di atas tidak
 * memperbaiki apa pun: JID memang tidak pernah cocok dengan nama device.
 */
describe('kontrol negatif', function () {
    it('JID mentah tidak akan cocok dengan nama device mana pun', function () {
        expect(WaraSession::query()
            ->where('device_id', '6285126061182@s.whatsapp.net')
            ->count())->toBe(0);
    });
});
