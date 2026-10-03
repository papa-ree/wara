<?php

namespace Bale\Wara\Models;

use Bale\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Client `bale/wara`: siapa yang berhak memakai gateway.
 *
 * 🔴 Inilah tabel yang sebelumnya tidak ada sama sekali. Tanpa pemilik yang
 * jelas, router tidak mungkin bekerja: `POST /send` tidak punya cara tahu
 * client mana yang memanggil, jadi tidak punya cara tahu device mana yang
 * harus dipakai.
 *
 * Client BUKAN tenant. Semua tabel `wara_*` ada di sisi LANDLORD (lihat
 * `src/menu.php`, `type => 'landlord'`) dan satu DB MySQL yang sama dengan
 * `bale/frasasti` dan `bale/bale-cms`. Yang dipetakan lewat `bale_id` adalah
 * organisasi penyewa, bukan pemisahan database.
 *
 * @property string $id
 * @property string|null $bale_id
 * @property string $type
 * @property string|null $api_token_id
 * @property string $name
 * @property bool $is_active
 */
class WaraClient extends Model
{
    use HasUuids, LogsActivity;

    /** Client yang identitasnya sebuah api token. */
    public const TYPE_TOKEN = 'token';

    /** Client internal bale, mis. notifikasi sistem. */
    public const TYPE_SERVICE = 'service';

    /** Client per organisasi tanpa token sendiri. */
    public const TYPE_TENANT = 'tenant';

    public const TYPES = [
        self::TYPE_TOKEN,
        self::TYPE_SERVICE,
        self::TYPE_TENANT,
    ];

    protected $table = 'wara_clients';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * * 🔴 `api_token_id` dan `bale_id` SENGAJA TIDAK mass-assignable dari request
     * HTTP. Keduanya menentukan client ini "siapa", dan itu hanya boleh lewat proses
     * enrolment, bukan lewat payload yang bisa dikirim siapa saja.
     */
    protected $fillable = [
        'type',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const LOGGED_ATTRIBUTES = [
        'type',
        'name',
        'is_active',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * @return HasMany<WaraRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(WaraRoute::class, 'client_id');
    }

    /**
     * @return HasMany<WaraWebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WaraWebhookEndpoint::class, 'client_id');
    }

    /**
     * Client `token` tanpa `api_token_id` adalah konfigurasi rusak yang tidak
     * akan ketahuan sampai request masuk dan tidak bisa dipetakan ke device.
     */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->type === self::TYPE_TOKEN) {
            return filled($this->api_token_id);
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    public function readinessConditions(): array
    {
        return [
            'is_active' => $this->is_active,
            'api_token_id' => $this->type === self::TYPE_TOKEN ? filled($this->api_token_id) : null,
        ];
    }
}
