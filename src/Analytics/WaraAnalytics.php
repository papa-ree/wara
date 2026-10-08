<?php

namespace Bale\Wara\Analytics;

use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Metrik gateway & router (PRD §14).
 *
 * Kelas ini **hanya membaca**. Tidak ada cache, tidak ada tabel agregasi, tidak
 * ada penulisan. Alasannya dua:
 *
 * 1. `wara_logs` masih **0 baris** di lingkungan dev per 5 Okt 2026. Tabel
 *    rollup pra-komputasi yang belum pernah berisi hanya menambah permukaan
 *    yang harus dijaga — dan yang harus dibongkar nanti kalau bentuk
 *    kebutuhannya berubah.
 * 2. Angka yang di-cache mudah basi tanpa terlihat. Untuk satu admin yang
 *    membuka halaman sesekali, on-demand lebih jujur.
 *
 * 🔴 Atribusi hanya untuk arah KELUAR (PRD §14.3).
 *
 * `client_id` diisi oleh `WaraManager::recordOutgoing()` karena objek client ada
 * di tangannya saat menulis. Arah inbound tidak punya itu: webhook hanya tahu
 * `device_id`, dan `wara_routes` mengizinkan satu device melayani banyak client
 * (`UNIQUE (client_id, purpose)`). Jadi metrik per-client di sini menghitung
 * **pesan keluar saja**, dan halaman wajib menyatakan itu — kalau tidak, angka
 * nol untuk inbound akan terbaca sebagai "tidak ada trafik".
 */
class WaraAnalytics
{
    /**
     * Rentang waktu yang boleh dipilih di UI.
     *
     * Dipakai `WaraManager`? Tidak — ini murni batas UI supaya ada satu tempat
     * yang mendefinisikan rentang valid.
     *
     * @var array<int, int>
     */
    public const RANGES = [6, 12, 24, 72, 168];

    public const DEFAULT_RANGE_HOURS = 24;

    /**
     * Jumlah bucket grafik funnel.
     *
     * Dipisah dari `RANGES` karena 168 jam dalam 24 bucket = 7 jam/bucket,
     * sedangkan 6 jam dalam 24 bucket = 15 menit/bucket. Jumlah bucket tetap,
     * lebar bucket yang menyesuaikan.
     */
    public const FUNNEL_BUCKETS = 24;

    public function since(int $hours): Carbon
    {
        return now()->subHours($hours);
    }

    /**
     * Volume pesan per status dalam rentang waktu.
     *
     * Satu query, tanpa N+1. Dikembalikan sebagai map `status => jumlah`.
     *
     * @return array<string, int>
     */
    public function volumeByStatus(int $hours): array
    {
        $rows = WaraLog::query()
            ->where('created_at', '>=', $this->since($hours))
            ->whereNotNull('status')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $out = [];

        foreach ($rows as $status => $total) {
            $out[(string) $status] = (int) $total;
        }

        return $out;
    }

