<?php

declare(strict_types=1);

use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Laravel\SettingsServiceProvider;

it('loads the storage options from the configuration file at boot', function (): void {
    expect(config('settings.columns'))->toBe(['scope_id' => 'scope_id', 'legacy_scope_id' => null])
        ->and(config('settings.encryption'))->toBe(['strict' => true]);
});

it('supplies nested defaults for an older published configuration', function (): void {
    config(['settings.columns' => [], 'settings.encryption' => []]);
    $settingsConfig = $this->app->make(SettingsConfig::class);

    expect($settingsConfig->scopeIdColumn())->toBe('scope_id')
        ->and($settingsConfig->legacyScopeIdColumn())->toBeNull()
        ->and($settingsConfig->shouldUseStrictEncryption())->toBeTrue();
});

it('supports an alternative scope column and explicitly disabled strict encryption', function (): void {
    config([
        'settings.migrations.load' => false,
        'settings.columns.scope_id' => 'scope_ref',
        'settings.columns.legacy_scope_id' => 'scope_id',
        'settings.encryption.strict' => false,
    ]);
    $settingsConfig = $this->app->make(SettingsConfig::class);

    $settingsConfig->validateStorageOptions();

    expect($settingsConfig->scopeIdColumn())->toBe('scope_ref')
        ->and($settingsConfig->legacyScopeIdColumn())->toBe('scope_id')
        ->and($settingsConfig->shouldUseStrictEncryption())->toBeFalse();
});

it('fails boot when a present storage option is invalid', function (string $option, mixed $value): void {
    config([$option => $value]);

    expect(fn () => $this->app->call([new SettingsServiceProvider($this->app), 'boot']))
        ->toThrow(InvalidArgumentException::class, $option);
})->with([
    'nonboolean strict' => ['settings.encryption.strict', 'false'],
    'null strict' => ['settings.encryption.strict', null],
    'null scope column' => ['settings.columns.scope_id', null],
    'empty scope column' => ['settings.columns.scope_id', ''],
    'column expression' => ['settings.columns.scope_id', 'scope_id->name'],
    'invalid legacy' => ['settings.columns.legacy_scope_id', []],
    'duplicate columns' => ['settings.columns.legacy_scope_id', 'scope_id'],
]);

it('keeps scope column aliases separate from the other settings columns', function (string $column): void {
    config(['settings.columns.scope_id' => strtoupper($column)]);

    expect(fn () => $this->app->make(SettingsConfig::class)->validateStorageOptions())
        ->toThrow(InvalidArgumentException::class, 'settings.columns.scope_id');
})->with(['id', 'scope', 'group', 'key', 'value', 'encryption', 'revision', 'metadata', 'created_at', 'updated_at']);

it('reports the configured key if storage validation fails during the deploy check', function (): void {
    config(['settings.encryption.strict' => 'synthetic-invalid-option']);

    $this->artisan('settings:check')
        ->expectsOutput('settings.encryption.strict must be a boolean.')
        ->doesntExpectOutput('synthetic-invalid-option')
        ->assertExitCode(1);
});
