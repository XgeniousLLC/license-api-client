<?php

namespace Xgenious\XgApiClient;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Xgenious\XgApiClient\Commands\XgApiClientCommand;

class XgApiClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('xgapiclient')
            ->hasConfigFile('xgapiclient')
            ->hasViews('XgApiClient')
            ->hasRoute('web')
            ->hasMigration('create_xg_ftp_infos_table')
            ->hasCommand(XgApiClientCommand::class);
    }

    public function boot()
    {
        app()->bind('XgApiClient', function () {
            return new XgApiClient();
        });

        // Publish V2 assets (JavaScript)
        $this->publishes([
            __DIR__ . '/../resources/js/UpdateManager.js' => base_path('../assets/vendor/xgapiclient/js/UpdateManager.js'),
        ], 'xgapiclient-assets');

        // Ensure update directory exists
        $this->ensureUpdateDirectoryExists();

        return parent::boot();
    }

    /**
     * Register V2 services
     */
    public function register()
    {
        parent::register();

        // Register V2 services as singletons for better performance
        $this->app->singleton(Services\V2\UpdateStatusManager::class, function ($app) {
            return new Services\V2\UpdateStatusManager();
        });
    }

    /**
     * Ensure the update directory exists and is writable
     */
    protected function ensureUpdateDirectoryExists(): void
    {
        $updateDir = config('xgapiclient.update.temp_directory', storage_path('app/xg-update'));

        if (!is_dir($updateDir)) {
            @mkdir($updateDir, 0755, true);
        }
    }
}
