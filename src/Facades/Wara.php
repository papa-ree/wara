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
