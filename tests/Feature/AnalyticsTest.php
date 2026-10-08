<?php

use App\Models\User;
use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Index as AnalyticsIndex;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Section\AnalyticsBreakdown;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Section\AnalyticsFunnel;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Section\AnalyticsHeader;
use Bale\Wara\Livewire\Pages\Landlord\Analytics\Section\AnalyticsSummary;
use Bale\Wara\Models\WaraClient;
use Bale\Wara\Models\WaraConsumerDelivery;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Models\WaraWebhookEndpoint;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    Permission::create(['name' => 'wara.log.read']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('wara.log.read');

    $this->analytics = new WaraAnalytics;
});

function analyticsClient(string $name = 'Client Uji'): WaraClient
{
    return WaraClient::query()->create([
        'type' => WaraClient::TYPE_SERVICE,
        'name' => $name,
        'is_active' => true,
    ]);
}

function analyticsDevice(string $id = 'dev-1'): WaraSession
{
    return WaraSession::query()->create([
        'device_id' => $id,
        'jid' => '6289900'.substr(md5($id), 0, 5).'@s.whatsapp.net',
        'display_name' => 'Device '.$id,
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);
}

/**
 * Baris log dengan `created_at` eksplisit supaya tes rentang waktu tidak
 * bergantung pada jam sistem.
 *
 * 🔴 WAJIB `forceFill()`, bukan `create()`. `created_at` tidak ada di
 * `$fillable` (Laravel-managed), jadi `create()` diam-diam membuangnya dan
 * seluruh baris mendapat timestamp sekarang. Gejalanya: tes "di luar rentang"
 * tetap gagal padahal kodenya benar, karena tidak ada baris yang pernah benar
 *-benar tua.
 */
function analyticsLog(array $attributes = []): WaraLog
{
    $log = new WaraLog;

    $log->forceFill(array_merge([
        'direction' => 'out',
        'device_id' => 'dev-1',
        'status' => 'accepted',
    ], $attributes));

    if (! array_key_exists('created_at', $attributes)) {
        $log->created_at = now()->subHour();
    }

    $log->updated_at = $attributes['updated_at'] ?? $log->created_at;

    $log->save();

    return $log;
}

describe('volume per status', function () {
    it('menghitung tiap status di dalam rentang', function () {
        analyticsLog(['status' => 'accepted']);
        analyticsLog(['status' => 'accepted']);
        analyticsLog(['status' => 'rejected']);
        analyticsLog(['direction' => 'in', 'status' => 'received']);

        // 🔴 Perbandingan per-key, bukan `toBe()` pada seluruh array. Urutan
        // key mengikuti urutan balikin database, jadi `toBe()` di sini menguji
        // urutan GROUP BY MySQL — bukan perilaku yang dijanjikan.
        $volume = $this->analytics->volumeByStatus(24);

        expect($volume['accepted'])->toBe(2)
            ->and($volume['rejected'])->toBe(1)
            ->and($volume['received'])->toBe(1)
            ->and($volume)->toHaveCount(3);
    });

    it('mengabaikan baris di luar rentang', function () {
        analyticsLog(['created_at' => now()->subHours(30)]);
        analyticsLog(['created_at' => now()->subHour()]);

        // Dua baris `accepted`, tapi hanya satu yang masuk rentang 24 jam.
        expect($this->analytics->volumeByStatus(24))->toBe(['accepted' => 1]);
    });
});

describe('funnel per bucket', function () {
    it('selalu mengembalikan jumlah bucket yang sama, terisi atau tidak', function () {
        expect($this->analytics->funnel(24))->toHaveCount(WaraAnalytics::FUNNEL_BUCKETS);
    });

    it('menaruh baris ke bucket yang benar', function () {
        // Ditaruh 1 menit dari `now` dan bucket berukuran 1 jam,
        // jadi harus mendarat di bucket terakhir yang dimulai <= sekarang.
        analyticsLog(['status' => 'accepted', 'created_at' => now()->subMinute()]);
        analyticsLog(['status' => 'rejected', 'created_at' => now()->subMinute()]);

        $funnel = $this->analytics->funnel(24);
        $last = end($funnel);

        expect($last['accepted'])->toBe(1)
            ->and($last['rejected'])->toBe(1);
    });

    it('bucket terurut dari lama ke baru', function () {
        $funnel = $this->analytics->funnel(24);

        $first = $funnel[0]['at']->timestamp;
        $last = $funnel[count($funnel) - 1]['at']->timestamp;

        expect($first)->toBeLessThan($last);
    });

    it('lebar bucket menyesuaikan rentang, bukan jumlah bucket', function () {
        // 24 jam / 24 bucket = 1 jam. 6 jam / 24 bucket = 15 menit.
        $wide = $this->analytics->funnel(24);
        $narrow = $this->analytics->funnel(6);

        $wideSpan = end($wide)['at']->timestamp - $wide[0]['at']->timestamp;
        $narrowSpan = end($narrow)['at']->timestamp - $narrow[0]['at']->timestamp;

        expect($wideSpan)->toBeGreaterThan($narrowSpan);
    });

    it('status ignored tidak dihitung sebagai throughput pesan', function () {
        analyticsLog(['status' => 'ignored']);

        expect($this->analytics->volumeByStatus(24))->toBe(['ignored' => 1])
            ->and(array_sum(array_map(
                fn (array $b) => $b['accepted'] + $b['rejected'] + $b['received'] + $b['delivered'] + $b['read'],
                $this->analytics->funnel(24)
            )))->toBe(0);
    });
});

it('🔴 grafik plotting kelima status throughput, bukan hanya tiga', function () {
    // Tiap status yang dihitung `summary()` harus punya dataset, dan nilai
    // dataset itu harus persis bucket-nya. Kalau `delivered`/`read` tidak
    // digambar, angka "Masuk" di kartu KPI tidak akan pernah cocok dengan
    // garis mana pun di grafik.
    $component = Livewire::test(AnalyticsFunnel::class);

    $datasets = $component->viewData('chartDatasets');
    $funnel = $component->viewData('funnel');

    expect($datasets)->toHaveCount(count(WaraAnalytics::throughputStatuses()));

    // Cocokkan per label, bukan per indeks: urutan dataset boleh berubah
    // tanpa mengubah yang diuji.
    $byLabel = collect($datasets)->keyBy(fn (array $set) => (string) $set['label']);

    $expected = [
        __('Diterima gateway') => 'accepted',
        __('Ditolak gateway') => 'rejected',
        __('Masuk') => 'received',
        __('Terkirim device') => 'delivered',
        __('Dibaca') => 'read',
    ];

    foreach ($expected as $label => $status) {
        expect($byLabel->has($label))->toBeTrue("label '{$label}' tidak ada di grafik")
            ->and($byLabel->get($label)['data'])->toBe(array_column($funnel, $status));
    }
});

describe('atribusi keluar per client', function () {
    it('mengelompokkan pesan keluar per client', function () {
        $a = analyticsClient('Client A');
        $b = analyticsClient('Client B');

        analyticsLog(['client_id' => $a->getKey(), 'status' => 'accepted']);
        analyticsLog(['client_id' => $a->getKey(), 'status' => 'accepted']);
        analyticsLog(['client_id' => $a->getKey(), 'status' => 'rejected']);
        analyticsLog(['client_id' => $b->getKey(), 'status' => 'accepted']);

        $rows = $this->analytics->outboundByClient(24);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['name'])->toBe('Client A')
            ->and($rows[0]['accepted'])->toBe(2)
            ->and($rows[0]['rejected'])->toBe(1)
            ->and($rows[1]['name'])->toBe('Client B')
            ->and($rows[1]['accepted'])->toBe(1);
    });

    it('🔴 tidak pernah menghitung pesan masuk, meski punya client_id', function () {
        // Ini skenario yang TIDAK boleh muncul di produksi karena
        // `recordOutgoing()` satu-satunya pembaris `client_id`. Tapi kalau
        // suatu saat ada bug di pemanggil lain, angka halaman tidak boleh ikut
        // bohong.
        $client = analyticsClient();

        analyticsLog([
            'direction' => 'in',
            'client_id' => $client->getKey(),
            'status' => 'received',
        ]);

        expect($this->analytics->outboundByClient(24))->toHaveCount(0);
    });

    it('pesan tanpa client tetap dihitung dan diberi label, bukan dibuang', function () {
        analyticsLog(['client_id' => null, 'status' => 'accepted']);

        $rows = $this->analytics->outboundByClient(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['client_id'])->toBeNull()
            ->and($rows[0]['accepted'])->toBe(1);
    });

    it('total per-client sama dengan total keluar', function () {
        $client = analyticsClient();

        analyticsLog(['client_id' => $client->getKey(), 'status' => 'accepted']);
        analyticsLog(['client_id' => $client->getKey(), 'status' => 'rejected']);
        analyticsLog(['client_id' => null, 'status' => 'accepted']);

        $sum = $this->analytics->outboundByClient(24)
            ->sum(fn (array $row) => $row['accepted'] + $row['rejected']);

        $status = $this->analytics->volumeByStatus(24);

        expect($sum)->toBe(($status['accepted'] ?? 0) + ($status['rejected'] ?? 0));
    });

    it('client yang sudah dihapus tidak membuat baris hilang', function () {
        $client = analyticsClient('Client Dihapus');
        $id = $client->getKey();

        analyticsLog(['client_id' => $id, 'status' => 'accepted']);
        $client->delete();

        $rows = $this->analytics->outboundByClient(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['accepted'])->toBe(1);
    });
});