    /**
     * Funnel pengiriman per bucket waktu.
     *
     * Bucket TIDAK dihitung di SQL. SQL hanya memotong waktu menjadi slot
     * terkecil yang aman (menit atau jam, sebagai STRING waktu dinding), lalu
     * pengelompokan ke bucket lebar dilakukan di PHP.
     *
     * Dua versi sebelumnya dicoba, dan keduanya gagal dengan cara berbeda:
     * 1. `DATE_FORMAT`/`strftime` langsung ke label bucket. Gagal di SQLite
     *    dengan error sintaks, dan itu kelihatan seketika. Tapi:
     * 2. Epoch di SQL (`strftime('%s')` vs `UNIX_TIMESTAMP`). Lebih berbahaya:
     *    `strftime('%s')` memperlakukan `created_at` sebagai UTC, sementara
     *    `now()->timestamp` dihitung di `Asia/Jakarta` (`config/app.php:68`).
     *    Selisih 7 jam itu tidak melempar error. Ia hanya membuat setiap baris
     *    mendarat di bucket yang salah, dan karena kunci yang tidak cocok
     *    disaring, hasilnya "grafik selalu nol" yang terlihat meyakinkan. Di
     *    MySQL masalahnya kebalikan: `UNIX_TIMESTAMP` memakai timezone sesi,
     *    jadi bisa bergeser lagi begitu config koneksi berubah.
     *
     * String waktu dinding tidak punya timezone, jadi tidak ada yang bisa salah
     * mengonversinya. Satu-satunya konversi ke timestamp terjadi di PHP, satu
     * tempat, dengan satu timezone.
     *
     * @return array<int, array{at: Carbon, accepted: int, rejected: int, received: int, delivered: int, read: int}>
     */
    public function funnel(int $hours): array
    {
        $width = $this->bucketWidth($hours);
        $buckets = $this->emptyBuckets($width);

        $rows = WaraLog::query()
            ->where('created_at', '>=', $this->since($hours))
            ->whereIn('status', self::throughputStatuses())
            ->selectRaw($this->slotExpression($width).' as slot, status, COUNT(*) as total')
            ->groupBy('slot', 'status')
            ->get();

        $now = now();

        foreach ($rows as $row) {
            $index = $this->bucketIndexFor((string) $row->getAttribute('slot'), $width, $now);

            if ($index === null) {
                continue;
            }

            $bucket = $this->statusBucket((string) $row->getAttribute('status'));

            if ($bucket === null) {
                continue;
            }

            $buckets[$index][$bucket] += (int) $row->getAttribute('total');
        }

        return $buckets;
    }

    /**
     * Bucket ter-nol, dari lama ke baru.
     *
     * 🔴 Bucket terakhir dimulai dari `$now`, bukan dari slot paling awal.
     * Dengan begitu baris yang baru saja terjadi masuk ke bucket terakhir dan
     * titik ujung kanan grafik tidak selalu nol.
     *
     * @return array<int, array{at: Carbon, accepted: int, rejected: int, received: int, delivered: int, read: int}>
     */
    protected function emptyBuckets(int $widthInSeconds): array
    {
        $now = now();
        $buckets = [];

        // 🔴 Iterasi dari index 0 (lama) ke index terakhir (baru). Kalau
        // dibalik, urutan key array ikut terbalik juga — `$funnel[0]` jadi
        // bucket paling baru. Tidak ada error, grafik cuma tampil terbalik dan
        // tes "terurut dari lama ke baru" gagal dengan angka yang membingungkan.
        for ($index = 0; $index < self::FUNNEL_BUCKETS; $index++) {
            $buckets[$index] = [
                'at' => $now->copy()->subSeconds($widthInSeconds * (self::FUNNEL_BUCKETS - $index)),
                'accepted' => 0,
                'rejected' => 0,
                'received' => 0,
                'delivered' => 0,
                'read' => 0,
            ];
        }

        return $buckets;
    }

    /**
     * Index bucket untuk sebuah slot, atau `null` kalau di luar rentang.
     *
     * Bucket `0` adalah yang paling LAMA: `[now - 24*width, now - 23*width)`.
     * Bucket terakhir (`FUNNEL_BUCKETS - 1`) adalah `[now - width, now]`.
     */
    protected function bucketIndexFor(string $slot, int $widthInSeconds, Carbon $now): ?int
    {
        $slotTime = Carbon::parse($slot, $now->getTimezone());

        // Slot terpotong ke bawah (menit/jam), jadi `startOfMinute` membuat
        // posisi dalam bucket terukur dari slot, bukan dari detik yang
        // terpotong.
        $elapsed = $now->diffInSeconds($slotTime->copy()->startOfMinute(), absolute: false);

        // Slot di masa depan tidak mungkin untuk log yang sudah tercatat, tapi
        // skew jam pada mesin uji bisa membuatnya terjadi. Perlakukan sebagai
        // "baru saja".
        if ($elapsed <= 0) {
            $index = self::FUNNEL_BUCKETS - 1;
        } else {
            $index = (int) floor($elapsed / $widthInSeconds);
        }

        return $index >= 0 && $index < self::FUNNEL_BUCKETS ? $index : null;
    }

