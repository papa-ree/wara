<?php

namespace Bale\Wara\Data;

/**
 * Konfigurasi webhook milik satu device (PRD §15.4/§15.6).
 *
 * 🔴 Hidup di gateway, BUKAN di database Bale — karena itu fase ini nol
 * migration. Yang disimpan di sini hanya nilai yang sedang dibaca/ditulis.
 *
 * `secret` tidak pernah dirender balik utuh ke browser: UI hanya menanyakan
 * "terisi atau belum" lewat {@see hasSecret()}, konsisten dengan
 * `wara_webhook_endpoints.secret` (§7.1a).
 */
final readonly class WebhookConfig
{
    /**
     * @param  list<string>  $events  nama event yang dikirim gateway
     */
    public function __construct(
        public ?string $url = null,
        public ?string $secret = null,
        public array $events = [],
        public bool $insecureSkipVerify = false,
    ) {}

    public function hasSecret(): bool
    {
        return filled($this->secret);
    }
}