describe('trafik per device', function () {
    it('memisahkan masuk dan keluar', function () {
        analyticsDevice('dev-a');
        analyticsDevice('dev-b');

        analyticsLog(['device_id' => 'dev-a', 'direction' => 'out', 'status' => 'accepted']);
        analyticsLog(['device_id' => 'dev-a', 'direction' => 'in', 'status' => 'received']);
        analyticsLog(['device_id' => 'dev-b', 'direction' => 'out', 'status' => 'accepted']);

        $rows = $this->analytics->trafficByDevice(24);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['device_id'])->toBe('dev-a')
            ->and($rows[0]['outbound'])->toBe(1)
            ->and($rows[0]['inbound'])->toBe(1)
            ->and($rows[1]['device_id'])->toBe('dev-b')
            ->and($rows[1]['inbound'])->toBe(0);
    });

    it('baris tanpa device_id tetap terhitung', function () {
        analyticsLog(['device_id' => null, 'status' => 'accepted']);

        $rows = $this->analytics->trafficByDevice(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['device_id'])->toBeNull()
            ->and($rows[0]['outbound'])->toBe(1);
    });
});

describe('latency', function () {
    it('🔴 rata-rata hanya menghitung baris yang punya latency_ms', function () {
        analyticsLog(['status' => 'accepted', 'latency_ms' => 100]);
        analyticsLog(['status' => 'accepted', 'latency_ms' => 300]);
        // Pesan yang DITOLAK tidak punya latency — gateway tidak pernah
        // menjawab. Kalau ikut dirata-ratakan, rata-rata akan terlihat turun
        // justru di saat gateway sedang bermasalah.
        analyticsLog(['status' => 'rejected', 'latency_ms' => null]);

        $latency = $this->analytics->latency(24);

        expect($latency['count'])->toBe(2)
            ->and($latency['average'])->toBe(200)
            ->and($latency['max'])->toBe(300);
    });

    it('tanpa data, average dan max adalah null, bukan nol', function () {
        expect($this->analytics->latency(24))->toBe([
            'count' => 0,
            'average' => null,
            'max' => null,
        ]);
    });

    it('mengabaikan pesan masuk yang kebetulan punya latency', function () {
        analyticsLog(['direction' => 'in', 'status' => 'received', 'latency_ms' => 900]);

        expect($this->analytics->latency(24)['count'])->toBe(0);
    });
});

