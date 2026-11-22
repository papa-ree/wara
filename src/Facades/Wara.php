<?php

namespace Bale\Wara\Facades;

use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\SendResult;
use Bale\Wara\WaraManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static SendResult sendMessage(string $phone, string $message, ?string $purpose = null, ?string $deviceId = null)
 * @method static SendResult sendOtp(string $phone, string $otpCode, ?string $purpose = null, ?string $deviceId = null)
 * @method static SendResult sendMedia(string $type, string $phone, string $mediaUrl, ?string $caption = null, ?string $deviceId = null, ?string $sessionKey = null, ?\Bale\Wara\Models\WaraClient $client = null)
 * @method static \Bale\Wara\Data\DownloadedMedia|null downloadMedia(string $deviceId, string $messageId, string $phone)
 * @method static void markRead(string $deviceId, string $messageId, string $phone)
 * @method static \Illuminate\Support\Collection<int, \Bale\Wara\Data\ChatSummary> chats(string $deviceId, int $limit = 100, int $offset = 0)
 * @method static \Illuminate\Support\Collection<int, \Bale\Wara\Data\ChatMessage> chatMessages(string $deviceId, string $phone, ?\DateTimeInterface $since = null, int $limit = 100, int $offset = 0)
 * @method static DeviceList listDevices()
 * @method static DeviceStatus pollDeviceStatus(\Bale\Wara\Models\WaraSession $session)
 *
 * @see WaraManager
 */
class Wara extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WaraManager::class;
    }
}