    /**
     * Lebar bucket dalam detik.
     *
     * 🔴 Minimal 60 detik. Untuk rentang terkecil di `RANGES` (6 jam) lebarnya
     * sudah 900 detik, jadi guard ini hanya relevan kalau ada yang menambahkan
     * rentang sangat pendek nanti — dan tanpa guard, pembulatan bisa membuat
     * lebar 0 yang membuat pembagian dengan nol.
     */
    protected function bucketWidth(int $hours): int
    {
        return max(60, (int) round(($hours * 3600) / self::FUNNEL_BUCKETS));
    }

    /**
     * Ekspresi SQL untuk slot, dijamin jalan di MySQL dan SQLite.
     *
     * 🔴 Hasilnya STRING waktu dinding, bukan epoch. Lihat catatan panjang di
     * `funnel()` untuk kenapa ini disengaja.
     */
    protected function slotExpression(int $widthInSeconds): string
    {
        $sqlFormat = $widthInSeconds < 3600 ? '%Y-%m-%d %H:%i' : '%Y-%m-%d %H:00';

        return $this->driver() === 'sqlite'
            ? sprintf("strftime('%s', created_at)", $sqlFormat)
            : sprintf("DATE_FORMAT(created_at, '%s')", $sqlFormat);
    }

    protected function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    /**
     * Status yang memang mengukur throughput pesan.
     *
     * 🔴 Daftar ini, bukan `match` di `statusBucket()`, yang menjadi penyaring.
     * Versi pertama memetakan status tak dikenal ke `accepted` lewat cabang
     * `default`, jadi `ignored` ikut terhitung sebagai pesan terkirim — persis
     * hal yang harus dihindari.
     *
     * @return array<int, string>
     */
    public static function throughputStatuses(): array
    {
        return ['accepted', 'rejected', 'received', 'delivered', 'read'];
    }

    /**
     * Status mana yang masuk bucket mana.
     *
     * 🔴 Status di luar `throughputStatuses()` dikembalikan sebagai `null` dan
     * DIBUANG pemanggil, bukan dipetakan ke `accepted`.
     */
    protected function statusBucket(string $status): ?string
    {
        return in_array($status, self::throughputStatuses(), true) ? $status : null;
    }

    /**
     * Pesan keluar per client.
     *
     * 🔴 HANYA `direction = 'out'`. Lihat catatan kelas.
     *
     * Client tanpa `client_id` sengaja dikembalikan dengan label "(tanpa client)"
     * dan TIDAK dibuang — pesan yang terkirim tanpa client (mis. lewat
     * `default_device_id`) tetap traffic nyata, dan menyembunyikannya membuat
     * total tidak cocok dengan `volumeByStatus()['accepted']`.
     *
     * @return Collection<int, array{client_id: string|null, name: string, accepted: int, rejected: int}>
     */
    public function outboundByClient(int $hours): Collection
    {
        $rows = WaraLog::query()
            ->where('direction', 'out')
            ->where('created_at', '>=', $this->since($hours))
            ->whereIn('status', ['accepted', 'rejected'])
            ->selectRaw('client_id, status, COUNT(*) as total')
            ->groupBy('client_id', 'status')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $clientId = $row->getAttribute('client_id');
            $key = $clientId === null ? '' : (string) $clientId;

            $totals[$key] ??= ['client_id' => $clientId, 'accepted' => 0, 'rejected' => 0];
            $bucket = $row->getAttribute('status') === 'rejected' ? 'rejected' : 'accepted';
            $totals[$key][$bucket] += (int) $row->getAttribute('total');
        }

        $names = WaraClient::query()
            ->whereIn('id', array_values(array_filter(array_keys($totals))))
            ->pluck('name', 'id');

