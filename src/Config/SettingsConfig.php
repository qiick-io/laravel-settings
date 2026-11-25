<?php

declare(strict_types=1);

namespace Qiick\Settings\Config;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal Reads and validates package options from Laravel configuration. */
final readonly class SettingsConfig
{
    public const string DEFAULT_SCOPE_ID_COLUMN = 'scope_id';

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
