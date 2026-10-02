<?php

use Bale\Wara\Exceptions\TooManyAttemptsException;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Models\WaraLog;
use Bale\Wara\Models\WaraSession;
use Bale\Wara\Support\SendPacer;
use Bale\Wara\WaraManager;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);

    config()->set('wara.endpoint', 'http://gateway.test');
    config()->set('wara.api_key', 'user:pass');

    Cache::flush();

    $this->manager = app(WaraManager::class);
    $this->pacer = app(SendPacer::class);

    $this->session = WaraSession::query()->create([
        'device_id' => 'wago prod',
        'jid' => '6285126061182@s.whatsapp.net',
        'display_name' => 'Nawasara Agent',
        'is_present' => true,
        'is_connected' => true,
        'is_logged_in' => true,
        'last_seen_at' => now(),
    ]);

    // Jaring pengaman diarahkan ke device test, supaya purpose kosong tetap
    // punya tujuan yang valid.
    config()->set('wara.default_device_id', $this->session->jid);

    $this->pacer->clearDeviceBackoff('wago prod');

    // Sengaja TIDAK memasang Http::fake() di sini. Stub dengan pola URL yang
    // sama tidak menimpa stub sebelumnya — stub pertama yang cocok yang
    // menang. Jadi tiap test memasang sendiri lewat helper di bawah.
});

function fakeGatewaySuccess(): void
{
    Http::fake([
        'gateway.test/*' => ['message_id' => 'msg-1'],
    ]);
}

function fakeGatewayFailure(int $status = 422): void
{
    Http::fake([
        'gateway.test/*' => fn () => Http::response(
            ['code' => 'SOMETHING'],
            $status
        ),
    ]);
}

describe('cooldown per nomor (§8.2)', function () {
    it('tidak menahan pengiriman pertama ke satu nomor', function () {
        expect($this->pacer->phoneCooldownRemaining('628123456789'))->toBe(0);
    });

    it('menahan pengiriman kedua ke nomor yang sama, bukan mengirim ulang', function () {
        fakeGatewaySuccess();
        config()->set('wara.cooldown_seconds', 1);

        $this->manager->sendMessage('628123456789', 'pertama');
        $this->manager->sendMessage('628123456789', 'kedua');

        // Dua request terkirim, tapi keduanya tercatat — tidak ada error.
        Http::assertSentCount(2);
        expect(WaraLog::query()->count())->toBe(2);
    });

    it('nomor berbeda tidak saling memengaruhi', function () {
        fakeGatewaySuccess();
        $this->manager->sendMessage('628111111111', 'a');
        $this->manager->sendMessage('628222222222', 'b');

        expect($this->pacer->phoneCooldownRemaining('628111111111'))->toBeGreaterThan(0)
            ->and($this->pacer->phoneCooldownRemaining('628222222222'))->toBeGreaterThan(0);
    });

    it('normalisasi nomor dipakai sebagai kunci cooldown', function () {
        fakeGatewaySuccess();
        $this->manager->sendMessage('081234567890', 'format lokal');

        // Nomor yang sama dalam format internasional harus kena cooldown.
        // 081234567890 -> 62 + 81234567890 = 6281234567890
        expect($this->pacer->phoneCooldownRemaining('6281234567890'))->toBeGreaterThan(0);
    });

    it('cooldown dimatikan lewat config tidak menahan', function () {
        fakeGatewaySuccess();
        config()->set('wara.cooldown_seconds', 0);

        $this->manager->sendMessage('628123456789', 'a');

        expect($this->pacer->phoneCooldownRemaining('628123456789'))->toBe(0);
    });
});

describe('batas percobaan per sesi login (§8.2)', function () {
    beforeEach(function () {
        config()->set('wara.cooldown_seconds', 0);
        fakeGatewaySuccess();
    });

    it('menghitung percobaan pada satu sesi', function () {
        expect($this->pacer->attemptsInSession('sesi-1'))->toBe(0);

        $this->manager->sendMessage('628123456789', 'a', sessionKey: 'sesi-1');
        $this->manager->sendMessage('628123456789', 'b', sessionKey: 'sesi-1');

        expect($this->pacer->attemptsInSession('sesi-1'))->toBe(2);
    });

    it('menolak setelah batas percobaan habis', function () {
        config()->set('wara.max_attempts_per_session', 3);

        $this->manager->sendMessage('628123456789', 'a', sessionKey: 'sesi-1');
        $this->manager->sendMessage('628123456789', 'b', sessionKey: 'sesi-1');
        $this->manager->sendMessage('628123456789', 'c', sessionKey: 'sesi-1');

        expect(fn () => $this->manager->sendMessage('628123456789', 'd', sessionKey: 'sesi-1'))
            ->toThrow(TooManyAttemptsException::class, 'habis');

        // Keempat pesan tidak boleh keluar ke gateway.
        Http::assertSentCount(3);
    });

    it('percobaan di sesi lain tidak terpengaruh', function () {
        config()->set('wara.max_attempts_per_session', 1);

        $this->manager->sendMessage('628123456789', 'a', sessionKey: 'sesi-1');

        expect(fn () => $this->manager->sendMessage('628123456789', 'b', sessionKey: 'sesi-1'))
            ->toThrow(TooManyAttemptsException::class);

        // Sesi berbeda boleh tetap mencoba.
        $this->manager->sendMessage('628123456789', 'c', sessionKey: 'sesi-2');

        Http::assertSentCount(2);
    });

    it('tanpa sessionKey batas percobaan dilewati', function () {
        config()->set('wara.max_attempts_per_session', 1);

        $this->manager->sendMessage('628123456789', 'a');
        $this->manager->sendMessage('628123456789', 'b');
        $this->manager->sendMessage('628123456789', 'c');

        Http::assertSentCount(3);
    });

    it('sendOtp meneruskan sessionKey', function () {
        config()->set('wara.max_attempts_per_session', 1);

        $this->manager->sendOtp('628123456789', '111111', sessionKey: 'sesi-otp');

        expect(fn () => $this->manager->sendOtp('628123456789', '222222', sessionKey: 'sesi-otp'))
            ->toThrow(TooManyAttemptsException::class);
    });
});