        return collect($totals)
            ->map(function (array $row) use ($names): array {
                $id = $row['client_id'] === null ? null : (string) $row['client_id'];

                return [
                    'client_id' => $id,
                    'name' => $id === null ? __('(tanpa client)') : ($names[$id] ?? __('(client dihapus)')),
                    'accepted' => $row['accepted'],
                    'rejected' => $row['rejected'],
                ];
            })
            ->sortByDesc(fn (array $row) => $row['accepted'] + $row['rejected'])
            ->values();
    }

    /**
     * Pesan per device, masuk dan keluar dipisah.
     *
     * `@return Collection<int, array{device_id: string|null, display_name: string, inbound: int, outbound: int}>`
     */
    public function trafficByDevice(int $hours): Collection
    {
        $rows = WaraLog::query()
            ->where('created_at', '>=', $this->since($hours))
            ->selectRaw('device_id, direction, COUNT(*) as total')
            ->groupBy('device_id', 'direction')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $deviceId = $row->getAttribute('device_id');
            $key = $deviceId === null ? '' : (string) $deviceId;

            $totals[$key] ??= ['device_id' => $deviceId, 'inbound' => 0, 'outbound' => 0];
            $bucket = $row->getAttribute('direction') === 'in' ? 'inbound' : 'outbound';
            $totals[$key][$bucket] += (int) $row->getAttribute('total');
        }

        $names = WaraSession::query()
            ->whereIn('device_id', array_values(array_filter(array_keys($totals))))
            ->pluck('display_name', 'device_id');

        return collect($totals)
            ->map(function (array $row) use ($names): array {
                $id = $row['device_id'] === null ? null : (string) $row['device_id'];

                return [
                    'device_id' => $id,
                    'display_name' => $id === null
                        ? __('(tanpa device)')
                        : ($names[$id] ?? $id),
                    'inbound' => $row['inbound'],
                    'outbound' => $row['outbound'],
                ];
            })
            ->sortByDesc(fn (array $row) => $row['inbound'] + $row['outbound'])
            ->values();
    }

    /**
     * Pesan masuk per nomor pengirim (keputusan PRD §14.3, 8 Okt 2026).
     *
     * 🔴 TIDAK bisa GROUP BY di SQL: kolom `phone` ter-enkripsi lewat cast
     * `encrypted`, dan enkripsi itu non-deterministik — dua baris dengan nomor
     * yang sama punya ciphertext yang berbeda. Mengelompokkan di SQL
     * menghasilkan satu grup per baris (nomor yang sama terpisah-pisah). Maka
     * dekripsi + agregasi dilakukan di PHP dari dua kolom saja
     * (`phone`, `message_id`) — pola yang sama dengan `funnel()`, yang sudah
     * memuat semua baris dalam rentangnya.
     *
     * 🔴 HANYA `event = 'message'`. `recordAck()` menulis `event='message.ack'`
     * tanpa `phone`; kalau ikut dihitung, `message.ack` mencemari grup
     * "(tanpa nomor)" dengan hitungan yang tidak pernah menjadi pesan masuk
     * sungguhan.
     *
     * 🔴 Dedupe: GOWA mengulang webhook saat 5xx (PRD §12.4) dan
     * `recordIncoming()` (`WebhookController.php:187`) menulis satu baris per
     * kiriman ulang. Baris ber-`message_id` di-dedupe (hash map per nomor,
     * bukan `in_array` yang O(n) per pemanggilan); baris tanpa `message_id`
     * tidak bisa dibedakan dari pengulang, jadi dihitung mentah. Selisih
     * `raw - messages` ditampilkan sebagai "termasuk pengulangan" supaya angka
     * besar tidak terbaca sebagai volume.
     *
     * `client_id` tidak disentuh sama sekali — atribusi per client tetap
     * outbound-only (lihat catatan kelas).
     *
     * @return Collection<int, array{phone: string|null, label: string, messages: int, raw: int, replayed: int}>
     */
    public function inboundBySender(int $hours): Collection
    {
        $rows = WaraLog::query()
            ->incoming()
            ->where('event', 'message')
            ->where('created_at', '>=', $this->since($hours))
            ->select('phone', 'message_id')
            ->get();

        $totals = [];
        $seen = [];

        foreach ($rows as $row) {
            $phone = $row->getAttribute('phone');
            $key = $phone === null ? "\0" : $phone;

            $totals[$key] ??= ['phone' => $phone, 'messages' => 0, 'raw' => 0];
            $totals[$key]['raw']++;

            $messageId = $row->getAttribute('message_id');

            if ($messageId === null) {
                $totals[$key]['messages']++;
            } elseif (isset($seen[$key][$messageId])) {
                continue;
            } else {
                $seen[$key][$messageId] = true;
                $totals[$key]['messages']++;
            }
        }

        return collect($totals)
            ->map(fn (array $row) => [
                'phone' => $row['phone'],
                'label' => $row['phone'] === null ? __('(tanpa nomor)') : $row['phone'],
                'messages' => $row['messages'],
                'raw' => $row['raw'],
                'replayed' => $row['raw'] - $row['messages'],
            ])
            ->sortByDesc(fn (array $row) => $row['messages'])
            ->values();
    }

    /**
     * Reliability fan-out per endpoint (PRD §12.4).
     *
     * Membaca `wara_consumer_deliveries` — ledger-nya sudah punya `attempts`,
     * `status_code`, dan `last_error`; yang belum ada cuma agregasinya.
     *
     * 🔴 Kolom `consumer` bernama consumer, tapi isinya sudah UUID
     * `wara_webhook_endpoints.id` (lihat `DeliverToConsumer::$endpointId`).
     * Jadi labelnya ikut diambil dari tabel endpoint — menampilkan UUID mentah
     * ke admin tidak berguna sama sekali.
     *
     * 🔴 Satu baris ledger = satu event (`firstOrCreate` pada
     * `(consumer, delivery_id)`), BUKAN satu percobaan. Jadi menghitung baris
     * menghitung event, dan `attempts` di-sum terpisah untuk melihat berapa
     * kali endpoint itu harus dicoba. Status `sending`/`queued` dihitung
     * sebagai pending: job-nya masih hidup, jadi bukan kegagalan.
     *
     * @return Collection<int, array{endpoint_id: string|null, client_name: string|null, delivered: int, failed: int, pending: int, attempts: int, last_status_code: int|null, last_error: string|null}>
     */
    public function fanOutByEndpoint(int $hours): Collection
    {
        $rows = WaraConsumerDelivery::query()
            ->where('created_at', '>=', $this->since($hours))
            ->selectRaw('consumer, status, COUNT(*) as total, MAX(status_code) as last_status_code, MAX(last_error) as last_error, SUM(attempts) as attempts')
            ->groupBy('consumer', 'status')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $endpointId = (string) $row->getAttribute('consumer');

            $totals[$endpointId] ??= [
                'endpoint_id' => $endpointId,
                'client_name' => null,
                'delivered' => 0,
                'failed' => 0,
                'pending' => 0,
                'attempts' => 0,
                'last_status_code' => null,
                'last_error' => null,
            ];

            $status = (string) $row->getAttribute('status');

            // 🔴 `total` dari COUNT(*), BUKAN `++`. Satu group bisa berisi banyak
            // baris: dua delivery `sent` untuk endpoint yang sama adalah satu
            // group dengan total 2. Versi pertama memakai `++` sehingga
            // "2 terkirim" tampil sebagai "1".
            $total = (int) $row->getAttribute('total');

            match ($status) {
                'sent' => $totals[$endpointId]['delivered'] += $total,
                'failed' => $totals[$endpointId]['failed'] += $total,
                default => $totals[$endpointId]['pending'] += $total,
            };

            $totals[$endpointId]['attempts'] += (int) $row->getAttribute('attempts');

            if ($status === 'failed') {
                $totals[$endpointId]['last_status_code'] = $row->getAttribute('last_status_code') === null
                    ? null
                    : (int) $row->getAttribute('last_status_code');
                $totals[$endpointId]['last_error'] = $row->getAttribute('last_error');
            }
        }

        // 🔴 Endpoint dihapus AFTER pengiriman tetap muncul di log. Kalau
        // endpoint-nya hilang, tampilkan UUID-nya apa adanya — lebih baik daripada
        // baris yang terlihat "tidak ada", karena itu menyamar sebagai bug
        // agregasi.
        $endpoints = WaraWebhookEndpoint::query()
            ->with('client:id,name')
            ->whereIn('id', array_keys($totals))
            ->get();

        return collect($totals)
            ->map(function (array $row) use ($endpoints): array {
                $endpoint = $endpoints->firstWhere('id', $row['endpoint_id']);
                $client = $endpoint?->client;

                return [
                    ...$row,
                    'client_name' => $client?->name ?? __('(endpoint dihapus)'),
                ];
            })
            ->sortByDesc(fn (array $row) => $row['failed'] + $row['pending'])
            ->values();
    }

    /**
     * Sesi login yang menyentuh batas percobaan dalam rentang waktu.
     *
     * Memakai `scopeExhausted()` yang sudah ada. Basisnya dibaca dari
     * `maxSessionAttempts()` yang mengembalikan `null` kalau batasnya `<= 0` —
     * jadi kalau batas dimatikan, angka ini benar-benar nol, bukan "semua
     * sesi". Itu perbedaan penting untuk dibaca, jadi nilainya dikembalikan
     * bersama penandanya.
     *
     * @return array{exhausted: int, limit_disabled: bool}
     */
    public function exhaustedSessions(int $hours): array
    {
        return [
            'exhausted' => WaraLog::query()
                ->exhausted((int) $this->since($hours)->diffInMinutes())
                ->count(),
            'limit_disabled' => (new WaraLog)->maxSessionAttempts() === null,
        ];
    }

    /**
     * Tunda kirim yang tercatat, dalam milidetik.
     *
     * 🔴 `latency_ms` NULL = "tidak terukur", bukan 0. Rata-rata dihitung hanya
     * dari baris yang punya angka. Kalau null ikut dirata-ratakan, pesan yang
     * gagal akan menarik rata-rata ke bawah dan membuatnya terlihat lebih cepat
     * dari kenyataan — arah kesalahan yang menipu.
     *
     * @return array{count: int, average: int|null, max: int|null}
     */
    public function latency(int $hours): array
    {
        $row = WaraLog::query()
            ->where('direction', 'out')
            ->where('created_at', '>=', $this->since($hours))
            ->whereNotNull('latency_ms')
            ->selectRaw('COUNT(*) as total, AVG(latency_ms) as average, MAX(latency_ms) as max')
            ->first();

        $count = (int) ($row->total ?? 0);

        return [
            'count' => $count,
            'average' => $count === 0 ? null : (int) round((float) $row->average),
            'max' => $count === 0 ? null : (int) $row->max,
        ];
    }

    /**
     * Device yang perlu perhatian admin (PRD §8.3).
     *
     * `needsReattention()` sudah dipakai `wara:poll-status` dan banner Device,
     * jadi definisi "perlu perhatian" tidak didefinisikan ulang di sini.
     */
    public function devicesNeedingAttention(): Collection
    {
        $threshold = (int) config('wara.offline_alert_after_minutes', 10);

        return WaraSession::query()
            ->get()
            ->filter(fn (WaraSession $session) => $session->needsReattention($threshold))
            ->values();
    }

    /**
     * Ringkasan angka untuk kartu statistik.
     *
     * @return array<string, int|null>
     */
    public function summary(int $hours): array
    {
        $status = $this->volumeByStatus($hours);
        $latency = $this->latency($hours);
        $exhausted = $this->exhaustedSessions($hours);

        $outbound = ($status['accepted'] ?? 0) + ($status['rejected'] ?? 0);
        $rejected = $status['rejected'] ?? 0;

        return [
            'outbound' => $outbound,
            'inbound' => ($status['received'] ?? 0) + ($status['delivered'] ?? 0) + ($status['read'] ?? 0),
            'rejected' => $rejected,
            'rejected_percent' => $outbound === 0 ? null : (int) round(($rejected / $outbound) * 100),
            'latency_average' => $latency['average'],
            'exhausted' => $exhausted['exhausted'],
            'devices_needing_attention' => $this->devicesNeedingAttention()->count(),
        ];
    }
}
