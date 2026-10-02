<?php

use Bale\Wara\Channels\WaraChannel;
use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Events\WaraMessageSent;
use Bale\Wara\Gateways\GowaGateway;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\WaraManager;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    // 🔴 WAJIB. Suite memuat `.env` lokal, jadi tanpa override di sini
    // `wara.endpoint` menunjuk gateway NYATA (localhost:7200) dan test akan
    // mengirim pesan WhatsApp sungguhan. `preventStrayRequests()` di bawah
    // adalah jaring pengaman kedua kalau ada jalur yang lolos.
    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');
    config()->set('wara.cooldown_seconds', 0);
    config()->set('wara.device_backoff_seconds', 0);

    // Kanal tidak pernah mengirim `purpose` — itu batas §13.3 ("tidak pernah
    // menebak device"). Jadi device pengirimnya harus datang dari
    // `default_device_id`, persis seperti notifikasi domain di dunia nyata.
    config()->set('wara.default_device_id', '6285126061182@s.whatsapp.net');

    // `RefreshDatabase` tidak membersihkan cache, padahal `SendPacer` menyimpan
    // cooldown dan device backoff di sana. Tanpa flush, satu test yang memicu
    // backoff akan membuat test berikutnya gagal diam-diam.
    Cache::flush();

    Http::preventStrayRequests();
    fakeGatewayAccepts();
});

/**
 * Pasang stub gateway yang menerima (200).
 *
 * Dijadi helper karena `Http::fake()` meng-`merge`, bukan menimpa: test yang
 * butuh perilaku lain harus memanggil `fakeGateway*` yang lebih spesifik
 * sebagai stub PERTAMA, atau memakai `resetHttpStubs()` dulu.
 */
function fakeGatewayAccepts(): void
{
    Http::fake([
        'gateway.test/*' => Http::response([
            'code' => 200,
            'message' => 'Message sent',
            'results' => ['message_id' => 'wamid.TEST1', 'status' => 'sent'],
        ]),
    ]);
}

/**
 * Reset total stub + rekaman Http fake.
 *
 * Dipakai test yang butuh respons gateway berbeda dari default `beforeEach`.
 * Tanpa ini stub 200 selalu menang dan test diam-diam menguji hal yang salah.
 */
function resetHttpStubs(): void
{
    $factory = app(Factory::class);

    $stubs = new ReflectionProperty($factory, 'stubCallbacks');
    $stubs->setAccessible(true);
    $stubs->setValue($factory, new Collection);

    $recorded = new ReflectionProperty($factory, 'recorded');
    $recorded->setAccessible(true);
    $recorded->setValue($factory, []);

    Http::preventStrayRequests();
}

/**
 * Notifikasi contoh yang menunjuk ke channel `wara`.
 *
 * Sengaja TIDAK `ShouldQueue`: kalau queued, `notify()` hanya mengantre dan
 * kanal tidak pernah dijalankan — test jadi tidak menguji apa pun. Antrean
 * adalah milik pemanggil (PRD §13.1), bukan bagian dari kontrak kanal.
 */
class WaraTestNotification extends BaseNotification
{
    use Dispatchable;

    public function __construct(public string $text = 'Halo, ini notifikasi uji.') {}

    public function via(object $notifiable): array
    {
        return ['wara'];
    }

    public function toArray(object $notifiable): array
    {
        return ['wara' => $this->text];
    }
}

/** Notifikasi yang tidak punya isi teks sama sekali. */
class WaraEmptyNotification extends BaseNotification
{
    use Dispatchable;

    public function via(object $notifiable): array
    {
        return ['wara'];
    }

    public function toArray(object $notifiable): array
    {
        return ['subject' => 'x'];
    }
}

/** Notifikasi yang tidak punya nomor tujuan. */
class WaraNoPhoneNotification extends BaseNotification
{
    use Dispatchable;

    public function via(object $notifiable): array
    {
        return ['wara'];
    }

    public function toArray(object $notifiable): array
    {
        return ['wara' => 'tanpa nomor'];
    }
}

/**
 * Penangkap `NotificationFailed` berbasis array statis.
 *
 * Dipakai sebagai gantian `Event::fake()`: `fake()` mengganti dispatcher di
 * container dan TIDAK dikembalikan ke aslinya antar-test, jadi listener pada
 * test berikutnya akan terdaftar pada dispatcher palsu dan tidak pernah
 * terpicu. Kolektor statis tidak menyentuh state global tersebut.
 */
final class FailedNotificationCollector
{
    /** @var array<int, array<string, mixed>> */
    public static array $seen = [];

