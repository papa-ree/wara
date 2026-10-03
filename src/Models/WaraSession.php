<?php

namespace Bale\Wara\Models;

use Bale\Core\Traits\LogsActivity;
use Bale\Wara\Data\DeviceState;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $device_id
 * @property string|null $jid
 * @property string|null $display_name
 * @property bool $is_present
 * @property bool $is_connected
 * @property bool $is_logged_in
 * @property Carbon|null $last_seen_at
 */
class WaraSession extends Model
{
    use HasUuids, LogsActivity;

    protected $table = 'wara_sessions';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 🔴 `purpose` TIDAK ADA lagi di tabel ini — kolomnya dihapus oleh migrasi
     * `create_wara_routes_table`.
     *
     * Penugasan device ke tujuan layanan pindah ke `wara_routes`, yang unik
     * PER CLIENT. Itu yang membuat dua client boleh memakai device yang sama
     * untuk `notifikasi`, dan yang membuat `wara_sessions.purpose` yang unik
     * global tidak lagi membatasi siapa boleh memakai device mana.
     */
    protected $fillable = [
        'device_id',
        'jid',
        'display_name',
        'is_present',
        'is_connected',
        'is_logged_in',
        'last_seen_at',
    ];

    protected $casts = [
        'is_present' => 'boolean',
        'is_connected' => 'boolean',
        'is_logged_in' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Atribut yang boleh masuk activity log.
     *
     * Hanya metadata operasional. `jid` tidak dicatat karena JID adalah nomor
     * telepon device — termasuk data yang tidak perlu terekspos di log.
     */
    public const LOGGED_ATTRIBUTES = [
        'device_id',
        'display_name',
        'is_present',
        'is_connected',
        'is_logged_in',
        'last_seen_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * Route yang memakai device ini.
     *
     * 🔴 Satu device bisa melayani BANYAK client. Ini kebalikan dari skema lama
     * di mana `purpose` di device berarti satu device hanya boleh punya satu
     * tujuan di seluruh sistem.
     *
     * @return HasMany<WaraRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(WaraRoute::class, 'device_id', 'device_id');
    }

    /**
     * Device yang boleh dipakai sebagai pengirim (PRD §6.5.2).
     *
     * Keempat syarat WAJIB terpenuhi. Tidak ada jalan pintas: device offline
     * harus menggagalkan pengiriman, bukan digantikan device lain.
     */
    public function isSendable(): bool
    {
        return $this->is_present
            && $this->is_connected
            && $this->is_logged_in
            && filled($this->jid);
    }

    /**
     * Syarat yang gagal, untuk pesan exception §6.5.2.
     *
     * @return array<string, bool|null>
     */
    public function readinessConditions(): array
    {
        return [
            'is_present' => $this->is_present,
            'is_connected' => $this->is_connected,
            'is_logged_in' => $this->is_logged_in,
            'jid' => filled($this->jid) ? true : null,
        ];
    }

    public function currentState(): DeviceState
    {
        return match (true) {
            $this->is_logged_in => DeviceState::LoggedIn,
            $this->is_connected => DeviceState::Connected,
            default => DeviceState::Disconnected,
        };
    }

    /**
     * Label status untuk ditampilkan di dashboard.
     */
    public function getStateLabelAttribute(): string
    {
        return match (true) {
            ! $this->is_present => __('Tidak ada di gateway'),
            $this->is_logged_in => __('Siap'),
            $this->is_connected => __('Terhubung, belum login'),
            default => __('Terputus'),
        };
    }

    public function getStateColorAttribute(): string
    {
        return match (true) {
            ! $this->is_present => 'gray',
            $this->is_logged_in => 'green',
            $this->is_connected => 'amber',
            default => 'red',
        };
    }

    /**
     * Device yang butuh perhatian admin (§8.3): status buruk yang bertahan lama.
     */
    public function needsReattention(?int $thresholdMinutes = null): bool
    {
        if ($this->isSendable()) {
            return false;
        }

        if (! $this->is_present) {
            // Tidak ada di gateway sama sekali — ditangani wara:sync-devices.
            return false;
        }

        $threshold = $thresholdMinutes
            ?? (int) config('wara.offline_alert_after_minutes', 10);

        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->lt(now()->subMinutes($threshold));
    }
}
