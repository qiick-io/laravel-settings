<?php

declare(strict_types=1);

namespace Qiick\Settings\Laravel;

use Illuminate\Support\ServiceProvider;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Console\CheckSettingsCommand;
use Qiick\Settings\ScopeRegistry;

/** @internal Wires the package into the Laravel application. */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/settings.php', 'settings');
        $this->app->singleton(ScopeRegistry::class);
    }

    public function boot(SettingsConfig $config): void
    {
        $config->validateStorageOptions();

        if ($config->shouldLoadMigrations()) {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        }

        $this->publishes([
            dirname(__DIR__, 2).'/config/settings.php' => config_path('settings.php'),
        ], 'settings-config');

        $this->commands([CheckSettingsCommand::class]);
    }
}