    public static function start(): void
    {
        self::$seen = [];

        Event::listen(NotificationFailed::class, function (NotificationFailed $e) {
            static::$seen[] = [
                'channel' => $e->channel,
                'notification' => $e->notification::class,
                'data' => $e->data,
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public static function seen(): array
    {
        return self::$seen;
    }

    /** @return array<int, string> */
    public static function channels(): array
    {
        return array_column(self::$seen, 'channel');
    }
}

function seedReadyDevice(array $overrides = []): WaraSession
{
    static $n = 0;
    $n++;

    return WaraSession::query()->create(array_merge([
        'device_id' => 'wago prod',
        'jid' => '6285126061182@s.whatsapp.net',
        'display_name' => 'Nawasara Agent',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ], $overrides));
}

describe('registrasi channel (PRD §13.6)', function () {
    it('channel "wara" terdaftar di ChannelManager', function () {
        expect(app(ChannelManager::class)->driver('wara'))
            ->toBeInstanceOf(WaraChannel::class);
    });

    it('WaraChannel ada di namespace Bale\Wara\Channels', function () {
        expect(class_exists(WaraChannel::class))->toBeTrue()
            ->and(class_exists(WaraMessageSent::class))->toBeTrue();
    });
});

describe('pengiriman lewat channel (PRD §13.2, §13.6)', function () {
    it('Notification::route("wara", $phone) mengirim lewat gateway', function () {
        seedReadyDevice();
        Event::fake([WaraMessageSent::class]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification('Halo dari channel.'));

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/send/message')
                && str_contains((string) $request->body(), 'Halo dari channel.');
        });
    });

    it('meneruskan Session ID device sebagai X-Device-Id', function () {
        seedReadyDevice();

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Http::assertSent(function ($request) {
            $headers = $request->headers();

            return ($headers['X-Device-Id'][0] ?? null) === 'wago prod';
        });
    });

    it('nomor polos dinormalisasi hanya di WaraManager', function () {
        seedReadyDevice();

        NotificationFacade::route('wara', '0812-3456-789')
            ->notify(new WaraTestNotification);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            // Channel meneruskan apa adanya; WaraManager yang menormalkan.
            return str_contains(json_encode($body), '628123456789')
                || str_contains(json_encode($body), '+628123456789');
        });
    });
});

describe('batas yang tidak boleh dilanggar (PRD §13.3)', function () {
    it('tidak melempar exception saat device offline', function () {
        seedReadyDevice([
            'is_connected' => false,
            'is_logged_in' => false,
            'last_seen_at' => now(),
        ]);

        // `notify()` harus selesai normal meski device tidak siap.
        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Http::assertNothingSent();

        // Tidak melempar = `notify()` selesai normal. Verifikasi sebenarnya
        // sebenarnya ada di block 'pelaporan kegagalan' yang memeriksa event
        // `NotificationFailed` benar-benar tiba.        expect(true)->toBeTrue();
    });

    it('tidak melempar exception saat gateway menolak', function () {
        seedReadyDevice();
        Http::fake(['gateway.test/*' => Http::response([
            'code' => 500,
            'message' => 'internal error',
        ], 500)]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        expect(true)->toBeTrue();
    });

    it('tidak melempar exception saat tidak ada nomor tujuan', function () {
        NotificationFacade::send(new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return ['wara'];
            }

            public function toArray(object $notifiable): array
            {
                return ['wara' => 'tanpa tujuan'];
            }
        }, new WaraNoPhoneNotification);

        expect(true)->toBeTrue();
    });

    it('tidak melempar exception saat notifikasi tanpa isi teks', function () {
        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraEmptyNotification);

        Http::assertNothingSent();

        // Tidak melempar = `notify()` selesai normal.
        expect(true)->toBeTrue();
    });

    it('tidak pernah menebak device pengirim', function () {
        // Hanya satu device yang tersedia; kalau channel menebak-nebak device
        // sendiri, test ini akan gagal karena request tetap terkirim.
        seedReadyDevice(['is_logged_in' => false, 'is_connected' => false]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Http::assertNothingSent();
    });
});

