<?php

declare(strict_types=1);

namespace Qiick\Settings\Cache;

use Illuminate\Database\Eloquent\Collection;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Database\SettingsTable;
use Qiick\Settings\Definition;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Secret;
use Qiick\Settings\SettingsCache;
use Qiick\Settings\Support\DefinitionRegistry;
use Throwable;

/**
 * Memoizes safe, committed snapshots for the current request or job.
 *
 * @internal
 *
 * @phpstan-type SnapshotRow array{key: string, value: ?string, encryption: ?string}
 * @phpstan-type Version array{revision: int, identity: string}
 */
final class SettingsGroupCache
{
    /** @var array<string, Collection<array-key, Setting>> */
    private array $memo = [];

    public function __construct(
        private readonly SettingsTable $table,
        private readonly SettingsCache $cache,
        private readonly SettingsConfig $config,
        private readonly DefinitionRegistry $definitions,
    ) {}

    public function revision(ScopeRef $scope, string $group): int
    {
        // Edit values read after the revision must not come from an older memo.
        unset($this->memo[$this->groupIdentity($scope, $group)]);

        return $this->table->revision($scope, $group);
    }

    /** @return Collection<array-key, Setting> */
    public function rows(ScopeRef $scope, string $group): Collection
    {
        if ($this->table->connection()->transactionLevel() > 0) {
            return $this->table->group($scope, $group)->get()->keyBy('key');
        }

        $identity = $this->groupIdentity($scope, $group);

        if (isset($this->memo[$identity])) {
            return $this->memo[$identity];
        }

        $version = $this->table->cacheVersion($scope, $group);
        $key = $this->cacheKey($identity, $version);
        $cached = $this->read($key);
        $snapshot = $cached === null ? null : $this->snapshot($cached, $scope, $group);

        if ($snapshot !== null) {
            return $this->memo[$identity] = $this->hydrate($snapshot);
        }

        $rows = $this->table->recentFirst($scope, $group)->get();
        $snapshot = $this->snapshot($rows, $scope, $group);

        // A commit between the two database queries can change the snapshot.
        if ($snapshot === null || $this->table->versionOf($rows->first()) !== $version) {
            return $rows->keyBy('key');
        }

        try {
            $this->cache->put($key, json_encode($snapshot, JSON_THROW_ON_ERROR), $this->config->cacheTtl());
        } catch (Throwable) {
            // A failed cache write does not affect the database result.
        }

        return $this->memo[$identity] = $this->hydrate($snapshot);
    }

    /** @param Version $version */
    public function forget(ScopeRef $scope, string $group, array $version): void
    {
        $identity = $this->groupIdentity($scope, $group);
        unset($this->memo[$identity]);

        try {
            $this->cache->forget($this->cacheKey($identity, $version));
        } catch (Throwable) {
            // The committed database version still prevents a stale hit.
        }
    }

    /** @return Collection<array-key, Setting>|null */
    private function read(string $key): ?Collection
    {
        try {
            $payload = $this->cache->get($key);

            if ($payload === null) {
                return null;
            }

            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $rows = [];

        foreach ($decoded as $fields) {
            if (! is_array($fields) || ! is_string($fields['key'] ?? null)
                || ! array_key_exists('value', $fields) || ! array_key_exists('encryption', $fields)) {
                return null;
            }

            $value = $fields['value'];
            $encryption = $fields['encryption'];

            if (($value !== null && ! is_string($value)) || ($encryption !== null && ! is_string($encryption))) {
                return null;
            }

            $rows[] = ['key' => $fields['key'], 'value' => $value, 'encryption' => $encryption];
        }

        return $this->hydrate($rows);
    }

    /**
     * @param  Collection<array-key, Setting>  $rows
     * @return list<SnapshotRow>|null
     */
    private function snapshot(Collection $rows, ScopeRef $scope, string $group): ?array
    {
        $definitions = $this->definitions->definitions();
        $snapshot = [];

        foreach ($rows as $row) {
            $definition = $definitions[$row->key] ?? null;

            if ($definition === null || $definition->group() !== $group || ! in_array($scope->scope, $definition->scopeNames(), true)) {
                continue;
            }

            $value = $row->getRawOriginal('value');

            if (($value !== null && ! is_string($value)) || ! $this->canCache($row, $definition, $value)) {
                return null;
            }

            $snapshot[] = ['key' => $row->key, 'value' => $value, 'encryption' => $row->encryption];
        }

        return $snapshot;
    }

    private function canCache(Setting $row, Definition $definition, ?string $rawValue): bool
    {
        if (! $definition->isSecret() || $rawValue === null) {
            return true;
        }

        if ($row->encryption !== Setting::LARAVEL_ENCRYPTION || ! is_string($row->value)) {
            return false;
        }

        try {
            // Reject raw plaintext even when it was given an encryption marker.
            Secret::fromCiphertext($row->value)->reveal();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<SnapshotRow>  $snapshot
     * @return Collection<array-key, Setting>
     */
    private function hydrate(array $snapshot): Collection
    {
        $rows = new Collection;

        foreach ($snapshot as $fields) {
            $rows->put($fields['key'], (new Setting)->setRawAttributes($fields, sync: true));
        }

        return $rows;
    }

    private function groupIdentity(ScopeRef $scope, string $group): string
    {
        return hash('sha256', serialize([$scope->scope, $scope->id, $group]));
    }

    /** @param Version $version */
    private function cacheKey(string $identity, array $version): string
    {
        $rowIdentity = hash('sha256', $version['identity']);

        return sprintf('%s:%s:%d:%s', $this->config->cachePrefix(), $identity, $version['revision'], $rowIdentity);
    }
}
