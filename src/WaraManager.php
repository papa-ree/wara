<?php

namespace Bale\Wara;

use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceState;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\SendResult;
use Bale\Wara\Events\WaraMessageSent;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\DeviceNotReadyException;
use Bale\Wara\Exceptions\GatewayException;
use Bale\Wara\Exceptions\TooManyAttemptsException;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\PhoneNumber;
use Bale\Wara\Support\SendPacer;
use Illuminate\Support\Facades\Log;

/**
 * Otak package: resolusi device pengirim dan pengiriman pesan.
 *
 * Kelas ini TIDAK boleh tahu detail gateway. Semua hal gateway-spesifik berhenti
 * di `WaraGateway` (PRD §11.2).
 */
class WaraManager
{
    public function __construct(
        protected WaraGateway $gateway,
        protected PhoneNumber $phone,
        protected SendPacer $pacer,
    ) {}

    /**
     * Kirim pesan teks biasa.
     *
     * @param  string|null  $purpose  Tujuan layanan, mis. `otp`. NULL atau
     *                                kosong memakai jaring pengaman
     *                                `default_device_id`.
     * @param  string|null  $deviceId  Escape-hatch: paksa device tertentu
     *                                 (Session ID). Hanya untuk tes/override.
     * @param  string|null  $sessionKey  Sesi login untuk batas percobaan
     *                                   (PRD §8.2). NULL melewati batas tersebut.
     * @param  string|null  $notification  Kelas notifikasi pemicu bila dipanggil
     *                                     lewat `WaraChannel`. NULL untuk
     *                                     pemanggilan internal — dipakai untuk
     *                                     menandai asal pada `WaraMessageSent`
     *                                     (PRD §13.4).
     */
    public function sendMessage(
        string $phone,
        string $message,
        ?string $purpose = null,
        ?string $deviceId = null,
        ?string $sessionKey = null,
        ?string $notification = null,
    ): SendResult {
        $normalized = $this->phone->toInternational($phone);

        if ($normalized === '') {
            throw WaraException::misconfigured('nomor tujuan', 'nomor telepon valid', $phone);
        }

        $session = $this->resolveDevice($purpose, $deviceId);

        $this->guardDeviceBackoff($session);
        $this->guardSessionAttempts($normalized, $sessionKey, $session);

        // Cooldown MENAHAN, bukan menolak (PRD §8.2): pemanggil adalah scraper
        // login yang tidak punya mekanisme backoff sendiri. Menolak dengan
        // exception hanya memindahkan masalah ke sana.
        $this->pacer->waitForPhoneCooldown($normalized);

        $attempt = $sessionKey !== null ? $this->pacer->recordAttempt($sessionKey) : null;

        $startedAt = microtime(true);

        try {
            $result = $this->gateway->sendText($session->device_id, $normalized, $message);
        } catch (GatewayException $e) {
            // Percobaan yang gagal tetap dicatat: pola restriksi harus terlihat
            // SEBELUM kejadian (PRD §10.2), bukan setelah.
            $this->markDeviceBackoff($session);

            $this->recordOutgoing($session, $normalized, $message, $sessionKey, $attempt, SendResult::rejected(
                'GATEWAY_ERROR',
                $e->getMessage()
            ));

            throw $e;
        }

        $this->pacer->recordSend($normalized, $attempt);
        $this->pacer->clearDeviceBackoff($session->device_id);

        $this->recordOutgoing($session, $normalized, $message, $sessionKey, $attempt, $result);

        // Event korelasi (PRD §13.4). Dipancarkan di sini, bukan di channel,
        // supaya pengiriman internal (mis. `sendOtp()` dari `bale/srikandi`)
        // ikut teramati.
        //
        // Isi pesan SENGAJA tidak disertakan: `bale/wara` tidak tahu APA yang
        // dikirim ke SIAPA.
        WaraMessageSent::dispatch(
            destination: $normalized,
            deviceId: $session->device_id,
            messageId: $result->messageId,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            notification: $notification,
        );

        return $result;
    }

    /**
     * Kirim OTP. Wrapper `sendMessage` dengan template standar.
     *
     * @param  string|null  $sessionKey  Sesi login untuk batas percobaan
     *                                   (PRD §8.2). Disarankan selalu diisi untuk
     *                                   OTP agar batasnya benar-benar berlaku.
     */
    public function sendOtp(
        string $phone,
        string $otpCode,
        ?string $purpose = null,
        ?string $deviceId = null,
        ?string $sessionKey = null,
    ): SendResult {
        return $this->sendMessage(
            $phone,
            $this->buildOtpMessage($otpCode),
            $purpose,
            $deviceId,
            $sessionKey
        );
    }

    /**
     * Backoff device: jangan mencoba ulang berkali-kali dalam waktu singkat
     * (PRD §8.2). Itu menghasilkan daftar device yang `disconnected`/`connecting`
     * terus, dan memboros request.
     */
    protected function guardDeviceBackoff(WaraSession $session): void
    {
        $remaining = $this->pacer->deviceBackoffRemaining($session->device_id);

        if ($remaining <= 0) {
            return;
        }

        throw WaraException::misconfigured(
            sprintf('device %s (masih dalam backoff %d detik)', $session->device_id, $remaining),
            sprintf('tunggu %d detik sebelum mencoba lagi', $remaining)
        );
    }

    protected function markDeviceBackoff(WaraSession $session): void
    {
        $this->pacer->markDeviceBackoff($session->device_id);
    }

