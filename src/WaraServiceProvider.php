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
use Bale\Wara\Support\DeviceRouter;
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

        // 🔴 Router device. Singleton supaya cache dan reverse mapping yang
        // dibangun di dalamnya konsisten dalam satu request.
        $this->app->singleton(DeviceRouter::class);

        $this->app->singleton(WaraGateway::class, GowaGateway::class);

        $this->app->singleton(WaraManager::class, fn ($app) => new WaraManager(
            $app->make(WaraGateway::class),
            $app->make(PhoneNumber::class),
            $app->make(SendPacer::class),
            $app->make(DeviceRouter::class),
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
        $this->registerApiScopes();
    }

    /**
     * Daftarkan komponen Livewire dengan alias kebab-case.
     *
     * src/Livewire/Pages/Landlord/Device/Index.php
     *   => <livewire:wara.pages.landlord.device.index />
     */
    /**
     * Scope API milik `bale/wara`.
     *
     * 🔴 Didaftarkan lewat helper `registerApiScopes()` milik `bale/api`, bukan
     * dengan menyentuh registry-nya, supaya `bale/api` tidak perlu tahu domain
     * package lain.
     *
     * `wara.send` sengaja terpisah dari `wara.routes.write`: token yang hanya
     * boleh mengirim pesan tidak perlu wewenang mengubah device mana yang dipakai -
     * dan sebaliknya.
     */
    protected function registerApiScopes(): void
    {
        if (! function_exists('registerApiScopes')) {
            return;
        }

        registerApiScopes('wara', [
            'wara.send' => 'Mengirim pesan WhatsApp atas nama client (POST /api/v1/wara/send).',
            'wara.routes.read' => 'Membaca peta purpose ke device milik client sendiri.',
            'wara.routes.write' => 'Memetakan purpose ke device milik client sendiri.',
        ]);
    }

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
     * 🔴 VALIDASI CONSUMER FAN-OUT DIHAPUS DARI BOOT.
     *
     * Dulu method ini membacanya `config('wara.consumers')` dan gagal boot
     * kalau URL-nya tidak https atau secret-nya pendek. Sekarang endpoint-nya
     * ada di `wara_webhook_endpoints` dan diisi lewat UI admin, jadi tidak
     * ada lagi yang bisa dibaca di boot.
     *
     * Aturan yang sama TIDAK ikut hilang - ia pindah ke
     * `WaraWebhookEndpoint::assertUrlAllowed()` dan `assertSecretUsable()`,
     * dan dipanggil dua kali: saat admin menyimpan endpoint (supaya input salah
     * ditolak di tempat yang salah ketik), dan lagi di dalam job
     * saat benar-benar mengirim (supaya job yang terlanjur tersimpan di queue
     * tidak bisa melewati guardrail setelah datanya diubah).
     *
     * Melakukan query database di boot juga berisiko: `php artisan migrate`
     * jalan sebelum tabel ada.
     */
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
