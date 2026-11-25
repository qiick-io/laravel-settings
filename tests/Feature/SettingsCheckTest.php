<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Laravel\SettingsServiceProvider;

it('registers the settings deploy check in Artisan', function (): void {
    $this->artisan('migrate')->assertSuccessful();

    try {
        $this->artisan('settings:check')
            ->expectsOutput('Settings configuration and table are ready.')
            ->assertExitCode(0);
    } finally {
        $this->artisan('migrate:rollback')->assertSuccessful();
    }
});

it('reports when the settings table is missing', function (): void {
    Schema::shouldReceive('hasTable')->with('settings')->once()->andReturnFalse();

    $this->artisan('settings:check')
        ->expectsOutput('The settings table is missing.')
        ->doesntExpectOutput('The settings write locks table is missing.')
        ->assertExitCode(1);
});

it('reports when the write locks table is missing', function (): void {
    Schema::shouldReceive('hasTable')->with('settings')->once()->andReturnTrue();
    Schema::shouldReceive('hasTable')->with('settings_write_locks')->once()->andReturnFalse();

    $this->artisan('settings:check')
        ->expectsOutput('The settings write locks table is missing.')
        ->assertExitCode(1);
});

it('rejects an incomplete set of write lock rows at deploy time', function (string $case): void {
    $this->artisan('migrate')->assertSuccessful();

    try {
        $locks = DB::table('settings_write_locks');

        if ($case !== 'extra row') {
            $locks->where('id', 12)->delete();
        }

        if ($case !== 'missing row') {
            $locks->insert(['id' => 64]);
        }

        $this->artisan('settings:check')
            ->expectsOutput('The settings write locks table must contain IDs 0 through 63.')
            ->assertExitCode(1);
    } finally {
        $this->artisan('migrate:rollback')->assertSuccessful();
    }
})->with(['missing row', 'wrong ID', 'extra row']);

it('reports a database failure without printing connection details', function (): void {
    Schema::shouldReceive('hasTable')->with('settings')->once()
        ->andThrow(new PDOException('synthetic connection details'));

    $this->artisan('settings:check')
        ->expectsOutput('Could not inspect the settings table.')
        ->doesntExpectOutput('synthetic connection details')
        ->assertExitCode(1);
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
