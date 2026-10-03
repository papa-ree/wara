# bale/wara

Pembungkus gateway WhatsApp (GOWA) untuk ekosistem Bale. Package ini
menyediakan pengiriman dan penerimaan pesan WhatsApp, manajemen device
pengirim, pencatatan log pesan, kanal notifikasi Laravel, dan penerusan event
ke aplikasi lain.

Seluruh detail gateway (sufiks JID, basic-auth, header signature, bentuk
payload) terisolasi di dalam adapter. Package lain tidak perlu tahu
detail itu.

## Kebutuhan

| Dependency | Alasan |
|---|---|
| PHP `^8.3` | Kebutuhan runtime |
| `bale/core` | Auth, permission, komponen UI, layout |
| Laravel `^11 \|\| ^12 \|\| ^13` | Kompatibilitas lintas versi |

## Instalasi

Package ini adalah Composer *path repository* di dalam monorepo `bale-dev`,
bukan paket Packagist. Daftarkan dulu di `composer.json` aplikasi:

```json
"repositories": [
    { "type": "path", "url": "./packages/wara" }
],
"require": {
    "bale/wara": "dev-master"
}
```

Lalu pasang:

```bash
composer install
php artisan wara:install
```

Alternatif manual:

```bash
php artisan vendor:publish --tag="wara:migrations"
php artisan migrate
php artisan vendor:publish --tag="wara:config"
```

## Konfigurasi

`config/wara.php` di-merge di `register()`. Nilai yang dikosongkan tidak
dianggap error selama pengembangan.

| Env | Default | Fungsi |
|---|---|---|
| `WARA_ENDPOINT` | — | Base URL service gateway |
| `WARA_API_KEY` | — | Basic-auth gateway, format `user:pass` |
| `WARA_WEBHOOK_SECRET` | — | HMAC webhook; harus identik dengan sisi gateway |
| `WARA_DEFAULT_DEVICE_ID` | — | Device pengirim cadangan, format JID penuh |
| `WARA_TIMEOUT` | `20` | Timeout HTTP (detik) |
| `WARA_SYNC_DEVICES_EVERY` | `5` | Interval sinkronisasi katalog device (menit) |
| `WARA_POLL_STATUS_EVERY` | `2` | Interval pemeriksaan status device (menit) |
| `WARA_OFFLINE_ALERT_AFTER` | `10` | Berapa lama status buruk bertahan sebelum ditandai peringatan |
| `WARA_COOLDOWN_SECONDS` | `60` | Jarak minimum antar pengiriman ke nomor yang sama |
| `WARA_MAX_ATTEMPTS_PER_SESSION` | `3` | Batas percobaan kirim per sesi |
| `WARA_SESSION_WINDOW_SECONDS` | `900` | Panjang jendela penghitung percobaan |
| `WARA_DEVICE_BACKOFF_SECONDS` | `300` | Jeda sebelum mencoba ulang device yang tidak siap |
| `WARA_CONSUMER_SRIKANDI_ENABLED` | `false` | Kirim event ke aplikasi consumer |
| `WARA_CONSUMER_SRIKANDI_URL` | — | URL consumer (wajib `https` kecuali loopback/localhost) |
| `WARA_CONSUMER_SRIKANDI_SECRET` | — | HMAC fan-out, minimal 32 karakter |

`WARA_DEFAULT_DEVICE_ID` **wajib JID penuh** (`<nomor>@s.whatsapp.net`), bukan
nama device — nama device bisa diubah lewat dashboard sedangkan JID immutable.

## Command

| Command | Fungsi |
|---|---|
| `wara:install` | Seed permission dashboard |
| `wara:sync-devices` | Sinkronkan katalog device dari gateway. `--dry-run` tersedia |
| `wara:poll-status` | Periksa status device. `--device=<id>` dan `--json` tersedia |
| `wara:publish-migration` | Publish migration stub ke aplikasi |

Jadwal otomatis didaftarkan sendiri saat package boot, jadi worker Laravel
cukup menjalankan `php artisan schedule:work`.

## Testing

```bash
vendor\bin\pest packages\wara
```

## Credits

- [Papa Ree (Ricky R)](mailto:ricky.romdhoni@gmail.com) — Developer

## License

MIT.