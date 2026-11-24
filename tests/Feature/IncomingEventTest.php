<?php

use Bale\Wara\Events\WaraIncomingMessage;
use Bale\Wara\Events\WaraMessageAck;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\WaraServiceProvider;
use Tests\Concerns\RefreshTestDatabaseSafely;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshTestDatabaseSafely::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.webhook_secret', 'rahasia-webhook');
    config()->set('wara.default_device_id', '');

    Http::preventStrayRequests();
    Http::fake(['gateway.test/*' => Http::response(['id' => 'x', 'status' => 'accepted'], 200)]);
});

function incomingBody(array $payload = [], string $event = 'message', string $deviceId = 'dev-1'): string
{
    return json_encode([
        'event' => $event,
        'device_id' => $deviceId,
        'payload' => array_merge([
            'id' => 'msg-1',
            'from' => '628123456789@s.whatsapp.net',
            'chat_id' => '628123456789@s.whatsapp.net',
            'body' => '123456',
            'timestamp' => '2026-09-30T10:00:00Z',
        ], $payload),
    ], JSON_THROW_ON_ERROR);
}

describe('event WaraIncomingMessage (TOPIK §7)', function () {
    it('dipancarkan untuk pesan masuk', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody();

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class);
    });

    it('TIDAK dipancarkan untuk message.ack', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody([], 'message.ack');

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertNotDispatched(WaraIncomingMessage::class);
    });

    it('memancarkan WaraMessageAck untuk message.ack', function () {
        Event::fake([WaraMessageAck::class]);

        $body = json_encode([
            'event' => 'message.ack',
            'device_id' => 'dev-1',
            'payload' => [
                'ids' => ['msg-1', 'msg-2'],
                'chat_id' => '628123456789@s.whatsapp.net',
                'receipt_type' => 'read',
            ],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraMessageAck::class, fn (WaraMessageAck $event) => $event->deviceId === 'dev-1'
            && $event->messageIds === ['msg-1', 'msg-2']
            && $event->receiptType === 'read'
            && $event->phone === '628123456789');
    });

    it('TIDAK dipancarkan untuk event lain', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody([], 'group.message');

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertNotDispatched(WaraIncomingMessage::class);
    });

    it('TIDAK dipancarkan ketika signature ditolak', function () {
        Event::fake([WaraIncomingMessage::class]);

        $this->postJson('/api/wara/webhook', json_decode(incomingBody(), true), [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertForbidden();

        Event::assertNotDispatched(WaraIncomingMessage::class);
    });

    it('TIDAK dipancarkan tanpa device_id', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = json_encode([
            'event' => 'message',
            'payload' => ['body' => '123456'],
        ], JSON_THROW_ON_ERROR);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertNotDispatched(WaraIncomingMessage::class);
    });

    it('phone di event adalah teks polos tanpa sufiks JID (TOPIK §7)', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody(['from' => '628123456789@s.whatsapp.net']);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->phone === '628123456789';
        });
    });

    it('body di event adalah teks polos, bukan ciphertext (TOPIK §7)', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody(['body' => 'kode-rahasia-123']);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->body === 'kode-rahasia-123';
        });
    });

    it('rawBody adalah JSON mentah byte-wise dari gateway', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody();

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) use ($body) {
            return $event->rawBody === $body;
        });
    });

    it('meneruskan deviceId, messageId, chatId, dan receivedAt', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody(['id' => 'msg-abc', 'chat_id' => '628123456789@s.whatsapp.net']);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->deviceId === 'dev-1'
                && $event->messageId === 'msg-abc'
                && $event->chatId === '628123456789@s.whatsapp.net'
                && $event->receivedAt === '2026-09-30T10:00:00Z';
        });
    });

    it('meneruskan kutipan balasan dan mengosongkannya untuk pesan biasa', function () {
        Event::fake([WaraIncomingMessage::class]);

        foreach ([
            [['id' => 'msg-2', 'replied_to_id' => 'msg-1', 'quoted_body' => 'halo'], 'msg-1', 'halo'],
            [['id' => 'msg-3'], null, null],
        ] as [$payload, $repliedToId, $quotedBody]) {
            $body = incomingBody($payload);

            $this->postJson('/api/wara/webhook', json_decode($body, true), [
                'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
            ])->assertOk();

            Event::assertDispatched(WaraIncomingMessage::class, fn (WaraIncomingMessage $event) => $event->messageId === $payload['id']
                && $event->repliedToId === $repliedToId
                && $event->quotedBody === $quotedBody);
        }
    });

    it('baris wara_logs tetap tersimpan walau listener melempar', function () {
        Event::listen(WaraIncomingMessage::class, function () {
            throw new RuntimeException('listener gagal');
        });

        $body = incomingBody();

        $response = $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ]);

        // 500 akan membuat GOWA mengirim ulang event yang sama.
        $response->assertOk();

        expect(WaraLog::query()->where('direction', 'in')->count())->toBe(1);
    });

    it('tidak membocorkan isi pesan ke respons webhook', function () {
        $body = incomingBody(['body' => 'kode-rahasia-123']);

        $response = $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ]);

        expect($response->getContent())->not->toContain('kode-rahasia-123');
    });
});