    /**
     * Batas percobaan per sesi login (PRD §8.2).
     *
     * Dilewati kalau `$sessionKey` kosong — pemanggil yang tidak punya konsep
     * sesi tidak bisa dihitung dengan fair.
     */
    protected function guardSessionAttempts(string $phone, ?string $sessionKey, WaraSession $session): void
    {
        if ($sessionKey === null) {
            return;
        }

        $remaining = $this->pacer->sessionAttemptsRemaining($sessionKey);

        if ($remaining <= 0) {
            throw TooManyAttemptsException::forSession(
                $sessionKey,
                $this->pacer,
                $this->pacer->attemptsInSession($sessionKey)
            );
        }
    }

    /**
     * Resolusi device pengirim (PRD §6.5).
     *
     * Urutan prioritas TIDAK BOLEH diubah (§6.5.1):
     *   1. `$deviceId` eksplisit
     *   2. device bertanda `$purpose`
     *   3. `default_device_id` sebagai jaring pengaman
     *   4. `$purpose` null -> `default_device_id`
     *
     * Setiap hasil wajib lolos gerbang §6.5.2 tanpa fallback.
     */
    public function resolveDevice(?string $purpose = null, ?string $deviceId = null): WaraSession
    {
        $context = $this->describeContext($purpose, $deviceId);

        if (filled($deviceId)) {
            $session = WaraSession::query()->where('device_id', $deviceId)->first();

            if ($session === null) {
                throw DeviceNotFoundException::forSessionId($deviceId);
            }
        } elseif (filled($purpose)) {
            $session = WaraSession::query()->where('purpose', $purpose)->first();

            // Baris 3 §6.5.1: jatuh ke jaring pengaman, BUKAN gagal keras.
            $session ??= $this->resolveDefaultDevice($context);
        } else {
            $session = $this->resolveDefaultDevice($context);
        }

        $this->assertSendable($session, $context);

        return $session;
    }

    /**
     * Jaring pengaman: `config('wara.default_device_id')` yang berformat JID.
     */
    protected function resolveDefaultDevice(string $context): WaraSession
    {
        $jid = (string) config('wara.default_device_id');

        if ($jid === '') {
            throw WaraException::misconfigured(
                'WARA_DEFAULT_DEVICE_ID',
                'JID device pengirim default (lihat config/wara.php untuk formatnya)'
            );
        }

        $session = WaraSession::query()->where('jid', $jid)->first();

        if ($session === null) {
            throw DeviceNotReadyException::notFound(
                $context,
                null,
                $jid
            );
        }

        return $session;
    }

    /**
     * Gerbang §6.5.2 — gagal keras, tanpa fallback ke device lain.
     *
     * Melewati device yang dipilih demi "yang jelas jalan" justru menyembunyikan
     * masalah yang harus diperbaiki admin: nomor pengirim adalah bagian dari
     * yang dilihat operator di WhatsApp.
     */
    protected function assertSendable(WaraSession $session, string $context): void
    {
        if (! $session->isSendable()) {
            throw DeviceNotReadyException::forDevice(
                $context,
                $session->display_name,
                $session->jid,
                $session->readinessConditions()
            );
        }
    }

    /**
     * Status device (§8.3) — dipanggil scheduler, bukan hanya saat sync.
     */
    public function pollDeviceStatus(WaraSession $session): DeviceStatus
    {
        $status = $this->gateway->deviceStatus($session->device_id);

        // Status endpoint tidak selalu menyertakan `jid`, jadi kelayakan di sini
        // hanya dibaca dari state gateway. JID yang sudah tersimpan tidak
        // ditimpa oleh nilai kosong dari endpoint status.
        $session->forceFill([
            'is_present' => true,
            'is_connected' => $status->state !== DeviceState::Disconnected,
            'is_logged_in' => $status->state->isUsable(),
            'last_seen_at' => now(),
        ])->save();

        return $status;
    }

    /**
     * Katalog device dari gateway (PRD §7.1).
     */
    public function listDevices(): DeviceList
    {
        return $this->gateway->listDevices();
    }

    public function gateway(): WaraGateway
    {
        return $this->gateway;
    }

    /**
     * Catat pesan keluar. Kegagalan pencatatan tidak boleh menggagalkan
     * pengiriman — yang penting pesannya terkirim.
     *
     * `session_key` dan `attempt` diisi supaya pola percobaan pada satu sesi
     * login bisa dibaca dari `wara_logs` (PRD §10.2). Tanpa itu, pola restriksi
     * baru terlihat setelah kejadian — dan saat itu nomor sudah bermasalah.
     */
    protected function recordOutgoing(
        WaraSession $session,
        string $phone,
        string $body,
        ?string $sessionKey,
        ?int $attempt,
        SendResult $result,
    ): void {
        try {
            WaraLog::query()->create([
                'direction' => 'out',
                'event' => 'message',
                'device_id' => $session->device_id,
                'phone' => $phone,
                'message_id' => $result->messageId,
                'body' => $body,
                'status' => $result->accepted ? 'accepted' : 'rejected',
                'session_key' => $sessionKey,
                'attempt' => $attempt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal mencatat pesan keluar Wara ke wara_logs', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function buildOtpMessage(string $otpCode): string
    {
        return sprintf("Kode OTP Anda: *%s*\n\nMasukkan kode ini untuk melanjutkan.", $otpCode);
    }

    /**
     * Label konteks untuk pesan exception — wajib menyebut tujuan/device yang
     * bermasalah agar admin tahu harus memperbaiki apa (§6.5.2).
     */
    protected function describeContext(?string $purpose, ?string $deviceId): string
    {
        if (filled($deviceId)) {
            return sprintf('device "%s"', $deviceId);
        }

        if (filled($purpose)) {
            return sprintf('purpose "%s"', $purpose);
        }

        return 'default_device_id';
    }
}