describe('pencatatan percobaan (§10.2)', function () {
    it('session_key dan attempt tersimpan di wara_logs', function () {
        fakeGatewaySuccess();
        config()->set('wara.cooldown_seconds', 0);

        $this->manager->sendMessage('628123456789', 'a', sessionKey: 'sesi-x');
        $this->manager->sendMessage('628123456789', 'b', sessionKey: 'sesi-x');

        $logs = WaraLog::query()->where('session_key', 'sesi-x')->orderBy('attempt')->get();

        expect($logs)->toHaveCount(2)
            ->and($logs[0]->attempt)->toBe(1)
            ->and($logs[1]->attempt)->toBe(2);
    });

    it('percobaan yang gagal juga tercatat agar pola terlihat sebelum kejadian', function () {
        fakeGatewayFailure();

        try {
            $this->manager->sendMessage('628123456789', 'gagal', sessionKey: 'sesi-y');
        } catch (Throwable) {
            // diharapkan
        }

        $log = WaraLog::query()->where('session_key', 'sesi-y')->first();

        expect($log)->not->toBeNull()
            ->and($log->status)->toBe('rejected')
            ->and($log->attempt)->toBe(1);
    });

    it('tanpa sessionKey kolom attempt kosong', function () {
        fakeGatewaySuccess();
        $this->manager->sendMessage('628123456789', 'a');

        $log = WaraLog::query()->first();

        expect($log->session_key)->toBeNull()
            ->and($log->attempt)->toBeNull();
    });
});

describe('backoff device (§8.2)', function () {
    beforeEach(function () {
        config()->set('wara.cooldown_seconds', 0);
        // Stub dipasang per-test, bukan di beforeEach: stub dengan pola URL
        // yang sama tidak saling menimpa, dan stub pertama yang cocok yang
        // menang. Memasang sukses di sini akan menutupi stub gagal.
    });

    it('device tidak/backoff bisa mengirim', function () {
        fakeGatewaySuccess();
        expect($this->pacer->deviceBackoffRemaining('wago prod'))->toBe(0);

        $this->manager->sendMessage('628123456789', 'a');

        Http::assertSentCount(1);
    });

    it('device yang gagal diberi backoff', function () {
        // Di-install di dalam test (bukan beforeEach) supaya stub sukses yang
        // terpasang lebih dulu tidak menutupi stub gagal.
        fakeGatewayFailure();

        try {
            $this->manager->sendMessage('628123456789', 'gagal');
        } catch (Throwable) {
            // diharapkan
        }

        expect($this->pacer->deviceBackoffRemaining('wago prod'))->toBeGreaterThan(0);
    });

    it('device dalam backoff ditolak tanpa mencoba ke gateway', function () {
        $this->pacer->markDeviceBackoff('wago prod');

        expect(fn () => $this->manager->sendMessage('628123456789', 'a'))
            ->toThrow(WaraException::class, 'backoff');

        Http::assertNothingSent();
    });

    it('backoff dihapus setelah pengiriman berhasil', function () {
        fakeGatewaySuccess();
        $this->pacer->markDeviceBackoff('wago prod');
        $this->pacer->clearDeviceBackoff('wago prod');

        $this->manager->sendMessage('628123456789', 'a');

        expect($this->pacer->deviceBackoffRemaining('wago prod'))->toBe(0);
        Http::assertSentCount(1);
    });

    it('backoff nol tidak menahan apa pun', function () {
        config()->set('wara.device_backoff_seconds', 0);

        $this->pacer->markDeviceBackoff('wago prod');

        expect($this->pacer->deviceBackoffRemaining('wago prod'))->toBe(0);
    });
});

describe('urgensi pacing untuk OTP (§8.2)', function () {
    it('pola captcha-gagal lalu kirim-ulang tidak menghasilkan burst', function () {
        fakeGatewaySuccess();

        // Skenario nyata: login gagal beberapa kali lalu OTP dikirim ulang.
        config()->set('wara.cooldown_seconds', 0);
        config()->set('wara.max_attempts_per_session', 3);

        $session = 'login-sesi-abc';

        // Penghitung Percobaan dibaca SEBELUM panggilan, karena batas
        // diperiksa lebih dulu lalu baru attempt dicatat.
        for ($i = 1; $i <= 3; $i++) {
            expect($this->pacer->attemptsInSession($session))->toBe($i - 1);
            $this->manager->sendOtp('628123456789', '00000'.$i, sessionKey: $session);
        }

        expect($this->pacer->attemptsInSession($session))->toBe(3);

        // Percobaan keempat harus tertahan, bukan terkirim.
        expect(fn () => $this->manager->sendOtp('628123456789', '000004', sessionKey: $session))
            ->toThrow(TooManyAttemptsException::class);

        Http::assertSentCount(3);
        expect(WaraLog::query()->forSession($session)->count())->toBe(3);
    });
});
