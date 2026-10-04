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
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\DeviceRouter;
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
        protected DeviceRouter $router,
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
        ?WaraClient $client = null,
    ): SendResult {
        $normalized = $this->phone->toInternational($phone);

        if ($normalized === '') {
            throw WaraException::misconfigured('nomor tujuan', 'nomor telepon valid', $phone);
        }

        $session = $this->resolveDevice($purpose, $deviceId, $client);

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
        ?WaraClient $client = null,
    ): SendResult {
        return $this->sendMessage(
            $phone,
            $this->buildOtpMessage($otpCode),
            $purpose,
            $deviceId,
            $sessionKey,
            null,
            $client
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
     * 🔴 PEMANGGIL TANPA `session_key` TIDAK PERNAH DI-BATAS — INI KEPUTUSAN
     * SADAR, BUKAN KELALAIN (disepakati 4 Okt 2026).
     *
     * Baris `return` di bawah berarti Aturan §8.2 sama sekali tidak berlaku:
     * tidak ada penghitung yang bertambah, jadi `attempt` juga null dan tidak
     * ada baris log yang bisa dibaca untuk melihat polanya. Jalur ini tetap
     * punya cooldown per nomor dan backoff per device, jadi tidak tanpa
     * pengaman — hanya tanpa pengaman spesifik sesi.
     *
     * Konsekuensi yang harus disadari: client API yang dibuat dengan scope
     * kirim baru bisa mengaktifkan jalur tanpa batas percobaan. Itu sebabnya
     * setiap pemanggil eksternal WAJIB mengirim `session_key`. Yang dibolehkan
     * tanpa `session_key` hanya jalur internal yang memang tidak punya konsep
     * sesi, yaitu `WaraChannel`.
     *
     * Kalau nanti ini berubah jadi "wajib", jangan lupa mengecualikan
     * `WaraChannel` secara eksplisit — kalau tidak, semua notifikasi internal
     * ikut rusak.
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
     * Resolusi device pengirim.
     *
     * 🔴 URUTAN INI BERUBAH, dan itu disengaja.
     *
     * Schema lama menjawab "device mana untuk purpose ini?" secara global,
     * lalu jatuh ke `default_device_id`. Itu tidak bisa jadi router: begitu
     * ada dua client, keduanya akan berebut device yang sama, dan tidak ada
     * cara tahu client mana yang sebenarnya berhak.
     *
     * Sekarang urutannya:
     *   1. `$deviceId` eksplisit
     *   2. `$client` + `$purpose` lewat `wara_routes`
     *   3. jaring pengaman `default_device_id`
     *
     * Baris 2 TIDAK diam-diam jatuh ke baris 3 kalau client tidak punya
     * route-nya: itu justru kesalahan konfigurasi yang harus terlihat.
     * Jaring pengaman hanya dipakai kalau `$client` memang tidak diberikan
     * (jalur internal, mis. `WaraChannel`).
     *
     * Setiap hasil wajib lolos gerbang §6.5.2 tanpa fallback ke device lain.
     *
     * @param  WaraClient|null  $client  Client yang memanggil, atau null untuk
     *                                   jalur internal yang tidak punya client.
     */
    public function resolveDevice(
        ?string $purpose = null,
        ?string $deviceId = null,
        ?WaraClient $client = null,
    ): WaraSession {
        $context = $this->describeContext($purpose, $deviceId, $client);

        if (filled($deviceId)) {
            $session = WaraSession::query()->where('device_id', $deviceId)->first();

            if ($session === null) {
                throw DeviceNotFoundException::forSessionId($deviceId);
            }
        } elseif ($client !== null && filled($purpose)) {
            // 🔴 Gagal keras kalau route-nya tidak ada. Mengganti dengan
            // `default_device_id` di sini berarti pesan terkirim dari nomor yang
            // tidak Asked - dan penerima tidak akan pernah tahu.
            $session = $this->router->resolveSession($client, $purpose);
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
     *
     * 🔴 Client ikut disebut. Tanpa itu, dua client yang sama-sama memakai
     * purpose `notifikasi` akan menghasilkan pesan error yang identik, dan
     * admin tidak akan tahu route client mana yang bermasalah.
     */
    protected function describeContext(
        ?string $purpose,
        ?string $deviceId,
        ?WaraClient $client = null,
    ): string {
        if (filled($deviceId)) {
            return sprintf('device "%s"', $deviceId);
        }

        if ($client !== null && filled($purpose)) {
            return sprintf('client "%s" purpose "%s"', $client->name, $purpose);
        }

        if (filled($purpose)) {
            return sprintf('purpose "%s"', $purpose);
        }

        return 'default_device_id';
    }
}
