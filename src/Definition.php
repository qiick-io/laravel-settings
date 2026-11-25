<?php

declare(strict_types=1);

namespace Qiick\Settings;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use Qiick\Settings\Support\StorageIdentifier;
use SensitiveParameter;

/**
 * Declares one setting's type and ownership. The group is `app` and the only
 * allowed scope is `instance` unless the application chooses other values.
 *
 * JSON definitions accept lists and associative arrays. Enum definitions
 * accept instances of their declared backed enum. Persistence uses the
 * backing value. Invalid stored values use the default and report SettingTypeMismatch.
 * Secret definitions are strings with a null default and no config fallback.
 *
 * @api
 *
 * @phpstan-type SettingType 'bool'|'int'|'float'|'string'|'json'|'enum'
 */
final class Definition
{
    private string $group = 'app';

    /** @var list<string> */
    private array $scopeNames;

    private bool $isSecret;

    private mixed $defaultValue = null;

    /**
     * @param  SettingType  $type
     * @param  class-string<BackedEnum>|null  $enumClass
     */
    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly ?string $enumClass = null,
    ) {
        if (! StorageIdentifier::isValid($this->key, 191)) {
            throw new InvalidArgumentException('A settings key must fit in the key column.');
        }

        $this->scopeNames = ['instance'];
        $this->isSecret = false;
    }

    public static function bool(string $key): self
    {
        return new self($key, 'bool');
    }

    public static function int(string $key): self
    {
        return new self($key, 'int');
    }

    public static function float(string $key): self
    {
        return new self($key, 'float');
    }

    public static function string(string $key): self
    {
        return new self($key, 'string');
    }

    /** Declares a PHP list or associative array stored as JSON. */
    public static function json(string $key): self
    {
        return new self($key, 'json');
    }

    /**
     * Declares a setting whose PHP values are cases of the specified enum.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $key, string $enum): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException('A settings enum must be a backed enum.');
        }

        return new self($key, 'enum', $enum);
    }

    public function in(string $group): self
    {
        if (! StorageIdentifier::isValid($group, 64)) {
            throw new InvalidArgumentException('A settings group must fit in the group column.');
        }

        $definition = clone $this;
        $definition->group = $group;

        return $definition;
    }

    public function scopes(string ...$scopes): self
    {
        if ($scopes === []) {
            throw new InvalidArgumentException('A settings definition needs at least one scope.');
        }

        foreach ($scopes as $scope) {
            if (! StorageIdentifier::isValid($scope, 32)) {
                throw new InvalidArgumentException('A settings scope name must fit in the scope column.');
            }
        }

        $definition = clone $this;
        $definition->scopeNames = array_values($scopes);

        return $definition;
    }

    /** Marks a string setting as encrypted, with null as its only default. */
    public function secret(): self
    {
        if ($this->type !== 'string' || $this->defaultValue !== null) {
            throw new InvalidArgumentException('A secret setting must be a string with a null default.');
        }

        $definition = clone $this;
        $definition->isSecret = true;

        return $definition;
    }

    /** Supplies a literal default or a closure evaluated on demand. */
    public function default(#[SensitiveParameter] mixed $value): self
    {
        if ($this->isSecret && $value !== null) {
            throw new InvalidArgumentException('A secret setting only accepts a null default.');
        }

        if (! $value instanceof Closure) {
            $this->validateDefault($value);
        }

        $definition = clone $this;
        $definition->defaultValue = $value;

        return $definition;
    }

    public function group(): string
    {
        return $this->group;
    }

    /** @return list<string> */
    public function scopeNames(): array
    {
        return $this->scopeNames;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    /** @internal */
    public function defaultValue(): mixed
    {
        $value = $this->defaultValue;

        if ($value instanceof Closure) {
            $value = $value();
            $this->validateDefault($value);
        }

        return $value;
    }

    /** @internal */
    public function accepts(#[SensitiveParameter] mixed $value): bool
    {
        return match ($this->type) {
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => is_float($value) && is_finite($value),
            'string' => is_string($value),
            'json' => is_array($value),
            'enum' => $value instanceof BackedEnum && $value::class === $this->enumClass,
        };
    }

    private function validateDefault(#[SensitiveParameter] mixed $value): void
    {
        if ($value !== null && ! $this->accepts($value)) {
            throw new InvalidArgumentException('The settings default does not match the declared type.');
        }
    }
}