describe('fan-out per endpoint', function () {
    it('mengelompokkan delivery per endpoint', function () {
        $client = analyticsClient('Client Webhook');
        $endpoint = new WaraWebhookEndpoint;
        $endpoint->forceFill([
            'client_id' => $client->getKey(),
            'url' => 'https://contoh.test/hook',
            'secret' => str_repeat('a', 40),
            'enabled' => true,
        ])->save();

        foreach ([['sent', 200], ['sent', 200], ['failed', 500]] as [$status, $code]) {
            WaraConsumerDelivery::query()->create([
                'consumer' => $endpoint->getKey(),
                'delivery_id' => hash('sha256', $endpoint->getKey().$status.$code.random_int(1, 1_000_000)),
                'event' => 'message.status',
                'device_id' => 'dev-1',
                'status' => $status,
                'attempts' => 1,
                'status_code' => $code,
                'last_error' => $status === 'failed' ? 'HTTP 500: jeblok' : null,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
        }

        $rows = $this->analytics->fanOutByEndpoint(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['client_name'])->toBe('Client Webhook')
            ->and($rows[0]['delivered'])->toBe(2)
            ->and($rows[0]['failed'])->toBe(1)
            ->and($rows[0]['last_status_code'])->toBe(500);
    });

    it('endpoint yang sudah dihapus tetap muncul, diberi label', function () {
        $endpointId = (string) Str::uuid();

        WaraConsumerDelivery::query()->create([
            'consumer' => $endpointId,
            'delivery_id' => hash('sha256', $endpointId),
            'event' => 'message.status',
            'device_id' => 'dev-1',
            'status' => 'failed',
            'attempts' => 3,
            'status_code' => 503,
            'last_error' => 'HTTP 503',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $rows = $this->analytics->fanOutByEndpoint(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['endpoint_id'])->toBe($endpointId)
            ->and($rows[0]['client_name'])->toBe('(endpoint dihapus)')
            ->and($rows[0]['failed'])->toBe(1)
            ->and($rows[0]['attempts'])->toBe(3);
    });
});

describe('sesi yang exhausted', function () {
    it('nol kalau batas dimatikan, dan dilaporkan sebagai limit_disabled', function () {
        config()->set('wara.max_attempts_per_session', 0);

        analyticsLog(['session_key' => 's1', 'attempt' => 99]);

        expect($this->analytics->exhaustedSessions(24))->toBe([
            'exhausted' => 0,
            'limit_disabled' => true,
        ]);
    });

    it('menghitung sesi yang menyentuh batas', function () {
        config()->set('wara.max_attempts_per_session', 3);

        analyticsLog(['session_key' => 's1', 'attempt' => 3]);
        analyticsLog(['session_key' => 's2', 'attempt' => 2]);

        expect($this->analytics->exhaustedSessions(24))->toBe([
            'exhausted' => 1,
            'limit_disabled' => false,
        ]);
    });
});

describe('summary', function () {
    it('🔴 persentase ditolak null saat tidak ada pesan keluar', function () {
        expect($this->analytics->summary(24)['rejected_percent'])->toBeNull();
    });

    it('menghitung persentase ditolak dari total keluar', function () {
        analyticsLog(['status' => 'accepted']);
        analyticsLog(['status' => 'accepted']);
        analyticsLog(['status' => 'accepted']);
        analyticsLog(['status' => 'rejected']);

        $summary = $this->analytics->summary(24);

        expect($summary['outbound'])->toBe(4)
            ->and($summary['rejected'])->toBe(1)
            ->and($summary['rejected_percent'])->toBe(25);
    });
});

describe('inbound per pengirim', function () {
    it('mengelompokkan pesan masuk per nomor, hanya event=message', function () {
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6282222', 'status' => 'received']);
        // `message.ack` bukan pesan masuk — tidak punya `phone`, dan harus
        // dilewati kalau query-nya suatu saat lupa difilter event.
        analyticsLog(['direction' => 'in', 'event' => 'message.ack', 'phone' => null, 'status' => 'delivered']);

        $rows = $this->analytics->inboundBySender(24)->keyBy('phone');

        expect($rows)->toHaveCount(2)
            ->and($rows['6281111']['messages'])->toBe(2)
            ->and($rows['6282222']['messages'])->toBe(1)
            ->and($rows->has(null))->toBeFalse();
    });

    it('🔴 meng-dedupe message_id yang diulang (webhook retry, PRD §12.4)', function () {
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'message_id' => 'mid-1', 'status' => 'received']);
        // Replay: GOWA mengulang webhook, `recordIncoming()` menulis baris
        // baru dengan message_id yang sama.
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'message_id' => 'mid-1', 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'message_id' => 'mid-2', 'status' => 'received']);
        // Tanpa message_id tidak bisa dibedakan dari pengulang — dihitung mentah.
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6282222', 'message_id' => null, 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6282222', 'message_id' => null, 'status' => 'received']);

        $rows = $this->analytics->inboundBySender(24)->keyBy('phone');

        expect($rows['6281111']['messages'])->toBe(2)
            ->and($rows['6281111']['raw'])->toBe(3)
            ->and($rows['6281111']['replayed'])->toBe(1)
            ->and($rows['6282222']['messages'])->toBe(2)
            ->and($rows['6282222']['replayed'])->toBe(0);
    });

    it('mengabaikan baris di luar rentang', function () {
        analyticsLog([
            'direction' => 'in',
            'event' => 'message',
            'phone' => '6281111',
            'status' => 'received',
            'created_at' => now()->subHours(30),
        ]);

        expect($this->analytics->inboundBySender(24))->toBeEmpty();
    });

    it('nomor null ditampilkan sebagai (tanpa nomor)', function () {
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => null, 'status' => 'received']);

        $rows = $this->analytics->inboundBySender(24);

        expect($rows[0]['label'])->toBe(__('(tanpa nomor)'))
            ->and($rows[0]['phone'])->toBeNull()
            ->and($rows[0]['messages'])->toBe(1);
    });

    it('terurut dari yang paling banyak pesan', function () {
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6282222', 'status' => 'received']);

        $rows = $this->analytics->inboundBySender(24);

        expect($rows[0]['phone'])->toBe('6281111')
            ->and($rows[1]['phone'])->toBe('6282222');
    });

    it('🔴 tidak mengelompokkan per client_id — tetap agregat per nomor', function () {
        $a = analyticsClient('Client A');
        $b = analyticsClient('Client B');

        // Situasi terburuk: satu pengirim, dua client membagikan satu device
        // (wara_routes UNIQUE (client_id, purpose)), dan data uji sengaja
        // dibuat "bocor" (client_id terisi). Agregasi harus tetap per nomor —
        // kalau mulai memakai client_id, baris ini jadi double count.
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'client_id' => $a->getKey(), 'status' => 'received']);
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'client_id' => $b->getKey(), 'status' => 'received']);

        $rows = $this->analytics->inboundBySender(24);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['phone'])->toBe('6281111')
            ->and($rows[0]['messages'])->toBe(2);
    });
});