describe('bantu WaraIncomingMessage', function () {
    it('mengenali group chat dari sufiks @g.us', function () {
        $event = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', '628123', '120363@g.us', '123456', '{}', null
        );

        expect($event->isGroupChat())->toBeTrue()
            ->and($event->isAddressableOneToOne())->toBeFalse();
    });

    it('menganggap chat 1:1 bisa dicocokkan', function () {
        $event = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', '628123', '628123@s.whatsapp.net', '123456', '{}', null
        );

        expect($event->isGroupChat())->toBeFalse()
            ->and($event->isAddressableOneToOne())->toBeTrue();
    });

    it('menolak event tanpa pengirim atau tanpa isi', function () {
        $noPhone = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', null, '628123@s.whatsapp.net', '123456', '{}', null
        );
        $noBody = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', '628123', '628123@s.whatsapp.net', null, '{}', null
        );
        $blankBody = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', '628123', '628123@s.whatsapp.net', '   ', '{}', null
        );

        expect($noPhone->isAddressableOneToOne())->toBeFalse()
            ->and($noBody->isAddressableOneToOne())->toBeFalse()
            ->and($blankBody->isAddressableOneToOne())->toBeFalse();
    });
});

describe('media masuk', function () {
    it('menormalkan media path dari payload webhook', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody([
            'body' => null,
            'image' => 'statics/media/1752404751-foto.jpeg',
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->mediaType() === 'image'
                && $event->media['filename'] === '1752404751-foto.jpeg'
                && $event->isAddressableOneToOne();
        });
    });

    it('menormalkan media dengan caption dan nama dokumen', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody([
            'body' => 'Laporan bulanan',
            'document' => ['url' => 'https://mmg.whatsapp.net/x', 'filename' => 'laporan.pdf', 'caption' => 'Laporan bulanan'],
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->mediaType() === 'document'
                && $event->media['filename'] === 'laporan.pdf'
                && $event->media['caption'] === 'Laporan bulanan'
                && $event->body === 'Laporan bulanan';
        });
    });
});

describe('pesan dari device sendiri', function () {
    it('menandai is_from_me dan memakai chat_id sebagai lawan bicara', function () {
        Event::fake([WaraIncomingMessage::class]);

        $body = incomingBody([
            'from' => '628000000001:12@s.whatsapp.net',
            'chat_id' => '628123456789@s.whatsapp.net',
            'is_from_me' => true,
            'body' => 'dari hp',
        ]);

        $this->postJson('/api/wara/webhook', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'rahasia-webhook'),
        ])->assertOk();

        Event::assertDispatched(WaraIncomingMessage::class, function (WaraIncomingMessage $event) {
            return $event->isFromMe()
                && $event->chatPhone() === '628123456789'
                && $event->phone === '628000000001'
                && ! $event->isAddressableOneToOne();
        });
    });

    it('menolak chat yang bukan nomor telepon', function () {
        $group = new WaraIncomingMessage(
            'dev-1', 'message', 'm1', '628123', '120363012345678901@g.us', 'halo', '{}', null
        );
        $bot = new WaraIncomingMessage(
            'dev-1', 'message', 'm2', '628123', '1807055946647698@bot', 'halo', '{}', null
        );
        $lid = new WaraIncomingMessage(
            'dev-1', 'message', 'm3', '628123', '251556368777322@lid', 'halo', '{}', null
        );

        expect($group->chatPhone())->toBeNull()
            ->and($bot->chatPhone())->toBeNull()
            ->and($lid->chatPhone())->toBeNull();
    });
});
