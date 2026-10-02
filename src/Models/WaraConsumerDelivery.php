<?php

namespace Bale\Wara\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Status pengiriman event ke consumer fan-out (PRD §7.1a & §12).
 */
class WaraConsumerDelivery extends Model
{
    use HasUuids;

    protected $table = 'wara_consumer_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'consumer',
        'delivery_id',
        'event',
        'device_id',
        'message_id',
        'status',
        'attempts',
        'status_code',
        'last_error',
        'delivered_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'status_code' => 'integer',
        'delivered_at' => 'datetime',
    ];
}
