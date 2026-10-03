<?php

namespace Bale\Wara\Models;

use Bale\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pemetaan `client + purpose -> device`.
 *
 * 🔴 Tabel ini menggantikan `wara_sessions.purpose` yang unik GLOBAL.
 *
 * Bedanya tidak sekadar teknis. Kolom lama berarti satu device hanya bisa
 * melayani satu purpose di seluruh sistem, jadi `notifikasi` dan `otp` tidak
 * bisa memakai device yang sama, dan menambah klien baru berarti mengubah
 * arti `purpose` untuk semua client sekaligus.
 *
 * Di sini unik-nya `(client_id, purpose)`. Dua client boleh sama-sama memakai
 * device yang sama untuk `notifikasi`, dan masing-masing punya keputusan
 * on/off-nya sendiri.
 *
 * @property string $id
 * @property string $client_id
 * @property string $purpose
 * @property string $device_id
 * @property bool $outbound_enabled
 * @property bool $inbound_webhook_enabled
 */
class WaraRoute extends Model
{
    use HasUuids, LogsActivity;

    protected $table = 'wara_routes';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    protected $fillable = [
        'purpose',
        'device_id',
        'outbound_enabled',
        'inbound_webhook_enabled',
    ];

    protected $casts = [
        'outbound_enabled' => 'boolean',
        'inbound_webhook_enabled' => 'boolean',
    ];

    /**
     * 🔴 `client_id` TIDAK mass-assignable. Route yang menunjuk client lain
     * adalah cara paling langsung untuk mengirim pesan memakai device yang
     * bukan haknya.
     */
    public const LOGGED_ATTRIBUTES = [
        'purpose',
        'device_id',
        'outbound_enabled',
        'inbound_webhook_enabled',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * @return BelongsTo<WaraClient, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(WaraClient::class, 'client_id');
    }

    /**
     * Device tujuan, atau `null` kalau device sudah tidak ada di gateway.
     *
     * Tidak otomatis pakai device lain kalau ini null - device offline harus
     * menggagalkan pengiriman, bukan digantikan.
     *
     * @return BelongsTo<WaraSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WaraSession::class, 'device_id', 'device_id');
    }

    public function allowsOutbound(): bool
    {
        return $this->outbound_enabled;
    }

    public function allowsInboundWebhook(): bool
    {
        return $this->inbound_webhook_enabled;
    }
}
