<?php

namespace Bale\Wara\Models;

use Bale\Wara\Support\SensitiveData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Log pesan masuk dan keluar (PRD §7).
 *
 * @property string $id UUID primary key
 * @property string $direction `in` atau `out`
 * @property string|null $event
 * @property string|null $device_id
 * @property string|null $chat_id
 * @property string|null $phone
 * @property string|null $message_id
 * @property string|null $body
 * @property string|null $status
 * @property string|null $session_key
 * @property int|null $attempt
 */
class WaraLog extends Model
{
    use HasUuids;

    protected $table = 'wara_logs';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    protected $fillable = [
        'direction',
        'event',
        'device_id',
        'chat_id',
        'phone',
        'message_id',
        'body',
        'status',
        'session_key',
        'attempt',
    ];

    /**
     * `phone` dan `body` dienkripsi, sama seperti `wa_number` pada Aduan
     * (`bale/frasasti`) — nomor tujuan dan isi pesan adalah data pribadi.
     *
     * Konsekuensinya, keduanya TIDAK bisa dicari dengan `LIKE` dan tidak
     * bisa dipakai sebagai filter. Lihat migration
     * `update_wara_logs_encrypt_pii` untuk detailnya.
     */
    protected $casts = [
        'phone' => 'encrypted',
        'body' => 'encrypted',
    ];

    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', 'in');
    }

    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', 'out');
    }

    /**
     * Percobaan kirim pada satu sesi login — dipakai deteksi pola burst (§8.2).
     */
    public function scopeForSession(Builder $query, string $sessionKey): Builder
    {
        return $query->where('session_key', $sessionKey);
    }

    /*
     * -------------------------------------------------------------------------
     * Sesi percobaan (P1 docs/wara/TODO.md)
     * -------------------------------------------------------------------------
     *
     * `session_key` + `attempt` sudah tersimpan sejak PRD §8.2, tapi tidak pernah
     * sampai ke layar: `log-table.blade.php` tidak mencarinya dan `log-row.blade.php`
     * tidak merendernya. Akibatnya admin yang sedang menelusuri "kenapa OTP tidak
     * sampai" tidak punya cara melihat bahwa satu sesi login sudah menghabiskan
     * seluruh jatah percobaannya.
     *
     * Helper di bawah sengaja ada di model, bukan di view: "sesi ini sudah habis"
     * adalah keputusan yang melibatkan angka dari config. Kalau dihitung ulang di
     * Blade, tiap tampilan harus mengingat aturan yang sama, dan perubahan config
     * bisa membuat satu layar bilang "habis" sementara yang lain tidak.
     */

    /** Baris log yang tidak punya konsep sesi percobaan (pesan biasa). */
    public const SESSION_NONE = 'none';

    /** Baris log yang masih punya jatah percobaan. */
    public const SESSION_ACTIVE = 'active';

    /** Baris log pada percobaan terakhir yang diizinkan. */
    public const SESSION_LAST = 'last';

    /**
     * Batas percobaan yang berlaku saat ini.
     *
     * Dibaca dari config, bukan di-hardcode, supaya `WARA_MAX_ATTEMPTS_PER_SESSION`
     * yang diubah di environment benar-benar tercermin di layar.
     */
    public function maxSessionAttempts(): int
    {
        return max(1, (int) config('wara.max_attempts_per_session', 3));
    }

    /**
     * Percobaan ke-berapa dari batas sesi, mis. "2/3".
     *
     * 🔴 Dibuat sebagai accessor (`session_attempt_label`), bukan method biasa,
     * supaya konsisten dengan `masked_body` dan `status_label` yang sudah dipakai
     * di baris tabel. Kalau bentuknya berbeda, view akan menulis
     * `$record->sessionAttemptLabel()` di satu tempat dan
     * `$record->session_attempt_label` di tempat lain - dan yang salah akan
     * diam-diam merender kosong tanpa error.
     *
     * Null kalau baris ini bukan bagian dari percobaan sesi - baik karena tidak
     * ada `session_key`, atau karena `session_key` ada tapi `attempt` belum diisi
     * (baris lama sebelum kolomnya ditambah).
     */
    public function getSessionAttemptLabelAttribute(): ?string
    {
        if ($this->attempt === null) {
            return null;
        }

        return sprintf('%d/%d', $this->attempt, $this->maxSessionAttempts());
    }

    /**
     * Kondisi baris ini terhadap batas percobaan sesi.
     *
     * 🔴 `last` berarti "ini percobaan terakhir yang BOLEH dikirim". Percobaan
     * berikutnya sudah ditolak `WaraManager::guardSessionAttempts()`. Istilah
     * "habis" dipakai untuk kondisi setelah itu, yaitu ketika tidak ada lagi
     * percobaan yang tercatat karena semuanya ditolak sebelum sampai gateway —
     * jadi tidak akan pernah terlihat sebagai badge di baris log mana pun.
     */
    public function getSessionStateAttribute(): string
    {
        if ($this->session_key === null || $this->attempt === null) {
            return self::SESSION_NONE;
        }

        return $this->attempt >= $this->maxSessionAttempts()
            ? self::SESSION_LAST
            : self::SESSION_ACTIVE;
    }

    /**
     * Body pesan dengan kode OTP disamarkan.
     *
     * Nilai aslinya tetap utuh di database — penyamaran ini murni lapisan
     * tampilan, supaya admin yang membuka dashboard tidak membaca kode OTP
     * utuh sementara tetap bisa mengaudit bahwa pesannya terkirim.
     */
    public function getMaskedBodyAttribute(): ?string
    {
        return SensitiveData::maskOtp($this->body);
    }

    /**
     * Nomor tujuan/penirim yang lebih enak dibaca.
     *
     * Pesan keluar menyimpan nomor di `phone`; pesan masuk membawa JID di
     * `chat_id` dan nomor bersih di `from` yang sudah disimpan ke `phone`.
     */
    public function getContactAttribute(): ?string
    {
        return $this->phone;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'accepted' => __('Diterima gateway'),
            'rejected' => __('Ditolak gateway'),
            'received' => __('Masuk'),
            'delivered' => __('Sampai'),
            'read' => __('Dibaca'),
            'ignored' => __('Diabaikan'),
            default => $this->status ?? __('—'),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'accepted', 'received' => 'green',
            'delivered' => 'blue',
            'read' => 'indigo',
            'rejected' => 'red',
            default => 'gray',
        };
    }
}
