<?php

namespace Bale\Wara\Models;

use Bale\Core\Traits\LogsActivity;
use Bale\Wara\Exceptions\WaraException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Endpoint webhook yang didaftarkan client.
 *
 * 🔴 Ini menggantikan `config('wara.consumers')`. URL, secret, dan daftar
 * event dulu hidup di config/env: tidak bisa diatur per CLIENT, dan tidak
 * bisa dibuat atau diubah dari UI admin.
 *
 * Keputusan arsitektur yang ditegakkan di sini: CLIENT yang mendaftarkan
 * path-nya, `bale/wara` yang push. Client tidak boleh menarik.
 *
 * @property string $id
 * @property string $client_id
 * @property string $url
 * @property string $secret
 * @property array<int, string>|null $events
 * @property bool $enabled
 */
class WaraWebhookEndpoint extends Model
{
    use HasUuids, LogsActivity;

    /** Panjang minimum secret. 32 karakter = 256 bit bila diacak dengan benar. */
    public const MIN_SECRET_LENGTH = 32;

    protected $table = 'wara_webhook_endpoints';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * 🔴 `secret` SENGAJA TIDAK ada di sini dan ada di `LOGGED_ATTRIBUTES`
     * sebagai hal yang dikecualikan. Kalau secret masuk `$fillable` DAN
     * masuk activity log, satu perubahan secret akan membocorkannya ke tabel
     * log yang dibaca lebih banyak orang daripada tabel utama.
     */
    protected $fillable = [
        'url',
        'events',
        'enabled',
    ];

    /**
     * `encrypted` cast - bukan `hashed`, bukan polos. Client harus bisa
     * menghitung HMAC dengan secret yang sama, dan tidak ada proses yang
     * perlu membandingkan secret secara teks.
     */
    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * 🔴 `secret` TIDAK PERNAH masuk sini. `url` juga tidak: URL webhook sering
     * memuat token atau path internal yang tidak perlu terekspos di log.
     */
    public const LOGGED_ATTRIBUTES = [
        'enabled',
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
     * Endpoint aktif hanya kalau `enabled === true` yang eksplisit.
     *
     * `!== true` (bukan `empty()`) supaya nilai `'false'` dari input yang tidak
     * dikonversi tidak ikut dianggap aktif.
     */
    public function isActive(): bool
    {
        return $this->enabled === true;
    }

    /**
     * Apakah endpoint tertarik pada event ini?
     *
     * `events` kosong atau null berarti terima semua event.
     *
     * @param  array<int, string>|null  $events
     */
    public static function subscribesTo(?array $events, ?string $event): bool
    {
        if ($events === null || $events === []) {
            return true;
        }

        return $event !== null && in_array($event, $events, true);
    }

    /**
     * Tolak URL endpoint yang tidak aman.
     *
     * 🔴 Dipindah dari `ConsumerConfig` (yang dihapus). Aturannya tidak berubah:
     * `http://` ke IP privat/loopback ditolak kecuali memang lokal. Itu mengurangi
     * risiko SSRF dari salah konfigurasi - endpoint diisi lewat UI admin, dan UI
     * admin bukan tempat yang layak ditanami URL bebas.
     */
    public function assertUrlAllowed(): string
    {
        $url = trim((string) $this->url);

        if ($url === '') {
            throw WaraException::misconfigured('URL webhook', 'diisi dengan URL endpoint client');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw WaraException::misconfigured(
                'URL webhook',
                'berbentuk URL absolut, contoh: https://contoh.go.id/webhook/wara'
            );
        }

        if (self::isLocalHost($parts['host'])) {
            return $url;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw WaraException::misconfigured(
                'URL webhook',
                'berbentuk https:// (http:// hanya boleh untuk localhost/loopback agar tidak jadi SSRF)'
            );
        }

        return $url;
    }

    /**
     * Secret wajib ada dan cukup panjang.
     *
     * Ini yang mencegah "diam-diam mengirim tanpa tanda tangan": tanpa secret
     * valid, HMAC tidak berarti apa-apa dan client tidak bisa membedakan event
     * asli dari pemalsuan.
     */
    public function assertSecretUsable(): string
    {
        $secret = (string) $this->secret;

        if ($secret === '') {
            throw WaraException::misconfigured(
                'Secret webhook',
                sprintf('diisi minimal %d karakter untuk endpoint yang enabled=true', self::MIN_SECRET_LENGTH)
            );
        }

        if (mb_strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw WaraException::misconfigured(
                'Secret webhook',
                sprintf('minimal %d karakter', self::MIN_SECRET_LENGTH)
            );
        }

        return $secret;
    }

    /**
     * Secret ada dan cukup panjang?
     *
     * Dipisah dari `assertSecretUsable()` supaya pengecekan ini bisa dipakai
     * sebagai kondisi tanpa melempar exception - misalnya untuk menyaring endpoint
     * yang layak jadi kandidat fan-out.
     */
    public function hasUsableSecret(): bool
    {
        return filled($this->secret) && mb_strlen((string) $this->secret) >= self::MIN_SECRET_LENGTH;
    }

    /**
     * Endpoint siap dipakai: aktif, URL aman, secret memadai.
     */
    public function isDeliverable(): bool
    {
        return $this->isActive() && $this->hasUsableSecret();
    }

    /**
     * Host yang diperlakukan "lokal" sehingga `http://` masih diizinkan.
     */
    protected static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', '::1', '0.0.0.0'], true)) {
            return true;
        }

        if (str_ends_with($host, '.localhost')) {
            return true;
        }

        return preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
    }
}
