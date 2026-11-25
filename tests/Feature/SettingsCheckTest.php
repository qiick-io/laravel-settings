<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Laravel\SettingsServiceProvider;

it('registers the settings deploy check in Artisan', function (): void {
    $this->artisan('settings:check')
        ->expectsOutput('Settings configuration is valid.')
        ->assertExitCode(0);
});

it('loads the shipped migration option before boot', function (): void {
    expect(config('settings.migrations.load'))->toBeTrue();
});

it('reports the invalid configuration key without printing its value', function (): void {
    config(['settings.migrations.load' => 'sensitive typo']);

    $this->artisan('settings:check')
        ->expectsOutput('settings.migrations.load must be a boolean.')
        ->assertExitCode(1);
});

it('publishes the package configuration under its own tag', function (): void {
    expect(ServiceProvider::pathsToPublish(SettingsServiceProvider::class, 'settings-config'))->toBe([
        dirname(__DIR__, 2).'/config/settings.php' => config_path('settings.php'),
    ]);
});

it('keeps the migration default for an older published config', function (): void {
    config(['settings.migrations' => []]);

    expect(config('settings.migrations.load'))->toBeNull()
        ->and($this->app->make(SettingsConfig::class)->shouldLoadMigrations())->toBeTrue();
});

it('adds the package migration path at boot', function (): void {
    expect($this->app->make(Migrator::class)->paths())
        ->toContain(dirname(__DIR__, 2).'/database/migrations');
});

it('skips the package migration path when disabled', function (): void {
    config(['settings.migrations.load' => false]);
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldNotReceive('path');
    $this->app->instance('migrator', $migrator);

    $this->app->call([new SettingsServiceProvider($this->app), 'boot']);
});

it('fails boot when the published option is invalid', function (): void {
    config(['settings.migrations.load' => null]);

    expect(fn () => $this->app->call([new SettingsServiceProvider($this->app), 'boot']))
        ->toThrow(InvalidArgumentException::class, 'settings.migrations.load must be a boolean.');
});
