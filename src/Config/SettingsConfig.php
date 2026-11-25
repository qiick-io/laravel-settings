<?php

declare(strict_types=1);

namespace Qiick\Settings\Config;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal Reads and validates package options from Laravel configuration. */
final readonly class SettingsConfig
{
    public const string DEFAULT_SCOPE_ID_COLUMN = 'scope_id';

    public const string DEFAULT_CACHE_PREFIX = 'settings';

    public function __construct(private Repository $config) {}

    public static function defaultMigrationLoading(): bool
    {
        return true;
    }

    public function shouldLoadMigrations(): bool
    {
        return $this->boolean('settings.migrations.load', self::defaultMigrationLoading());
    }

    public static function defaultStrictEncryption(): bool
    {
        return true;
    }

    public function shouldUseStrictEncryption(): bool
    {
        return $this->boolean('settings.encryption.strict', self::defaultStrictEncryption());
    }

    /** @return non-empty-string */
    public function scopeIdColumn(): string
    {
        return $this->column('settings.columns.scope_id', self::DEFAULT_SCOPE_ID_COLUMN);
    }

    public function legacyScopeIdColumn(): ?string
    {
        if ($this->config->get('settings.columns.legacy_scope_id') === null) {
            return null;
        }

        $legacy = $this->column('settings.columns.legacy_scope_id');

        if (strcasecmp($legacy, $this->scopeIdColumn()) === 0) {
            throw new InvalidArgumentException('settings.columns.legacy_scope_id must differ from settings.columns.scope_id.');
        }

        return $legacy;
    }

    public function validateStorageOptions(): void
    {
        $this->scopeIdColumn();
        $this->legacyScopeIdColumn();
        $this->shouldUseStrictEncryption();
    }

    public static function defaultCacheTtl(): int
    {
        return 3600;
    }

    public function cacheStore(): ?string
    {
        $store = $this->config->get('settings.cache.store');

        if ($store !== null && (! is_string($store) || trim($store) === '')) {
            throw new InvalidArgumentException('settings.cache.store must be null or a cache store name.');
        }

        return $store;
    }

    public function cacheTtl(): int
    {
        $ttl = $this->config->get('settings.cache.ttl', self::defaultCacheTtl());

        if (! is_int($ttl) || $ttl < 1) {
            throw new InvalidArgumentException('settings.cache.ttl must be a positive integer.');
        }

        return $ttl;
    }

    public function cachePrefix(): string
    {
        $prefix = $this->config->get('settings.cache.prefix', self::DEFAULT_CACHE_PREFIX);

        if (! is_string($prefix) || trim($prefix) === '') {
            throw new InvalidArgumentException('settings.cache.prefix must be a nonempty string.');
        }

        return $prefix;
    }

    public function validateCacheOptions(): void
    {
        $this->cacheStore();
        $this->cacheTtl();
        $this->cachePrefix();
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException("{$key} must be a boolean.");
        }

        return $value;
    }

    /** @return non-empty-string */
    private function column(string $key, ?string $default = null): string
    {
        $column = $this->config->get($key, $default);

        if (! is_string($column) || $column === '' || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,62}\z/', $column) !== 1) {
            throw new InvalidArgumentException("{$key} must name a database column.");
        }

        if (in_array(strtolower($column), [
            'id', 'scope', 'group', 'key', 'value', 'encryption', 'revision', 'metadata', 'created_at', 'updated_at',
        ], true)) {
            throw new InvalidArgumentException("{$key} must differ from the other settings columns.");
        }

        return $column;
    }
}
