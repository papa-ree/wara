<?php

namespace Bale\Wara\Tests;

use Bale\Wara\WaraServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WaraServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('wara.endpoint', 'http://gateway.test');
        $app['config']->set('wara.api_key', 'user:pass');
        $app['config']->set('wara.webhook_secret', 'rahasia-webhook');
        $app['config']->set('wara.default_device_id', '');
    }

    /**
     * Buat tabel package tanpa Depends on migration aplikasi.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
