<?php

namespace Bale\Wara\Contracts;

use Bale\Wara\Data\DeviceInfo;
use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\PairingCode;
use Bale\Wara\Data\QrLogin;
use Bale\Wara\Data\SendResult;
use Bale\Wara\Data\WebhookConfig;
use Bale\Wara\Exceptions\DeviceNotFoundException;
use Bale\Wara\Exceptions\DeviceNotReadyException;
use Bale\Wara\Exceptions\GatewayException;

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
    public function sendText(string $deviceId, string $phone, string $text): SendResult;

    /**
     * Kirim gambar dari URL.
     *
     * Dipakai template ber-header IMAGE (PRD §16.7). Caption membawa body
     * template yang sudah dirakit.
     */
    public function sendImage(string $deviceId, string $phone, string $imageUrl, ?string $caption = null): SendResult;

    /**
     * Kirim video dari URL (template ber-header VIDEO).
     */
    public function sendVideo(string $deviceId, string $phone, string $videoUrl, ?string $caption = null): SendResult;

    /**
     * Kirim dokumen dari URL (template ber-header DOCUMENT).
     */
    public function sendDocument(string $deviceId, string $phone, string $fileUrl, ?string $caption = null): SendResult;

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
