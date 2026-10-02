<?php

use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Support\PhoneNumber;
use Bale\Wara\WaraServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(WaraServiceProvider::class);
});

describe('validasi default_device_id saat boot (PRD §6.3.1)', function () {
    it('menerima JID dengan format benar', function () {
        config()->set('wara.default_device_id', '6285126061182@s.whatsapp.net');

        $provider = new WaraServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    });

    it('menolak nama device sebagai ganti JID', function () {
        config()->set('wara.default_device_id', 'wago prod');

        $provider = new WaraServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(WaraException::class, 'WARA_DEFAULT_DEVICE_ID');
    });

    it('menolak JID dengan sufiks salah', function () {
        config()->set('wara.default_device_id', '6285126061182@c.us');

        $provider = new WaraServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(WaraException::class, 'bukan nama device');
    });

    it('toleransi nilai kosong selama development', function () {
        config()->set('wara.default_device_id', '');

        $provider = new WaraServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    });
});

describe('normalisasi nomor telepon', function () {
    beforeEach(function () {
        $this->phone = new PhoneNumber;
    });

    it('mengubah awalan 0 menjadi 62', function () {
        expect($this->phone->toInternational('0812 3456 7890'))->toBe('6281234567890');
    });

    it('membuang karakter non-digit', function () {
        expect($this->phone->toInternational('+62 812-3456-7890'))->toBe('6281234567890');
    });

    it('membiarkan nomor internasional apa adanya', function () {
        expect($this->phone->toInternational('6281234567890'))->toBe('6281234567890');
    });

    it('mengembalikan string kosong bila tidak ada digit', function () {
        expect($this->phone->toInternational('abc'))->toBe('');
    });
});
