<?php

namespace Bale\Wara;

use Bale\Wara\Channels\WaraChannel;
use Bale\Wara\Commands\InstallCommand;
use Bale\Wara\Commands\PollDeviceStatusCommand;
use Bale\Wara\Commands\PublishMigrationCommand;
use Bale\Wara\Commands\SyncDevicesCommand;
use Bale\Wara\Contracts\WaraGateway;
use Bale\Wara\Exceptions\WaraException;
use Bale\Wara\Gateways\GowaGateway;
use Bale\Wara\Support\ConsumerConfig;
use Bale\Wara\Support\PhoneNumber;
use Bale\Wara\Support\SendPacer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

class WaraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wara.php', 'wara');

        $this->app->singleton(PhoneNumber::class);
        $this->app->singleton(SendPacer::class);

        $this->app->singleton(WaraGateway::class, GowaGateway::class);

        $this->app->singleton(WaraManager::class, fn ($app) => new WaraManager(
            $app->make(WaraGateway::class),
            $app->make(PhoneNumber::class),
            $app->make(SendPacer::class),
        ));

        // Kanal notifikasi Laravel (PRD §13). Dibuat lewat container supaya
        // `WaraManager` yang di-inject channel adalah instance yang sama
        // dengan yang dipakai package lain.
        //
        // `extend()` diwarisi dari `Illuminate\Support\Manager`.
        $this->app->make(ChannelManager::class)->extend(
            'wara',
            fn ($app) => $app->make(WaraChannel::class)
        );

        $this->commands([
            InstallCommand::class,
            SyncDevicesCommand::class,
            PollDeviceStatusCommand::class,
            PublishMigrationCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->validateConfig();

        $this->loadViews();
        $this->app->booted(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        $this->offerPublishing();
        $this->scheduleJobs();
        $this->registerLivewireComponents();
    }

    /**
     * Daftarkan komponen Livewire dengan alias kebab-case.
     *
     * src/Livewire/Pages/Landlord/Device/Index.php
     *   => <livewire:wara.pages.landlord.device.index />
     */
    protected function registerLivewireComponents(): void
    {
        $namespace = 'Bale\\Wara\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder;
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePathname = $file->getRelativePathname();
            $nsPath = str_replace(['/', '\\'], '\\', $relativePathname);

            $class = $namespace.'\\'.Str::beforeLast($nsPath, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, LivewireComponent::class)) {
                continue;
            }

            $withoutExt = Str::replaceLast('.php', '', $relativePathname);
            $segments = preg_split('#[\\/\\\\]#', $withoutExt);
            $kebab = array_map(fn ($s) => Str::kebab($s), $segments);

            Livewire::component('wara.'.implode('.', $kebab), $class);
        }
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            __DIR__.'/../resources/views',
            'wara'
        );
    }

    /**
     * Validasi `default_device_id` saat boot (PRD §6.3.1).
     *
     * Tujuannya supaya salah config ketahuan di deploy, bukan saat operator
     * sedang menunggu OTP. Nilai dikosongkan tidak dianggap error karena
     * `WARA_WEBHOOK` sengaja kosong selama development (keputusan #7).
     */
    protected function validateConfig(): void
    {
        $this->validateDefaultDeviceId();
        $this->validateConsumers();
    }

    /**
     * Validasi `default_device_id` saat boot (PRD §6.3.1).
     *
     * Tujuannya supaya salah config ketahuan di deploy, bukan saat operator
     * sedang menunggu OTP. Nilai dikosongkan tidak dianggap error karena
     * `WARA_WEBHOOK` sengaja kosong selama development (keputusan #7).
     */
    protected function validateDefaultDeviceId(): void
    {
        $defaultDeviceId = (string) config('wara.default_device_id');

        if ($defaultDeviceId === '') {
            return;
        }

        if (! preg_match('/^\d+@s\.whatsapp\.net$/', $defaultDeviceId)) {
            throw WaraException::misconfigured(
                'WARA_DEFAULT_DEVICE_ID',
                'JID device pengirim dalam format 6285126061182@s.whatsapp.net (bukan nama device)',
                $defaultDeviceId
            );
        }
    }

    /**
     * Validasi consumer fan-out saat boot (PRD §12.2).
     *
     * Hanya consumer `enabled=true` yang diperiksa. Consumer `enabled=false`
     * boleh punya `url`/`secret` kosong — itulah default-nya, dan mengulang
     * semuanya akan membuat package gagal boot hanya karena dikonfigurasi.
     *
     * Yang digagalkan di sini: URL non-https ke host non-lokal (risiko SSRF)
     * dan secret yang kosong/pendek (kirim tanpa tanda tangan yang berarti).
     */
    protected function validateConsumers(): void
    {
        $consumers = config('wara.consumers', []);

        if (! is_array($consumers)) {
            return;
        }

        foreach ($consumers as $name => $rawConfig) {
            $name = (string) $name;

            if (! is_array($rawConfig) || ! ConsumerConfig::isEnabled($rawConfig)) {
                continue;
            }

            $config = ConsumerConfig::normalize($rawConfig);

            ConsumerConfig::assertUrlAllowed($name, $config['url']);
            ConsumerConfig::assertSecretUsable($name, $config['secret']);
        }
    }

    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/wara.php' => config_path('wara.php'),
        ], 'wara:config');

        $this->publishes($this->getMigrations(), 'wara:migrations');
    }

    /**
     * Jadwal pemeriksaan berkala (PRD §7.1 dan §8.3).
     *
     * `wara:sync-devices` mengisi katalog; `wara:poll-status` terpisah karena
     * §8.3 meminta deteksi logged-out yang lebih cepat daripada sync.
     */
    protected function scheduleJobs(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Schedule::command('wara:sync-devices')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        Schedule::command('wara:poll-status')
            ->everyMinute()
            ->withoutOverlapping();
    }

    /**
     * @return array<string, string>
     */
    protected function getMigrations(): array
    {
        $migrations = [];
        $sourcePath = __DIR__.'/../database/migrations/';

        if (! is_dir($sourcePath)) {
            return $migrations;
        }

        foreach (glob($sourcePath.'*.php.stub') as $file) {
            $name = basename($file, '.stub');

            $migrations[$file] = database_path(
                sprintf('migrations/%s_%s.php', date('Y_m_d_His'), $name)
            );
        }

        return $migrations;
    }
}
