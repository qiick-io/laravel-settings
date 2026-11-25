<?php

declare(strict_types=1);

use Qiick\Settings\Definition;

enum DefinitionStringEnum: string
{
    case Required = 'required';
}

enum DefinitionIntEnum: int
{
    case Required = 1;
}

it('defaults definitions to the app group and only the instance scope', function (): void {
    $definition = Definition::bool('two_factor_required');

    expect($definition->key)->toBe('two_factor_required')
        ->and($definition->type)->toBe('bool')
        ->and($definition->group())->toBe('app')
        ->and($definition->scopeNames())->toBe(['instance'])
        ->and($definition->defaultValue())->toBeNull()
        ->and($definition->enumClass)->toBeNull()
        ->and($definition->isSecret())->toBeFalse();
});

it('returns independent definitions from fluent configuration', function (): void {
    $original = Definition::bool('two_factor_required');
    $definition = $original->in('security')->scopes('organisation', 'user')->default(false);

    expect($definition)->not->toBe($original)
        ->and($definition->key)->toBe('two_factor_required')
        ->and($definition->type)->toBe('bool')
        ->and($definition->group())->toBe('security')
        ->and($definition->scopeNames())->toBe(['organisation', 'user'])
        ->and($definition->defaultValue())->toBeFalse()
        ->and($original->group())->toBe('app')
        ->and($original->scopeNames())->toBe(['instance'])
        ->and($original->defaultValue())->toBeNull();
});

it('keeps scope names in a list when passed as named arguments', function (): void {
    $definition = Definition::bool('enabled')->scopes(primary: 'organisation', secondary: 'user');

    expect($definition->scopeNames())->toBe(['organisation', 'user']);
});

it('resolves closure defaults only when read', function (): void {
    $calls = 0;
    $definition = Definition::json('sidebar_modules')->default(function () use (&$calls): array {
        $calls++;

        return ['overview' => true];
    });

    expect($calls)->toBe(0)
        ->and($definition->defaultValue())->toBe(['overview' => true])
        ->and($calls)->toBe(1);
});

it('rejects values of the wrong type without coercion', function (string $type, mixed $valid, mixed $invalid): void {
    $definition = Definition::{$type}('example');

    expect($definition->type)->toBe($type)
        ->and($definition->accepts($valid))->toBeTrue()
        ->and($definition->accepts($invalid))->toBeFalse()
        ->and($definition->default($valid)->defaultValue())->toBe($valid)
        ->and(fn () => $definition->default($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'bool' => ['bool', false, 0],
    'int' => ['int', 0, '0'],
    'float' => ['float', 1.0, 1],
    'nonfinite float' => ['float', 1.0, INF],
    'string' => ['string', '', false],
    'json list' => ['json', [1, true], '[]'],
    'json associative array' => ['json', ['enabled' => true], (object) ['enabled' => true]],
]);

it('checks a default closure result when it is read', function (): void {
    $definition = Definition::bool('two_factor_required')->default(fn (): int => 1);

    expect(fn () => $definition->defaultValue())->toThrow(InvalidArgumentException::class)
        ->and(Definition::int('example')->default(fn (): ?int => null)->defaultValue())->toBeNull();
});

it('accepts only instances of the declared backed enum', function (): void {
    $definition = Definition::enum('password_policy', DefinitionStringEnum::class);
    $integer = Definition::enum('password_policy', DefinitionIntEnum::class);

    expect($definition->type)->toBe('enum')
        ->and($definition->enumClass)->toBe(DefinitionStringEnum::class)
        ->and($definition->accepts(DefinitionStringEnum::Required))->toBeTrue()
        ->and($definition->accepts(DefinitionIntEnum::Required))->toBeFalse()
        ->and($definition->accepts('required'))->toBeFalse()
        ->and($integer->default(DefinitionIntEnum::Required)->defaultValue())->toBe(DefinitionIntEnum::Required)
        ->and(fn () => Definition::enum('password_policy', stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('allows secrets only for strings and rejects all nonnull defaults', function (): void {
    $original = Definition::string('oidc_client_secret');
    $secret = $original->secret();

    expect($secret->type)->toBe('string')
        ->and($secret->isSecret())->toBeTrue()
        ->and($original->isSecret())->toBeFalse()
        ->and($secret->defaultValue())->toBeNull()
        ->and($secret->default(null)->defaultValue())->toBeNull()
        ->and(fn () => $secret->default('synthetic-value'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $secret->default(fn () => null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $original->default('synthetic-value')->secret())->toThrow(InvalidArgumentException::class)
        ->and(fn () => Definition::bool('enabled')->secret())->toThrow(InvalidArgumentException::class);
});

it('checks definition identifiers against the settings schema lengths', function (): void {
    $key = str_repeat('a', 191);
    $group = str_repeat('a', 64);
    $scope = str_repeat('a', 32);

    expect(Definition::string($key)->key)->toBe($key)
        ->and(Definition::string($key)->in($group)->group())->toBe($group)
        ->and(Definition::string($key)->scopes($scope)->scopeNames())->toBe([$scope])
        ->and(fn () => Definition::bool(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Definition::bool($key.'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Definition::float($key)->in($group.'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Definition::float($key)->scopes($scope.'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Definition::float($key)->scopes())->toThrow(InvalidArgumentException::class);
});
