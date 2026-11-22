<?php

namespace Bale\Wara\Contracts;

use Bale\Wara\Data\ChatMessage;
use Bale\Wara\Data\ChatSummary;
use Bale\Wara\Data\DeviceInfo;
use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\DownloadedMedia;
use Bale\Wara\Data\PairingCode;
use Bale\Wara\Data\QrLogin;
use Bale\Wara\Data\SendResult;
use Bale\Wara\Data\WebhookConfig;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\DeviceNotReadyException;
use Bale\Wara\Exceptions\GatewayException;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Batas yang memisahkan Bale dari gateway WhatsApp (PRD §11.2).
 *
 * Aturan yang tidak boleh dilanggar: nama field khas gateway, format JID
 * (`@s.whatsapp.net`), dan nama header signature TIDAK BOLEH muncul di kelas
 * selain adapter. Kalau satu saja bocor, batasnya jebol dan penggantian
 * gateway jadi mahal.
 *
 * Karena itu semua nilai di kontrak ini memakai model internal
 * (`Bale\Wara\Data\*`), bukan bentuk mentah gateway.
 */
interface WaraGateway
{
    /**
     * Kirim pesan teks dari device tertentu.
     *
     * @param  string  $deviceId  Session ID device di gateway (bukan JID).
     * @param  string  $phone  Nomor tujuan format internasional, mis. `62812...`.
     *
     * @throws DeviceNotReadyException
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function sendText(
        string $deviceId,
        string $phone,
        string $text,
        ?string $replyToMessageId = null,
    ): SendResult;

    /**
     * Kirim gambar dari URL.
     *
     * Dipakai template ber-header IMAGE (PRD §16.7). Caption membawa body
     * template yang sudah dirakit.
     */
    public function sendImage(
        string $deviceId,
        string $phone,
        string $imageUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult;

    /**
     * Kirim video dari URL (template ber-header VIDEO).
     */
    public function sendVideo(string $deviceId, string $phone, string $videoUrl, ?string $caption = null): SendResult;

    /**
     * Kirim dokumen dari URL (template ber-header DOCUMENT).
     */
    public function sendDocument(
        string $deviceId,
        string $phone,
        string $fileUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult;

    /**
     * Kirim audio dari URL. GOWA tidak punya field caption untuk audio.
     */
    public function sendAudio(
        string $deviceId,
        string $phone,
        string $audioUrl,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): SendResult;

    /**
     * Unduh isi media sebuah pesan masuk dari gateway.
     *
     * NULL bila pesan itu tidak punya media yang bisa diunduh.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function downloadMedia(string $deviceId, string $messageId, string $phone): ?DownloadedMedia;

    /**
     * Tandai pesan masuk sudah dibaca di WhatsApp (kirim read receipt).
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function markRead(string $deviceId, string $messageId, string $phone): void;

    /**
     * Tarik pesan yang sudah terkirim dari semua peserta ("hapus untuk semua").
     *
     * Hanya berlaku untuk pesan milik device ini dan dalam batas waktu yang
     * ditetapkan WhatsApp; di luar itu gateway membalas error.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function revokeMessage(string $deviceId, string $messageId, string $phone): void;

    /**
     * Hapus pesan hanya dari sisi akun ini ("hapus untuk saya").
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function deleteMessage(string $deviceId, string $messageId, string $phone): void;

    /**
     * Daftar percakapan yang tersimpan di chat storage gateway.
     *
     * @return Collection<int, ChatSummary>
     *
     * @throws GatewayException
     */
    public function chats(string $deviceId, int $limit = 100, int $offset = 0): Collection;

    /**
     * Pesan tersimpan pada satu percakapan, terbaru lebih dulu.
     *
     * `$since` membatasi umur pesan; NULL berarti semua yang ada di gateway.
     *
     * @return Collection<int, ChatMessage>
     *
     * @throws GatewayException
     */
    public function chatMessages(
        string $deviceId,
        string $phone,
        ?DateTimeInterface $since = null,
        int $limit = 100,
        int $offset = 0,
    ): Collection;

    /**
     * Apakah nilai ini adalah JID akun device, bukan nama device.
     *
     * 🔴 Ini satu-satunya cara yang sah untuk mengenali JID di luar adapter.
     * Bentuk JID adalah detail gateway (PRD §11.2), jadi manager dan
     * controller tidak boleh menyebut sufiksnya sendiri - mereka bertanya ke
     * gateway.
     *
     * Ini penting karena gateway mengisi `device_id` pada webhook pesan masuk
     * dengan JID, sementara kita memakai nama device sebagai identitas.
     */
    public function isDeviceJid(string $value): bool;

    /**
     * Daftar katalog device, sudah dinormalisasi ke model internal.
     */
    public function listDevices(): DeviceList;

    /**
     * Tambah slot device baru di gateway (PRD §15.2).
     *
     * @param  string|null  $sessionId  nama/ID slot yang diminta admin; `null`
     *                                  berarti biarkan gateway memberi nama.
     * @param  WebhookConfig|null  $webhook  konfigurasi webhook sejak pembuatan.
     *
     * @throws GatewayException
     */
    public function addDevice(?string $sessionId = null, ?WebhookConfig $webhook = null): DeviceInfo;

    /**
     * Minta QR pairing. Gambar sudah diunduh server-side dan dikembalikan
     * sebagai data-URI (keputusan #29) — bukan URL gateway.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function loginQr(string $sessionId): QrLogin;

    /**
     * Minta kode pairing 8 digit untuk dimasukkan di ponsel.
     *
     * @param  string  $phone  nomor perangkat, format internasional `62812...`.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function loginWithCode(string $sessionId, string $phone): PairingCode;

    /**
     * Hubungkan ulang device yang sesinya masih ada.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function reconnect(string $sessionId): void;

    /**
     * Logout device, tetapi slot tetap dipertahankan.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function logout(string $sessionId): void;

    /**
     * Hapus slot device sekaligus logout di gateway.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function removeDevice(string $sessionId): void;

    /**
     * Baca konfigurasi webhook milik device.
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function getWebhook(string $sessionId): WebhookConfig;

    /**
     * Tulis konfigurasi webhook milik device. `secret` kosong berarti
     * "jangan diubah", bukan "hapus".
     *
     * @throws DeviceNotFoundException
     * @throws GatewayException
     */
    public function setWebhook(string $sessionId, WebhookConfig $webhook): WebhookConfig;

    /**
     * Status satu device, sudah dinormalisasi ke enum internal.
     */
    public function deviceStatus(string $deviceId): DeviceStatus;

    /**
     * Verifikasi signature webhook dari raw body.
     *
     * @param  string  $rawBody  Body persis seperti diterima, tanpa re-encode.
     * @param  string  $signatureHeader  Nilai header signature dari gateway.
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool;
}