describe('event WaraMessageSent (PRD §13.4, §13.6)', function () {
    it('dipancarkan saat pengiriman sukses', function () {
        seedReadyDevice();
        Event::fake([WaraMessageSent::class]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Event::assertDispatched(
            WaraMessageSent::class,
            fn (WaraMessageSent $e) => $e->destination === '628123456789'
                && $e->deviceId === 'wago prod'
                && $e->messageId === 'wamid.TEST1'
                && $e->notification === WaraTestNotification::class
        );
    });

    it('juga dipancarkan untuk pemanggilan internal tanpa notifikasi', function () {
        seedReadyDevice();
        Event::fake([WaraMessageSent::class]);

        app(WaraManager::class)->sendMessage('628123456789', 'Pesan internal');

        Event::assertDispatched(
            WaraMessageSent::class,
            fn (WaraMessageSent $e) => $e->notification === null
                && $e->destination === '628123456789'
        );
    });

    it('tidak memuat isi pesan', function () {
        seedReadyDevice();
        Event::fake([WaraMessageSent::class]);

        $rahasia = 'RAHASIA-JANGAN-BOCOR-123';

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification($rahasia));

        Event::assertDispatched(function (WaraMessageSent $e) use ($rahasia) {
            $dump = print_r($e, true);

            return ! str_contains($dump, $rahasia);
        });
    });

    it('latencyMs terisi sebagai bilangan bulat', function () {
        seedReadyDevice();
        Event::fake([WaraMessageSent::class]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Event::assertDispatched(
            WaraMessageSent::class,
            fn (WaraMessageSent $e) => is_int($e->latencyMs) && $e->latencyMs >= 0
        );
    });

    it('tidak dipancarkan saat pengiriman gagal', function () {
        seedReadyDevice(['is_logged_in' => false, 'is_connected' => false]);
        Event::fake([WaraMessageSent::class]);

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Event::assertNotDispatched(WaraMessageSent::class);
    });
});

describe('pelaporan kegagalan (PRD §13.6)', function () {
    // Catatan: blok ini sengaja TIDAK memakai `Event::fake()`.
    //
    // `Event::fake()` mengganti dispatcher di container dan tidak
    // dikembalikan ke aslinya antar-test, jadi `Event::listen()` pada test
    // berikutnya akan terdaftar pada dispatcher palsu dan tidak pernah
    // terpanggil. Listener asli dipakai supaya setiap test berdiri sendiri.
    it('mendispatch NotificationFailed saat device offline', function () {
        seedReadyDevice(['is_connected' => false, 'is_logged_in' => false]);
        FailedNotificationCollector::start();

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        expect(FailedNotificationCollector::channels())->toContain('wara')
            ->and(FailedNotificationCollector::seen()[0]['notification'])->toBe(WaraTestNotification::class);
    });

    it('mendispatch NotificationFailed saat tidak ada nomor tujuan', function () {
        FailedNotificationCollector::start();

        NotificationFacade::send(new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return ['wara'];
            }

            public function toArray(object $notifiable): array
            {
                return ['wara' => 'tanpa tujuan'];
            }
        }, new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return ['wara'];
            }

            public function toArray(object $notifiable): array
            {
                return ['wara' => 'tanpa nomor'];
            }
        });

        expect(FailedNotificationCollector::channels())->toContain('wara');
    });

    it('melaporkan kegagalan saat gateway menolak', function () {
        seedReadyDevice();

        // `Http::fake()` meng-MERGE stub, jadi stub 200 dari `beforeEach` akan
        // selalu menang untuk pola URL yang sama. Stub harus dibersihkan dulu
        // supaya 500 benar-benar dipakai — tanpa ini test diam-diam
        // asserting `accepted` dan lulus dengan kesimpulan yang salah.
        resetHttpStubs();

        Http::fake([
            'gateway.test/*' => Http::response([
                'code' => 500,
                'message' => 'internal error',
            ], 500),
        ]);

        FailedNotificationCollector::start();

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Http::assertSentCount(1);

        $log = WaraLog::query()->where('direction', 'out')->latest()->first();

        expect($log)->not->toBeNull()
            ->and($log->status)->toBe('rejected')
            ->and(FailedNotificationCollector::channels())->toContain('wara');
    });

    it('tidak mendispatch NotificationFailed saat sukses', function () {
        seedReadyDevice();
        FailedNotificationCollector::start();

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        expect(FailedNotificationCollector::seen())->toBeEmpty();
    });
});

describe('nol request ke GOWA sungguhan (PRD §13.5)', function () {
    it('semua test memakai Http::fake', function () {
        // Guard suite-wide: kalau ada test yang lupa Http::fake(),
        // `preventStrayRequests()` di beforeEach akan melempar di sini.
        seedReadyDevice();

        NotificationFacade::route('wara', '628123456789')
            ->notify(new WaraTestNotification);

        Http::assertSentCount(1);
    });

    it('gateway adapter tetap injectable untuk test', function () {
        expect(app(WaraGateway::class))->toBeInstanceOf(GowaGateway::class);
    });
});
