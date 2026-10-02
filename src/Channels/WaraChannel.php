<?php

namespace Bale\Wara\Channels;

use Bale\Wara\WaraManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kanal notifikasi WhatsApp untuk Laravel Notification (PRD §13).
 *
 * Pemakaian:
 *
 * ```php
 * Notification::route('wara', '628123456789')->notify(new AduanStatusBerubah($aduan));
 * ```
 *
 * Catatan implementasi: PRD §13.2 menyebut `implements Channel` dan
 * `channelFailed()`. Keduanya tidak ada di Laravel 12:
 *
 *  - tidak ada interface `Illuminate\Notifications\Channels\Channel` — kanal
 *    bawaan (`MailChannel`, `DatabaseChannel`) juga kelas biasa tanpa
 *    `implements`. Kontrak yang benar hanya punya
 *    `send($notifiable, $notification)`.
 *  - tidak ada method `Notification::channelFailed()`. Yang tersedia adalah
 *    event `Illuminate\Notifications\Events\NotificationFailed`.
 *
 * Kenapa kanal TIDAK boleh melempar: `NotificationSender::sendToNotifiable()`
 * menangkap exception lalu **melempar ulang** (lihat baris `throw $exception;`).
 * Kalau kanal melempar, `notify()` ikut melempar dan bisa membatalkan transaksi
 * database yang sedang berjalan. Jadi kegagalanInstead dilaporkan lewat
 * `NotificationFailed` yang di-dispatch manual, dan `send()` selalu kembali
 * normal.
 *
 * Batas lain yang dijaga kelas ini (PRD §13.3):
 *
 *  1. Tidak menormalisasi nomor — itu milik `WaraManager` (`phone_format`).
 *     Dua implementasi normalisasi = dua hasil berbeda.
 *  2. Tidak memanggil GOWA langsung — semua request wajib lewat `WaraManager`
 *     supaya device resolution (§6.5) dan pacing (§8.2) tetap berlaku.
 *  3. Tidak me-retry sendiri — itu milik antrean Laravel dan `SendPacer`.
 *  4. Tidak pernah menebak device pengirim — ditentukan §6.5.
 *  5. **Tidak melempar exception ke pemanggil.** Kegagalan WA adalah kondisi
 *     operasional, bukan exception program. Melempar dari `send()` membuat
 *     `Notification::send()` melempar balik dan bisa membatalkan transaksi
 *     database yang sedang berjalan. Kegagalan dilaporkan lewat event
 *     `NotificationFailed`.
 */
class WaraChannel
{
    public function __construct(protected WaraManager $wara) {}

    public function send(mixed $notifiable, Notification $notification): mixed
    {
        $phone = $this->resolvePhone($notifiable, $notification);

        if ($phone === null || $phone === '') {
            // Tidak ada tujuan. Ini kesalahan konfigurasi notifikasi, dan
            // dilaporkan lewat NotificationFailed agar tidak menggagalkan
            // pemanggil (poin 5).
            $this->fail($notifiable, $notification, 'Nomor tujuan WhatsApp tidak tersedia pada notifikasi.');

            return null;
        }

        $message = $this->resolveMessage($notifiable, $notification);

        if ($message === null || $message === '') {
            $this->fail($notifiable, $notification, 'Konten notifikasi tidak menghasilkan pesan teks.');

            return null;
        }

        $startedAt = microtime(true);

        try {
            $result = $this->wara->sendMessage($phone, $message, notification: $notification::class);
        } catch (Throwable $e) {
            // Device offline, cooldown, device tidak ada, atau gateway menolak.
            // Semua itu kondisi operasional — laporkan, jangan lempar.
            $this->fail($notifiable, $notification, $e->getMessage(), $e);

            return null;
        }

        if (! $result->accepted) {
            $this->fail(
                $notifiable,
                $notification,
                $result->errorMessage ?? 'Gateway menolak pesan.',
                null,
                ['error_code' => $result->errorCode],
            );

            return null;
        }

        // Catatan: event `WaraMessageSent` dipancarkan di dalam
        // `WaraManager::sendMessage()`, BUKAN di sini.
        //
        // Alasannya event itu harus ikut untuk pemanggilan internal juga
        // (mis. `Wara::sendOtp()` dari `bale/srikandi`) — field `notification`
        // dibuat nullable justru untuk menandai pemanggilan yang tidak
        // melewati channel. Kalau dipancarkan di sini, semua pengiriman
        // internal akan hilang dari korelasi.
        //
        // `$startedAt` di sini hanya dipakai untuk mengukur latensi kanal
        // pada log; event memakai pengukuran di `WaraManager`.
        Log::info('WaraChannel: notifikasi terkirim.', [
            'destination' => $phone,
            'message_id' => $result->messageId,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return null;
    }

    /**
     * Laporkan kegagalan tanpa melempar exception.
     *
     * Memakai event `NotificationFailed` — mekanisme resmi Laravel untuk itu.
     * Sengaja tidak melempar: `NotificationSender` akan melempar ulang, dan
     * itu bisa membatalkan transaksi database pemanggil.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function fail(
        mixed $notifiable,
        Notification $notification,
        string $reason,
        ?Throwable $exception = null,
        array $extra = [],
    ): void {
        Log::warning('WaraChannel: notifikasi gagal dikirim.', [
            'reason' => $reason,
            'notification' => $notification::class,
        ] + $extra);

        Event::dispatch(new NotificationFailed(
            $notifiable,
            $notification,
            'wara',
            array_filter([
                'reason' => $reason,
                'exception' => $exception,
            ], fn ($v) => $v !== null) + $extra,
        ));
    }

    /**
     * Nomor tujuan polos, tanpa sufiks JID.
     *
     * Diprioritaskan `toWara()` supaya package pemanggil punya kontrol penuh,
     * lalu baru `routeNotificationFor('wara')` sebagai konvensi bawaan Laravel.
     */
    protected function resolvePhone(mixed $notifiable, Notification $notification): ?string
    {
        if (method_exists($notifiable, 'toWara')) {
            $phone = $notifiable->toWara($notification);

            if (is_string($phone) && $phone !== '') {
                return $phone;
            }
        }

        if (method_exists($notifiable, 'routeNotificationFor')) {
            $phone = $notifiable->routeNotificationFor('wara', $notification);

            if (is_string($phone) && $phone !== '') {
                return $phone;
            }
        }

        if (is_string($notifiable) && $notifiable !== '') {
            return $notifiable;
        }

        return null;
    }

    /**
     * Isi pesan teks.
     *
     * Didukung dua cara: `toWaraMessage()` (kontrol penuh) atau
     * `toArray()['wara']` (konvensi umum Laravel untuk kanal non-teks).
     */
    protected function resolveMessage(mixed $notifiable, Notification $notification): ?string
    {
        if (method_exists($notifiable, 'toWaraMessage')) {
            $message = $notifiable->toWaraMessage($notification);

            if (is_string($message)) {
                return $message;
            }
        }

        if (method_exists($notification, 'toWara')) {
            $message = $notification->toWara($notifiable);

            if (is_string($message)) {
                return $message;
            }
        }

        $payload = $notification->toArray($notifiable);

        if (isset($payload['wara']) && is_string($payload['wara'])) {
            return $payload['wara'];
        }

        if (method_exists($notification, 'toArray')) {
            $text = $notification->toArray($notifiable);
            unset($text['wara']);

            $flat = $this->flatten($text);

            if ($flat !== '') {
                return $flat;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function flatten(array $data): string
    {
        $parts = [];

        array_walk_recursive($data, function ($value) use (&$parts) {
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        });

        return implode(' ', $parts);
    }
}
