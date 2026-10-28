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
     * Rentang waktu yang boleh dipilih di UI, dalam jam.
     *
     * Nilai ini sengaja berupa jumlah jam, bukan nama periode, karena
     * `WaraAnalytics` hanya tahu "sejak kapan data dibaca". Label periode
     * (Hari/Minggu/Bulan/Tahun) adalah urusan tampilan dan disediakan lewat
     * `rangeOptions()` supaya filter dan query tidak bisa menyimpang.
     *
     * @var array<int, int>
     */
    public const RANGES = [24, 168, 720, 8760];

    public const DEFAULT_RANGE_HOURS = 24;

    /**
     * Jumlah bucket grafik funnel untuk rentang harian.
     *
     * Rentang lain memakai jumlah bucket berbeda lewat `bucketCount()`:
     * minggu 28 bucket (6 jam), bulan 30 bucket (1 hari), tahun 26 bucket
     * (sekitar 2 minggu). Tujuannya supaya sumbu-x selalu terbaca — 365 hari
     * dalam 24 bucket berarti satu titik mewakili 15 hari dan tidak lagi
     * membantu.
     */
    public const FUNNEL_BUCKETS = 24;

    /**
     * Pilihan filter periode di header Analitik.
     *
     * @return array<int, string>
     */
    public static function rangeOptions(): array
    {
        return [
            24 => __('Hari'),
            168 => __('Minggu'),
            720 => __('Bulan'),
            8760 => __('Tahun'),
        ];
    }

    /**
     * Label periode untuk satu nilai `RANGES`.
     */
    public static function rangeLabel(int $hours): string
    {
        return self::rangeOptions()[$hours] ?? __('Hari');
    }

    /**
     * Keterangan rentang yang sedang aktif, ditampilkan di bawah judul filter.
     */
    public static function rangeDescription(int $hours): string
    {
        return match ($hours) {
            168 => __('7 hari terakhir'),
            720 => __('30 hari terakhir'),
            8760 => __('365 hari terakhir'),
            default => __('24 jam terakhir'),
        };
    }

    public function since(int $hours): Carbon
    {
        return now()->subHours($hours);
    }

    /**
     * Jumlah bucket untuk rentang tertentu.
     *
     * Hari memakai bucket per jam supaya perubahan jam 3 pagi tetap terlihat.
     * Minggu memakai 6 jam, bulan per hari, dan tahun sekitar dua minggu —
     * semakin panjang rentangnya, semakin lebar bucket yang masih terbaca.
     */
    public function bucketCount(int $hours): int
    {
        return match (true) {
            $hours <= 24 => self::FUNNEL_BUCKETS,
            $hours <= 168 => 28,
            $hours <= 720 => 30,
            default => 26,
        };
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
        $count = $this->bucketCount($hours);
        $width = $this->bucketWidth($hours, $count);
        $buckets = $this->emptyBuckets($width, $count);

        $rows = WaraLog::query()
            ->where('created_at', '>=', $this->since($hours))
            ->whereIn('status', self::throughputStatuses())
            ->selectRaw($this->slotExpression($width).' as slot, status, COUNT(*) as total')
            ->groupBy('slot', 'status')
            ->get();

        $now = now();

        foreach ($rows as $row) {
            $index = $this->bucketIndexFor((string) $row->getAttribute('slot'), $width, $count, $now);

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
    protected function emptyBuckets(int $widthInSeconds, int $count): array
    {
        $now = now();
        $buckets = [];

        // 🔴 Iterasi dari index 0 (lama) ke index terakhir (baru). Kalau
        // dibalik, urutan key array ikut terbalik juga — `$funnel[0]` jadi
        // bucket paling baru. Tidak ada error, grafik cuma tampil terbalik dan
        // tes "terurut dari lama ke baru" gagal dengan angka yang membingungkan.
        for ($index = 0; $index < $count; $index++) {
            $buckets[$index] = [
                'at' => $now->copy()->subSeconds($widthInSeconds * ($count - $index)),
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
     * Bucket `0` adalah yang paling LAMA: `[now - count*width, now - (count-1)*width)`.
     * Bucket terakhir (`count - 1`) adalah `[now - width, now]`.
     */
    protected function bucketIndexFor(string $slot, int $widthInSeconds, int $count, Carbon $now): ?int
    {
        $slotTime = Carbon::parse($slot, $now->getTimezone());

        // Slot terpotong ke bawah (menit/jam), jadi `startOfMinute` membuat
        // posisi dalam bucket terukur dari slot, bukan dari detik yang
        // terpotong.
        //
        // 🔴 Urutan argumen harus `slot -> now`, bukan `now -> slot`. Carbon
        // mengembalikan nilai NEGATIF untuk slot yang sudah lewat kalau
        // argumennya dibalik; versi lama lalu menganggap semua baris "baru
        // saja" dan menumpuk seluruh trafik di bucket terakhir. Tidak ada
        // error — grafiknya hanya selalu terlihat ramai di ujung kanan.
        $elapsed = $slotTime->diffInSeconds($now, absolute: false);

        // Slot di masa depan tidak mungkin untuk log yang sudah tercatat, tapi
        // skew jam pada mesin uji bisa membuatnya terjadi. Perlakukan sebagai
        // "baru saja".
        if ($elapsed <= 0) {
            $index = $count - 1;
        } else {
            $index = $count - 1 - (int) floor($elapsed / $widthInSeconds);
        }

        return $index >= 0 && $index < $count ? $index : null;
    }

    /**
     * Lebar bucket dalam detik.
     *
     * 🔴 Minimal 60 detik. Untuk rentang terkecil di `RANGES` (24 jam) lebarnya
     * sudah 3600 detik, jadi guard ini hanya relevan kalau ada yang menambahkan
     * rentang sangat pendek nanti — dan tanpa guard, pembulatan bisa membuat
     * lebar 0 yang membuat pembagian dengan nol.
     */
    protected function bucketWidth(int $hours, ?int $count = null): int
    {
        $count ??= $this->bucketCount($hours);

        return max(60, (int) round(($hours * 3600) / $count));
    }

    /**
     * Ekspresi SQL untuk slot, dijamin jalan di MySQL dan SQLite.
     *
     * 🔴 Hasilnya STRING waktu dinding, bukan epoch. Lihat catatan panjang di
     * `funnel()` untuk kenapa ini disengaja.
     */
    protected function slotExpression(int $widthInSeconds): string
    {
        // Bucket harian ke atas tidak perlu slot per jam: setahun penuh akan
        // menghasilkan ~8.760 grup padahal hanya 26 titik yang digambar. Slot
        // per hari memotong biaya query tanpa mengubah posisi bucket, karena
        // pengelompokan tetap dilakukan di PHP.
        $sqlFormat = match (true) {
            $widthInSeconds < 3600 => '%Y-%m-%d %H:%i',
            $widthInSeconds < 86400 => '%Y-%m-%d %H:00',
            default => '%Y-%m-%d 00:00',
        };

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

    // ──────────────────────────────────────────────────────────────────────────
    // WaraFilter-aware overloads
    //
    // Semua method berikut menerima WaraFilter sebagai argumen tunggal dan
    // menggantikan `$this->since($hours)` dengan `$filter->sinceCarbon()` dan
    // `$filter->untilCarbon()`. Filter device dan client diterapkan secara
    // opsional di WHERE clause — filter null berarti "tidak disaring".
    //
    // Konvensi penamaan: prefix `filtered` supaya tidak ambigu dengan method
    // lama. Method lama tetap ada untuk backward compat (dipanggil
    // AnalyticsSummary, AnalyticsFunnel, dsb. sebelum di-migrate).
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * `volumeByStatus()` dengan WaraFilter.
     *
     * @return array<string, int>
     */
    public function filteredVolumeByStatus(WaraFilter $filter): array
    {
        $q = WaraLog::query()
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon())
            ->whereNotNull('status');

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        if ($filter->clientId !== null) {
            $q->where('client_id', $filter->clientId);
        }

        $rows = $q->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $out = [];

        foreach ($rows as $status => $total) {
            $out[(string) $status] = (int) $total;
        }

        return $out;
    }

    /**
     * Funnel per bucket waktu dengan WaraFilter.
     *
     * 🔴 Untuk custom range, `effectiveHours()` dipakai untuk menentukan
     * lebar bucket. Bucket index dihitung relatif dari `untilCarbon()`, bukan
     * `now()` — supaya grafik custom range tidak tergeser ke kiri.
     *
     * @return array<int, array{at: Carbon, accepted: int, rejected: int, received: int, delivered: int, read: int}>
     */
    public function filteredFunnel(WaraFilter $filter): array
    {
        $effHours = $filter->effectiveHours();
        $count = $this->bucketCount($effHours);
        $width = $this->bucketWidth($effHours, $count);

        // Bangun bucket relatif terhadap $until, bukan now()
        $until = $filter->untilCarbon();
        $buckets = [];

        for ($i = 0; $i < $count; $i++) {
            $buckets[$i] = [
                'at' => $until->copy()->subSeconds($width * ($count - $i)),
                'accepted' => 0,
                'rejected' => 0,
                'received' => 0,
                'delivered' => 0,
                'read' => 0,
            ];
        }

        $q = WaraLog::query()
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $until)
            ->whereIn('status', self::throughputStatuses());

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        if ($filter->clientId !== null) {
            $q->where('client_id', $filter->clientId);
        }

        $rows = $q->selectRaw($this->slotExpression($width).' as slot, status, COUNT(*) as total')
            ->groupBy('slot', 'status')
            ->get();

        foreach ($rows as $row) {
            $index = $this->bucketIndexFor((string) $row->getAttribute('slot'), $width, $count, $until);

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
     * Pesan keluar per client dengan WaraFilter.
     *
     * 🔴 Filter `clientId` pada method ini tidak masuk akal (sudah di-group per
     * client), tapi filter `deviceId` tetap relevan: admin bisa melihat distribusi
     * client hanya untuk satu device tertentu.
     *
     * @return Collection<int, array{client_id: string|null, name: string, accepted: int, rejected: int}>
     */
    public function filteredOutboundByClient(WaraFilter $filter): Collection
    {
        $q = WaraLog::query()
            ->where('direction', 'out')
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon())
            ->whereIn('status', ['accepted', 'rejected']);

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        // clientId filter: tampilkan hanya satu client
        if ($filter->clientId !== null) {
            $q->where('client_id', $filter->clientId);
        }

        $rows = $q->selectRaw('client_id, status, COUNT(*) as total')
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
     * Trafik per device dengan WaraFilter.
     *
     * @return Collection<int, array{device_id: string|null, display_name: string, inbound: int, outbound: int}>
     */
    public function filteredTrafficByDevice(WaraFilter $filter): Collection
    {
        $q = WaraLog::query()
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon());

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        if ($filter->clientId !== null) {
            $q->where('client_id', $filter->clientId);
        }

        $rows = $q->selectRaw('device_id, direction, COUNT(*) as total')
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
     * Pesan masuk per pengirim dengan WaraFilter.
     *
     * 🔴 `inboundBySender` TIDAK bisa di-group di SQL karena `phone` ter-enkripsi
     * non-deterministik. Filter `deviceId` diterapkan di SQL (tidak terenkripsi);
     * filter `clientId` TIDAK berlaku di sini karena pesan masuk tidak punya
     * client_id (PRD §14.3).
     *
     * @return Collection<int, array{phone: string|null, label: string, messages: int, raw: int, replayed: int}>
     */
    public function filteredInboundBySender(WaraFilter $filter): Collection
    {
        $q = WaraLog::query()
            ->incoming()
            ->where('event', 'message')
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon());

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        $rows = $q->select('phone', 'message_id')->get();

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
     * Fan-out per endpoint dengan WaraFilter.
     *
     * `clientId` bisa dipakai di sini karena `wara_consumer_deliveries` punya
     * join ke endpoint → client. Tapi WaraConsumerDelivery tidak langsung
     * menyimpan client_id; filter dilakukan via subquery `whereHas`.
     *
     * @return Collection<int, array{endpoint_id: string|null, client_name: string|null, delivered: int, failed: int, pending: int, attempts: int, last_status_code: int|null, last_error: string|null}>
     */
    public function filteredFanOutByEndpoint(WaraFilter $filter): Collection
    {
        $q = WaraConsumerDelivery::query()
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon());

        if ($filter->clientId !== null) {
            // Filter via subquery: consumer IN (SELECT id FROM wara_webhook_endpoints WHERE client_id = ?)
            $clientId = $filter->clientId;
            $q->whereIn('consumer', function ($sub) use ($clientId) {
                $sub->select('id')
                    ->from('wara_webhook_endpoints')
                    ->where('client_id', $clientId);
            });
        }

        $rows = $q->selectRaw('consumer, status, COUNT(*) as total, MAX(status_code) as last_status_code, MAX(last_error) as last_error, SUM(attempts) as attempts')
            ->groupBy('consumer', 'status')
            ->get();

        // reuse logic yang sama dengan fanOutByEndpoint()
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
            $total = (int) $row->getAttribute('total');

            match ($status) {
                'sent' => $totals[$endpointId]['delivered'] += $total,
                'failed', 'exhausted' => $totals[$endpointId]['failed'] += $total,
                default => $totals[$endpointId]['pending'] += $total,
            };

            $totals[$endpointId]['attempts'] += (int) $row->getAttribute('attempts');

            $sc = $row->getAttribute('last_status_code');

            if ($sc !== null) {
                $totals[$endpointId]['last_status_code'] = (int) $sc;
            }

            $err = $row->getAttribute('last_error');

            if ($err !== null) {
                $totals[$endpointId]['last_error'] = (string) $err;
            }
        }

        $endpoints = WaraWebhookEndpoint::query()
            ->whereIn('id', array_keys($totals))
            ->with('client:id,name')
            ->get()
            ->keyBy('id');

        return collect($totals)
            ->map(function (array $row) use ($endpoints): array {
                $ep = $endpoints->get($row['endpoint_id']);

                return array_merge($row, [
                    'client_name' => $ep?->client?->name ?? __('(endpoint dihapus)'),
                ]);
            })
            ->sortByDesc(fn (array $row) => $row['delivered'] + $row['failed'] + $row['pending'])
            ->values();
    }

    /**
     * Latency dengan WaraFilter.
     *
     * @return array{count: int, average: int|null, max: int|null}
     */
    public function filteredLatency(WaraFilter $filter): array
    {
        $q = WaraLog::query()
            ->where('direction', 'out')
            ->where('created_at', '>=', $filter->sinceCarbon())
            ->where('created_at', '<=', $filter->untilCarbon())
            ->whereNotNull('latency_ms');

        if ($filter->deviceId !== null) {
            $q->where('device_id', $filter->deviceId);
        }

        if ($filter->clientId !== null) {
            $q->where('client_id', $filter->clientId);
        }

        $row = $q->selectRaw('COUNT(*) as total, AVG(latency_ms) as average, MAX(latency_ms) as max')->first();

        $count = (int) ($row->total ?? 0);

        return [
            'count' => $count,
            'average' => $count === 0 ? null : (int) round((float) $row->average),
            'max' => $count === 0 ? null : (int) $row->max,
        ];
    }

    /**
     * Ringkasan KPI dengan WaraFilter (menggantikan `summary(int $hours)`).
     *
     * @return array<string, int|null>
     */
    public function filteredSummary(WaraFilter $filter): array
    {
        $status = $this->filteredVolumeByStatus($filter);
        $latency = $this->filteredLatency($filter);
        $exhausted = $this->exhaustedSessions($filter->effectiveHours());

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
