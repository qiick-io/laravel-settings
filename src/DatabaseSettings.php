<?php

declare(strict_types=1);

namespace Qiick\Settings;

use BackedEnum;
use Qiick\Settings\Cache\SettingsGroupMemo;
use Qiick\Settings\Database\SettingsTable;
use SensitiveParameter;

/** @internal Implements the typed settings API on the application's database. */
final readonly class DatabaseSettings implements Settings
{
    public function __construct(
        private SettingsReader $reader,
        private SettingsWriter $writer,
        private SettingsTable $table,
        private SettingsGroupMemo $memo,
    ) {}

    public function string(string $key, ?ScopeRef $scope = null): ?string
    {
        $value = $this->reader->value($key, $scope ?? ScopeRef::instance(), 'string');

        return is_string($value) ? $value : null;
    }

    public function int(string $key, ?ScopeRef $scope = null): ?int
    {
        $value = $this->reader->value($key, $scope ?? ScopeRef::instance(), 'int');

        return is_int($value) ? $value : null;
    }

    public function bool(string $key, ?ScopeRef $scope = null): bool
    {
        return $this->reader->value($key, $scope ?? ScopeRef::instance(), 'bool') === true;
    }

    public function float(string $key, ?ScopeRef $scope = null): ?float
    {
        $value = $this->reader->value($key, $scope ?? ScopeRef::instance(), 'float');

        return is_float($value) ? $value : null;
    }

    public function array(string $key, ?ScopeRef $scope = null): ?array
    {
        $value = $this->reader->value($key, $scope ?? ScopeRef::instance(), 'json');

        return is_array($value) ? $value : null;
    }

    public function enum(string $key, string $enum, ?ScopeRef $scope = null): ?BackedEnum
    {
        $value = $this->reader->value($key, $scope ?? ScopeRef::instance(), 'enum', $enum);

        return $value instanceof $enum ? $value : null;
    }

    public function secret(string $key, ?ScopeRef $scope = null): ?Secret
    {
        return $this->reader->secret($key, $scope ?? ScopeRef::instance());
    }

    public function all(string $group, ?ScopeRef $scope = null): array
    {
        return $this->reader->group($group, $scope ?? ScopeRef::instance(), withSecretFlags: false);
    }

    public function forEdit(string $group, ?ScopeRef $scope = null): array
    {
        return $this->reader->group($group, $scope ?? ScopeRef::instance(), withSecretFlags: true);
    }

    public function revision(string $group, ?ScopeRef $scope = null): int
    {
        $scope ??= ScopeRef::instance();

        // Edit values read after the revision must come from a fresh read.
        $this->memo->forget($scope, $group);

        return $this->table->revision($scope, $group);
    }

    public function set(#[SensitiveParameter] array $values, ?ScopeRef $scope = null, ?int $expectedRevision = null): void
    {
        $this->writer->set($values, $scope ?? ScopeRef::instance(), $expectedRevision);
    }

    public function forget(string $key, ?ScopeRef $scope = null): void
    {
        $this->set([$key => null], $scope);
    }
}
