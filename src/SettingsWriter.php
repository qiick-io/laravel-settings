<?php

declare(strict_types=1);

namespace Qiick\Settings;

use BackedEnum;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
use OverflowException;
use Qiick\Settings\Cache\SettingsGroupCache;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Database\SettingsTable;
use Qiick\Settings\Database\SettingsWriteLock;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\Support\DefinitionRegistry;
use SensitiveParameter;

/** @internal Validates and stores a batch with one new revision per changed group. */
final readonly class SettingsWriter
{
    public function __construct(
        private SettingsConfig $config,
        private SettingsTable $table,
        private SettingsWriteLock $locks,
        private DefinitionRegistry $definitions,
        private SettingsGroupCache $cache,
    ) {}

    /** @param array<array-key, mixed> $values */
    public function set(#[SensitiveParameter] array $values, ScopeRef $scope, ?int $expectedRevision): void
    {
        $prepared = $this->prepare($values, $scope);

        if ($expectedRevision !== null && count($prepared) !== 1) {
            throw new InvalidArgumentException('An expected revision requires exactly one settings group.');
        }

        if ($prepared === []) {
            return;
        }

        $groups = array_map(strval(...), array_keys($prepared));
        $connection = $this->table->connection();
        $connection->transaction(function () use ($connection, $scope, $groups, $prepared, $expectedRevision): void {
            $this->locks->acquire($connection, $scope, $groups);
            $invalidate = [];

            foreach ($groups as $group) {
                $version = $this->table->lockedVersion($scope, $group);
                $current = $version['revision'];

                if ($expectedRevision !== null && $expectedRevision !== $current) {
                    throw new StaleSetting('The settings group revision changed.');
                }

                if ($current >= 2147483647) {
                    throw new OverflowException('The settings group revision reached its storage limit.');
                }

                $this->write($connection, $scope, $group, $prepared[$group], $current + 1);
                $invalidate[] = ['group' => $group, 'version' => $version];
            }

            $connection->afterCommit(function () use ($scope, $invalidate): void {
                foreach ($invalidate as $changed) {
                    $this->cache->forget($scope, $changed['group'], $changed['version']);
                }
            });
        }, attempts: 3);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, list<array{key: string, value: ?string, encryption: ?string}>>
     */
    private function prepare(#[SensitiveParameter] array $values, ScopeRef $scope): array
    {
        $definitions = $this->definitions->definitions();
        $prepared = [];

        foreach ($values as $key => $value) {
            $definition = $definitions[$key] ?? null;

            if ($definition === null || ! in_array($scope->scope, $definition->scopeNames(), true)) {
                throw new InvalidArgumentException('A settings key is undeclared or disallowed in this scope.');
            }

            $prepared[$definition->group()][] = ['key' => $definition->key, ...$this->encode($definition, $value)];
        }

        return $prepared;
    }

    /** @return array{value: ?string, encryption: ?string} */
    private function encode(Definition $definition, #[SensitiveParameter] mixed $value): array
    {
        if ($value === null) {
            return ['value' => null, 'encryption' => null];
        }

        if (! $definition->accepts($value)) {
            throw new InvalidArgumentException("Setting {$definition->key} does not match its declared type.");
        }

        $encryption = null;

        if ($definition->isSecret() && is_string($value)) {
            $value = app(StringEncrypter::class)->encryptString($value);
            $encryption = Setting::LARAVEL_ENCRYPTION;
        } elseif ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        try {
            return [
                'value' => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'encryption' => $encryption,
            ];
        } catch (JsonException) {
            throw new InvalidArgumentException("Setting {$definition->key} cannot be encoded as JSON.");
        }
    }

    /** @param list<array{key: string, value: ?string, encryption: ?string}> $values */
    private function write(Connection $connection, ScopeRef $scope, string $group, array $values, int $revision): void
    {
        $scopeColumn = $this->config->scopeIdColumn();
        $legacyColumn = $this->config->legacyScopeIdColumn();
        $timestamp = Date::now('UTC')->toDateTimeString();
        $rows = [];
        $updatedColumns = ['value', 'encryption', 'revision', 'updated_at'];

        if ($legacyColumn !== null) {
            $updatedColumns[] = $legacyColumn;
        }

        foreach ($values as $value) {
            $row = [
                'scope' => $scope->scope,
                $scopeColumn => $scope->id,
                'group' => $group,
                'key' => $value['key'],
                'value' => $value['value'],
                'encryption' => $value['encryption'],
                'revision' => $revision,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if ($legacyColumn !== null) {
                $row[$legacyColumn] = ctype_digit($scope->id) ? $scope->id : null;
            }

            $rows[] = $row;
        }

        $connection->table(Setting::TABLE)->upsert($rows, ['scope', $scopeColumn, 'group', 'key'], $updatedColumns);
    }
}
