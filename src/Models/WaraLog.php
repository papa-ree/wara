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
     * Batas percobaan yang berlaku saat ini, atau `null` kalau tidak ada batas.
     *
     * 🔴 `null` BUKAN berarti "batas 1". Ini berarti `WARA_MAX_ATTEMPTS_PER_SESSION`
     * disetel `<= 0`, dan itu diartikan sebagai **tidak ada batas** — persis
     * seperti yang dilakukan `SendPacer::sessionAttemptsRemaining()` yang
     * mengembalikan `PHP_INT_MAX` untuk nilai yang sama.
     *
     * Versi lama method ini memakai `max(1, ...)`, jadi `0` diartikan sebagai
     * "batas 1". Dua tempat itu tidak bisa benar bersamaan: gateway menganggap
     * tanpa batas, sementara badge log menampilkan "2/1". Klamp ini sekarang
     * dihapus supaya hanya ada satu interpretasi.
     */
    public function maxSessionAttempts(): ?int
    {
        $max = (int) config('wara.max_attempts_per_session', 3);

        return $max > 0 ? $max : null;
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
     * Tanpa batas configured, penyebutnya dihilangkan: "4" bukan "4/∞" dan
     * tentu bukan "4/1".
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

        $max = $this->maxSessionAttempts();

        return $max === null
            ? sprintf('%d', $this->attempt)
            : sprintf('%d/%d', $this->attempt, $max);
    }

    /**
     * Kondisi baris ini terhadap batas percobaan sesi.
     *
     * 🔴 `last` berarti "ini percobaan terakhir yang BOLEH dikirim". Percobaan
     * berikutnya sudah ditolak `WaraManager::guardSessionAttempts()`. Istilah
     * "habis" dipakai untuk kondisi setelah itu, yaitu ketika tidak ada lagi
     * percobaan yang tercatat karena semuanya ditolak sebelum sampai gateway —
     * jadi tidak akan pernah terlihat sebagai badge di baris log mana pun.
     *
     * 🔴 Guard `max === null` itu wajib, bukan cuma biar aman. Tanpa itu,
     * `attempt >= PHP_INT_MAX` selalu false, jadi setiap sesi terbaca `active`
     * selamanya tepat ketika batasnya sengaja dimatikan — dan badge amber di
     * baris Log, serta banner sesi habis di halaman Device, sama-sama hilang
     * tanpa penjelasan.
     */
    public function getSessionStateAttribute(): string
    {
        if ($this->session_key === null || $this->attempt === null) {
            return self::SESSION_NONE;
        }

        $max = $this->maxSessionAttempts();

        if ($max === null) {
            return self::SESSION_ACTIVE;
        }

        return $this->attempt >= $max
            ? self::SESSION_LAST
            : self::SESSION_ACTIVE;
    }

    /**
     * Baris yang menyentuh batas percobaan sesi dalam `sinceMinutes` terakhir.
     *
     * 🔴 Kalau batas configured `<= 0` (tanpa batas), scope ini selalu kosong —
     * dan itu benar, bukan bug. Tidak ada sesi yang "habis" kalau tidak ada
     * batasnya. Guard-nya mencegah query sia-sia ke `wara_logs`.
     */
    public function scopeExhausted(Builder $query, int $sinceMinutes): Builder
    {
        $max = $this->maxSessionAttempts();

        if ($max === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNotNull('session_key')
            ->whereNotNull('attempt')
            ->where('attempt', '>=', $max)
            ->where('created_at', '>=', now()->subMinutes($sinceMinutes));
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
