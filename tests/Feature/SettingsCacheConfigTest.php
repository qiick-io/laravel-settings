<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Laravel\SettingsServiceProvider;
use Qiick\Settings\SettingsCache;

it('publishes and reads defaults for cache configuration', function (): void {
    expect(config('settings.cache'))->toBe(['store' => null, 'ttl' => 3600, 'prefix' => 'settings']);
    config(['settings.cache' => []]);
    $options = app(SettingsConfig::class);

    expect($options->cacheStore())->toBeNull()
        ->and($options->cacheTtl())->toBe(3600)
        ->and($options->cachePrefix())->toBe('settings');

    config(['settings.cache.ttl' => 1]);
    expect($options->cacheTtl())->toBe(1);
});

it('names invalid cache options at boot', function (string $key, mixed $value): void {
    config([$key => $value]);

    expect(fn () => $this->app->call([new SettingsServiceProvider($this->app), 'boot']))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    ['settings.cache.store', 42],
    ['settings.cache.store', ' '],
    ['settings.cache.ttl', '60'],
    ['settings.cache.ttl', 0],
    ['settings.cache.prefix', null],
    ['settings.cache.prefix', ' '],
]);

it('reports an invalid cache configuration during the deploy check', function (): void {
    config(['settings.cache.ttl' => 0]);

    $this->artisan('settings:check')->expectsOutput('settings.cache.ttl must be a positive integer.')->assertExitCode(1);
});

it('uses Laravel cache stores for shared snapshots', function (): void {
    $cache = app(SettingsCache::class);
    $cache->put('default-test', 'default payload', 30);
    expect(Cache::store('array')->get('default-test'))->toBe('default payload');
    config(['cache.stores.settings-probe' => ['driver' => 'array'], 'settings.cache.store' => 'settings-probe']);
    $cache->put('settings-test', 'payload', 30);

    expect($cache->get('settings-test'))->toBe('payload')
        ->and(Cache::store('settings-probe')->get('settings-test'))->toBe('payload')
        ->and(Cache::store('array')->get('settings-test'))->toBeNull();
    $cache->forget('settings-test');
    expect($cache->get('settings-test'))->toBeNull();
    Cache::store('settings-probe')->put('settings-test', false, 30);
    expect($cache->get('settings-test'))->toBeNull();
});
