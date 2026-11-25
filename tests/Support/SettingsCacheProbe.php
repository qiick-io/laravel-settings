<?php

declare(strict_types=1);

namespace Qiick\Settings\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\SettingsCache;
use RuntimeException;

final class SettingsCacheProbe implements SettingsCache
{
    /** @var array<string, string> */
    public array $entries = [];

    /** @var list<int> */
    public array $lifetimes = [];

    /** @var list<string> */
    public array $reads = [];

    /** @var list<array{key: string, transaction: int}> */
    public array $deletions = [];

    public bool $isUnavailable = false;

    public ?Closure $beforeRead = null;

    public function get(string $key): ?string
    {
        $this->reads[] = $key;
        $this->beforeRead?->__invoke();

        if ($this->isUnavailable) {
            throw new RuntimeException('Cache unavailable.');
        }

        return $this->entries[$key] ?? null;
    }

    public function put(string $key, string $payload, int $seconds): void
    {
        if ($this->isUnavailable) {
            throw new RuntimeException('Cache unavailable.');
        }

        $this->entries[$key] = $payload;
        $this->lifetimes[] = $seconds;
    }

    public function forget(string $key): void
    {
        $this->deletions[] = ['key' => $key, 'transaction' => DB::transactionLevel()];

        if ($this->isUnavailable) {
            throw new RuntimeException('Cache unavailable.');
        }

        unset($this->entries[$key]);
    }
}
