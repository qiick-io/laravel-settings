<?php

declare(strict_types=1);

namespace Qiick\Settings\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\SettingsCache;

/** @internal Stores group snapshots in the configured Laravel cache repository. */
final readonly class LaravelSettingsCache implements SettingsCache
{
    public function __construct(private Factory $factory, private SettingsConfig $config) {}

    public function get(string $key): ?string
    {
        $value = $this->store()->get($key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $payload, int $seconds): void
    {
        $this->store()->put($key, $payload, $seconds);
    }

    public function forget(string $key): void
    {
        $this->store()->forget($key);
    }

    private function store(): Repository
    {
        return $this->factory->store($this->config->cacheStore());
    }
}
