<?php

use Bale\Wara\Data\ChatMessage;
use Bale\Wara\Data\ChatSummary;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Gateways\GowaGateway;
use Bale\Wara\WaraServiceProvider;
use Tests\Concerns\RefreshTestDatabaseSafely;
use Illuminate\Support\Facades\Http;

uses(RefreshTestDatabaseSafely::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    Http::preventStrayRequests();
});

function gateway(): GowaGateway
{
    return new GowaGateway;
}

it('memetakan daftar chat dan menolak chat yang bukan nomor telepon', function () {
    Http::fake(['gateway.test/chats*' => Http::response([
        'code' => 'SUCCESS',
        'results' => [
            'data' => [
                ['jid' => '628123456789@s.whatsapp.net', 'name' => 'Budi', 'last_message_time' => '2026-11-19T10:00:00Z'],
                ['jid' => '628123456789:12@s.whatsapp.net', 'name' => 'Budi (device)', 'last_message_time' => null],
                ['jid' => '120363012345678901@g.us', 'name' => 'Grup RT'],
                ['jid' => '251556368777322@lid', 'name' => 'Tanpa nomor'],
            ],
            'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 4],
        ],
    ], 200)]);

    $chats = gateway()->chats('dev-a', 100, 0);

    expect($chats)->toHaveCount(4)
        ->and($chats[0])->toBeInstanceOf(ChatSummary::class)
        ->and($chats[0]->phone)->toBe('628123456789')
        ->and($chats[0]->lastMessageAt?->toIso8601String())->toBe('2026-11-19T10:00:00+00:00')
        ->and($chats[1]->phone)->toBe('628123456789')
        ->and($chats[2]->phone)->toBeNull()
        ->and($chats[3]->phone)->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/chats')
        && $request->hasHeader('X-Device-Id', 'dev-a')
        && $request['limit'] === 100);
});

it('memetakan pesan chat dan meneruskan filter waktu', function () {
    Http::fake(['gateway.test/chat/*/messages*' => Http::response([
        'code' => 'SUCCESS',
        'results' => [
            'data' => [
                ['id' => 'wamid-1', 'content' => 'halo', 'timestamp' => '2026-11-19T09:00:00Z', 'is_from_me' => false],
                ['id' => 'wamid-2', 'content' => '', 'timestamp' => '2026-11-19T09:05:00Z', 'is_from_me' => true, 'media_type' => 'image', 'filename' => 'foto.jpg'],
                ['id' => 'wamid-3', 'content' => null, 'timestamp' => '2026-11-19T09:06:00Z', 'is_from_me' => false, 'media_type' => 'call'],
            ],
            'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 3],
        ],
    ], 200)]);

    $since = now()->subDays(30);
    $messages = gateway()->chatMessages('dev-a', '628123456789', $since, 100, 200);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(ChatMessage::class)
        ->and($messages[0]->body)->toBe('halo')
        ->and($messages[0]->isFromMe)->toBeFalse()
        ->and($messages[0]->mediaType)->toBeNull()
        ->and($messages[1]->isFromMe)->toBeTrue()
        ->and($messages[1]->mediaType)->toBe('image')
        ->and($messages[1]->mediaName)->toBe('foto.jpg')
        ->and($messages[2]->mediaType)->toBe('call');

    Http::assertSent(function ($request) use ($since) {
        return str_contains($request->url(), '/chat/628123456789%40s.whatsapp.net/messages')
            && $request['offset'] === 200
            && $request['start_time'] === $since->toIso8601String();
    });
});

it('mengirim tanda dibaca dengan JID nomor kontak', function () {
    Http::fake(['gateway.test/message/*/read' => Http::response(['code' => 'SUCCESS'], 200)]);

    gateway()->markRead('dev-a', 'wamid-1', '628123456789');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/message/wamid-1/read')
        && $request['phone'] === '628123456789@s.whatsapp.net'
        && $request->hasHeader('X-Device-Id', 'dev-a'));
});

it('melempar exception domain saat gateway menolak', function () {
    Http::fake(['gateway.test/chats*' => Http::response(['code' => 'INTERNAL', 'message' => 'rusak'], 500)]);

    gateway()->chats('dev-a');
})->throws(GatewayException::class);
