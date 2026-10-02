<?php

namespace Bale\Wara\Support;

use Bale\Wara\Exceptions\WaraException;

/**
 * Resolusi + validasi konfigurasi consumer fan-out (PRD §12.2).
 *
 * PRD menyebut tiga aturan keras untuk consumer:
 *
 *  1. `enabled` default `false` di semua consumer. Konsumen baru tidak boleh
 *     mulai menerima traffic hanya karena package di-deploy.
 *  2. `url` wajib `https` di luar localhost. `http://` ke IP privat/loopback
 *     ditolak kecuali memang dikonfigurasi lokal — ini mengurangi risiko SSRF
 *     dari salah konfigurasi.
 *  3. `secret` minimal 32 karakter, dan kosong pada consumer `enabled=true`
 *     harus menggagalkan boot, bukan diam-diam mengirim tanpa tanda tangan.
 *
 * Validasi dijalankan di dua tempat: saat boot (supaya salah config ketahuan
 * di deploy) dan sekali lagi di dalam job (supaya job yang terlanjur tersimpan
 * di queue tidak bisa melewati guardrail setelah config berubah).
 */
final class ConsumerConfig
{
    /**
     * Panjang minimum secret. 32 karakter ≈ 256 bit bila diacak dengan benar.
     */
    public const MIN_SECRET_LENGTH = 32;

    /**
     * @return array<string, mixed>
     */
    public static function resolve(string $consumer): array
    {
        $config = config('wara.consumers', []);

        if (! is_array($config) || ! array_key_exists($consumer, $config)) {
            return [];
        }

        $resolved = $config[$consumer];

        return is_array($resolved) ? $resolved : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{enabled: bool, url: string, secret: string, events: array<int, string>, timeout: int, tries: int, backoff: int}
     */
    public static function normalize(array $config): array
    {
        $events = $config['events'] ?? [];
        $events = is_array($events) ? array_values(array_filter($events, 'is_string')) : [];

        return [
            'enabled' => ($config['enabled'] ?? false) === true,
            'url' => is_string($config['url'] ?? null) ? trim($config['url']) : '',
            'secret' => is_string($config['secret'] ?? null) ? (string) $config['secret'] : '',
            'events' => $events,
            'timeout' => max(1, (int) ($config['timeout'] ?? 5)),
            'tries' => max(1, (int) ($config['tries'] ?? 3)),
            'backoff' => max(1, (int) ($config['backoff'] ?? 30)),
        ];
    }

    /**
     * Consumer aktif hanya kalau `enabled` bernilai `true` yang eksplisit.
     *
     * Perhatikan: `!== true` (bukan `empty()`) supaya nilai `'false'` dari env
     * yang tidak dikonversi tidak ikut dianggap aktif.
     */
    public static function isEnabled(array $config): bool
    {
        return ($config['enabled'] ?? false) === true;
    }

    /**
     * Apakah consumer tertarik pada event ini?
     *
     * `events` kosong berarti terima semua event — dipakai kalau consumer
     * memang butuh semua.
     *
     * @param  array<int, string>  $events
     */
    public static function subscribesTo(array $events, ?string $event): bool
    {
        if ($events === []) {
            return true;
        }

        return $event !== null && in_array($event, $events, true);
    }

    /**
     * Tolak URL consumer yang melanggar aturan §12.2.
     *
     * Loopback/localhost dikecualikan supaya pengembangan lokal tetap bisa jalan
     * dengan `http://`; di luar itu `https` wajib.
     */
    public static function assertUrlAllowed(string $consumer, string $url): string
    {
        if ($url === '') {
            throw WaraException::misconfigured(
                sprintf('WARA_CONSUMER_%s_URL', strtoupper($consumer)),
                'diisi dengan URL endpoint consumer',
                '(kosong)'
            );
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw WaraException::misconfigured(
                sprintf('WARA_CONSUMER_%s_URL', strtoupper($consumer)),
                'berbentuk URL absolut, contoh: https://srikandi.ponorogo.go.id/webhook',
                $url
            );
        }

        if (self::isLocalHost($parts['host'])) {
            return $url;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw WaraException::misconfigured(
                sprintf('WARA_CONSUMER_%s_URL', strtoupper($consumer)),
                'berbentuk https:// (http:// hanya boleh untuk localhost/loopback agar tidak jadi SSRF)',
                $url
            );
        }

        return $url;
    }

    /**
     * Secret consumer `enabled=true` wajib ada dan cukup panjang.
     *
     * Ini yang mencegah "diam-diam mengirim tanpa tanda tangan": tanpa secret
     * valid, HMAC meaningless dan client tidak bisa membedakan event asli dari
     * pemalsuan.
     */
    public static function assertSecretUsable(string $consumer, string $secret): string
    {
        $variable = sprintf('WARA_CONSUMER_%s_SECRET', strtoupper($consumer));

        if ($secret === '') {
            throw WaraException::misconfigured(
                $variable,
                'diisi minimal 32 karakter untuk consumer yang enabled=true',
                '(kosong)'
            );
        }

        if (mb_strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw WaraException::misconfigured(
                $variable,
                sprintf('minimal %d karakter', self::MIN_SECRET_LENGTH),
                sprintf('%d karakter', mb_strlen($secret))
            );
        }

        return $secret;
    }

    /**
     * Host yang diperlakukan "lokal" sehingga `http://` masih diizinkan.
     */
    protected static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', '::1', '0.0.0.0'], true)) {
            return true;
        }

        // Alias yang sering dipakai untuk loopback.
        if (str_ends_with($host, '.localhost')) {
            return true;
        }

        // 127.0.0.0/8
        if (preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1) {
            return true;
        }

        return false;
    }
}
