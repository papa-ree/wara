<?php

namespace Bale\Wara\Contracts;

use Bale\Wara\Data\DeviceList;
use Bale\Wara\Data\DeviceStatus;
use Bale\Wara\Data\SendResult;
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
