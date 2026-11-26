<?php

declare(strict_types=1);

namespace Qiick\Settings;

use Illuminate\Contracts\Debug\ExceptionHandler;
use InvalidArgumentException;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Database\SettingsTable;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\Support\DefinitionRegistry;
use SensitiveParameter;
use TypeError;

/** @internal Resolves stored values and defaults without exposing secret settings. */
final readonly class SettingsReader
{
    public function __construct(
        private DefinitionRegistry $definitions,
        private SettingsTable $table,
        private SettingsConfig $config,
        private ExceptionHandler $reporter,
    ) {}

    public function value(string $key, ScopeRef $scope, string $type, ?string $enum = null): mixed
    {
        $definition = $this->definition($key, $scope);

        if ($definition->isSecret() || $definition->type !== $type || $definition->enumClass !== $enum) {
            throw new InvalidArgumentException("Setting {$key} cannot be read as {$type}.");
        }

        return $this->typedValue($definition, $this->row($scope, $definition));
    }

    public function secret(string $key, ScopeRef $scope): ?Secret
    {
        $definition = $this->definition($key, $scope);

        if (! $definition->isSecret()) {
            throw new InvalidArgumentException("Setting {$key} cannot be read as a secret.");
        }

        $row = $this->row($scope, $definition);

        if ($row === null || $row->value === null) {
            return null;
        }

        try {
            if ($row->encryption !== Setting::LARAVEL_ENCRYPTION || ! is_string($row->value)) {
                throw new UndecryptableSetting('The settings secret has invalid storage.');
            }

            $secret = Secret::fromCiphertext($row->value);
            $secret->reveal();

            return $secret;
        } catch (UndecryptableSetting $exception) {
            if ($this->config->shouldUseStrictEncryption()) {
                throw $exception;
            }

            return null;
        }
    }

    /** @return array<array-key, mixed> */
    public function group(string $group, ScopeRef $scope, bool $withSecretFlags): array
    {
        $rows = $this->table->group($scope, $group)->get()->keyBy('key');
        $values = [];
        $flags = [];

        foreach ($this->definitions->definitions() as $definition) {
            if ($definition->group() !== $group || ! in_array($scope->scope, $definition->scopeNames(), true)) {
                continue;
            }

            $row = $rows->get($definition->key);

            if ($definition->isSecret()) {
                if ($withSecretFlags) {
                    $flags['has_'.$definition->key] = $row?->encryption === Setting::LARAVEL_ENCRYPTION
                        && is_string($row->value);
                }

                continue;
            }

            $values[$definition->key] = $this->typedValue($definition, $row);
        }

        return array_replace($values, $flags);
    }

    private function definition(string $key, ScopeRef $scope): Definition
    {
        $definition = $this->definitions->definitions()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Setting {$key} is not declared.");
        }

        if (! in_array($scope->scope, $definition->scopeNames(), true)) {
            throw new InvalidArgumentException("Setting {$key} is not declared for scope {$scope->scope}.");
        }

        return $definition;
    }

    private function row(ScopeRef $scope, Definition $definition): ?Setting
    {
        return $this->table->group($scope, $definition->group())->where('key', $definition->key)->first();
    }

    private function typedValue(Definition $definition, #[SensitiveParameter] ?Setting $row): mixed
    {
        if ($row === null || $row->value === null) {
            return $definition->defaultValue();
        }

        $value = $row->value;
        $enum = $definition->enumClass;

        if ($enum !== null && (is_int($value) || is_string($value))) {
            try {
                $value = $enum::tryFrom($value);
            } catch (TypeError) {
                $value = null;
            }
        }

        if ($row->encryption !== null || ! $definition->accepts($value)) {
            $this->reporter->report(new SettingTypeMismatch("Stored setting {$definition->key} does not match its declared type."));

            return $definition->defaultValue();
        }

        return $value;
    }
}