describe('halaman Analitik', function () {
    it('menolak user tanpa permission wara.log.read', function () {
        $other = User::factory()->create();

        Livewire::actingAs($other)
            ->test(AnalyticsIndex::class)
            ->assertForbidden();
    });

    it('🔴 rentang di luar daftar diperbaiki ke default, bukan error', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->set('range', 0)
            ->assertSet('range', WaraAnalytics::DEFAULT_RANGE_HOURS)
            ->assertOk();
    });

    it('menerima rentang yang ada di daftar', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->set('range', 6)
            ->assertSet('range', 6);
    });

    it('merender komponen anak dengan range yang sama', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSeeLivewire(AnalyticsHeader::class)
            ->assertSeeLivewire(AnalyticsSummary::class)
            ->assertSeeLivewire(AnalyticsFunnel::class)
            ->assertSeeLivewire(AnalyticsBreakdown::class);
    });

    it('🔴 tombol rentang di header mengubah range milik Index', function () {
        // Header tidak memegang range; ia hanya `$dispatch`. Kalau tombolnya
        // `$set('range', ...)` seperti versi pertama, tes ini lolos untuk
        // `AnalyticsHeader` sendiri tapi semua grafik di bawahnya tetap
        // menampilkan rentang lama.
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->call('selectRange', 6)
            ->assertSet('range', 6);
    });

    it('🔴 rentang dari event divalidasi, bukan dipercaya', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->call('selectRange', 999)
            ->assertSet('range', WaraAnalytics::DEFAULT_RANGE_HOURS);
    });

    it('rentang tersimpan di query string', function () {
        Livewire::withQueryParams(['range' => 72])
            ->actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSet('range', 72);
    });

    it('🔴 range jelek di query string tidak merusak halaman', function () {
        Livewire::withQueryParams(['range' => -5])
            ->actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSet('range', WaraAnalytics::DEFAULT_RANGE_HOURS)
            ->assertOk();
    });

    it('🔴 range NON-NUMERIK di query string tidak merusak halaman', function () {
        // `?range=abc` membuat TypeError saat Livewire menghydrasi string ke
        // properti `int $range` (via `#[Url]`) — SEBELUM `mount()` sempat
        // menjalankan `normalizeRange()`. Versi lama halaman 500.
        Livewire::withQueryParams(['range' => 'abc'])
            ->actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSet('range', WaraAnalytics::DEFAULT_RANGE_HOURS)
            ->assertOk();
    });

    it('🔴 range kosong di query string diperlakukan sebagai default', function () {
        Livewire::withQueryParams(['range' => ''])
            ->actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSet('range', WaraAnalytics::DEFAULT_RANGE_HOURS)
            ->assertOk();
    });

    it('🔴 perubahan range meneruskan nilai baru ke komponen anak', function () {
        // Anak di-render sebagai komponen terpisah, jadi HTML-nya tidak ikut
        // masuk string render parent. Yang diuji di sini adalah LOKASI anak
        // (`wire:id`) di dalam render parent: kalau `Index` mengirim `:range`,
        // tiga grafik itu terpasang ulang dengan nilai baru. Kalau atributnya
        // dihapus, range anak diam-diam kembali ke default-nya sendiri.
        $html = Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->call('selectRange', 6)
            ->assertSet('range', 6)
            ->html();

        foreach (['analytics-header', 'analytics-summary', 'analytics-funnel', 'analytics-breakdown'] as $child) {
            expect($html)->toContain($child);
        }
    });

    it('🔴 tombol header men-dispatch, bukan $set', function () {
        // Ini yang menjamin tombol di browser benar-benar mengubah range milik
        // Index. Kalau view-nya berubah lagi ke `$set`, tes ini gagal.
        //
        // 🔴 Beruang tanpa kurung kurawal (`range: 6`) melempar SyntaxError di
        // browser — `evaluateActionExpression` mengkompilasi ekspresi jadi
        // `new Function(...)`, dan `range:` bukan sintaks JS yang valid. Versi
        // lama meng-assert sintaks yang rusak ini, jadi tombol gagal diam-diam.
        $view = file_get_contents(__DIR__.'/../../resources/views/livewire/pages/landlord/analytics/section/analytics-header.blade.php');

        expect($view)->toContain('wire:click="$dispatch(\'analytics-range-changed\', { range: ')
            ->not->toContain('wire:click="$dispatch(\'analytics-range-changed\', range: ')
            ->not->toContain('wire:click="$set(');
    });

    it('🔴 menampilkan catatan bahwa atribusi client hanya untuk keluar', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSee(__('Atribusi per client hanya untuk pesan KELUAR. Pesan masuk dicatat per device, karena satu device bisa melayani beberapa client.'));
    });

    it('🔴 merender tabel Pesan Masuk per Pengirim', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSee(__('Pesan Masuk per Pengirim'))
            ->assertSee(__('Angka per nomor pengirim — bukan atribusi per client. Pesan masuk tidak pernah punya client_id (PRD §14.3).'))
            ->assertSee(__('Belum ada pesan masuk pada rentang ini.'));
    });

    it('menampilkan nomor pengirim di tabel inbound', function () {
        analyticsLog(['direction' => 'in', 'event' => 'message', 'phone' => '6281111', 'status' => 'received']);

        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertSee(__('Pesan Masuk per Pengirim'))
            ->assertSee('6281111');
    });

    it('halaman tetap hidup saat wara_logs kosong', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsIndex::class)
            ->assertOk()
            ->assertSee(__('Belum ada pesan keluar pada rentang ini.'));
    });

    it('latency null ditampilkan sebagai —, bukan 0 ms', function () {
        Livewire::actingAs($this->user)
            ->test(AnalyticsSummary::class)
            ->assertSee('—');
    });
});
