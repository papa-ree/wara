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
