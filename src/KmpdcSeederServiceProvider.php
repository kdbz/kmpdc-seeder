<?php

namespace Thibitisha\KmpdcSeeder;

use Illuminate\Support\ServiceProvider;
use Thibitisha\KmpdcSeeder\Console\SyncKmpdcData;
use Thibitisha\KmpdcSeeder\Console\ExtractKmpdcData;
use Thibitisha\KmpdcSeeder\Console\ImportKmpdcData;
use Illuminate\Support\Facades\File;

class KmpdcSeederServiceProvider extends ServiceProvider
{
    /**
     * Register bindings or config.
     */
    public function register(): void
    {
        // Merge package config with app config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/kmpdc-seeder.php',
            'kmpdc-seeder'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Path definitions
        $packageConfigPath = __DIR__ . '/../config/kmpdc-seeder.php';
        $appConfigPath = config_path('kmpdc-seeder.php');

        // ✅ Auto-publish config if not already present
        if ($this->app->runningInConsole() && !File::exists($appConfigPath)) {
            File::copy($packageConfigPath, $appConfigPath);
            $this->app['log']->info('[KmpdcSeeder] Config file published automatically.');
        }


        // ✅ Publish config manually if desired
        $this->publishes([
            $packageConfigPath => $appConfigPath,
        ], 'config');

        // ✅ Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncKmpdcData::class,
                ExtractKmpdcData::class,
                ImportKmpdcData::class,
            ]);
        }
    }
}
