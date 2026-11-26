<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Definition;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Secret;
use Qiick\Settings\Support\DefinitionRegistry;
use SensitiveParameter;
use Throwable;

/** @internal Verifies storage against declared scopes, keys and encryption. */
final readonly class SettingsVerification
{
    public function __construct(private SettingsConfig $config, private DefinitionRegistry $registry) {}

    /** @return array{total: int, invalid: int} */
    public function inspect(): array
    {
        $definitions = $this->registry->definitions();
        $total = 0;
        $invalid = 0;

        foreach (Setting::query()->useWritePdo()->cursor() as $row) {
            $total++;

            if (! $this->isValid($row, $definitions)) {
                $invalid++;
            }
        }

        return ['total' => $total, 'invalid' => $invalid];
    }

    /** @param array<array-key, Definition> $definitions */
    public function isValid(#[SensitiveParameter] Setting $row, array $definitions, ?StringEncrypter $encrypter = null): bool
    {
        $definition = $definitions[$row->key] ?? null;

        if ($definition === null || $definition->group() !== $row->group) {
            return false;
        }

        try {
            $scope = $this->scopeOf($row);
            $value = $this->json($row, 'value');
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }

        if (! in_array($scope->scope, $definition->scopeNames(), true)) {
            return false;
        }

        if ($row->encryption === null) {
            return $value === null
                || (! $definition->isSecret() && $definition->accepts($definition->fromStoredValue($row->value)));
        }

        if (! $definition->isSecret() || $row->encryption !== Setting::LARAVEL_ENCRYPTION || ! is_string($value)) {
            return false;
        }

        try {
            if ($encrypter === null) {
                Secret::fromCiphertext($value)->reveal();
            } else {
                $encrypter->decryptString($value);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function currentEncrypter(): Encrypter
    {
        $cipher = config('app.cipher');

        if (! is_string($cipher)) {
            throw new InvalidArgumentException('app.cipher must name the Laravel cipher.');
        }

        return new Encrypter(app(Encrypter::class)->getKey(), $cipher);
    }

    public function scopeOf(#[SensitiveParameter] Setting $row): ScopeRef
    {
        $identity = $row->getRawOriginal($this->config->scopeIdColumn());

        if (! is_string($identity)) {
            throw new InvalidArgumentException('A settings row has an invalid scope identifier.');
        }

        $scope = ScopeRef::of($row->scope, $identity);

        if ($scope->id !== $identity) {
            throw new InvalidArgumentException('A settings row has a noncanonical scope identifier.');
        }

        return $scope;
    }

    public function json(#[SensitiveParameter] Setting $row, string $attribute): mixed
    {
        $raw = $row->getRawOriginal($attribute);

        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            throw new InvalidArgumentException('A settings JSON column has an invalid storage value.');
        }

        return json_decode($raw, flags: JSON_THROW_ON_ERROR);
    }
}
