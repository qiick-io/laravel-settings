<?php

declare(strict_types=1);

namespace Qiick\Settings;

use BackedEnum;
use SensitiveParameter;

/** @api Reads and writes declared settings for an application scope. */
interface Settings
{
    public function string(string $key, ?ScopeRef $scope = null): ?string;

    public function int(string $key, ?ScopeRef $scope = null): ?int;

    public function bool(string $key, ?ScopeRef $scope = null): bool;

    public function float(string $key, ?ScopeRef $scope = null): ?float;

    /** @return array<array-key, mixed>|null */
    public function array(string $key, ?ScopeRef $scope = null): ?array;

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public function enum(string $key, string $enum, ?ScopeRef $scope = null): ?BackedEnum;

    public function secret(string $key, ?ScopeRef $scope = null): ?Secret;

    /** @return array<array-key, mixed> Declared settings with stored values over defaults. */
    public function all(string $group, ?ScopeRef $scope = null): array;

    /** @return array<array-key, mixed> Editable settings and boolean presence flags for secrets. */
    public function forEdit(string $group, ?ScopeRef $scope = null): array;

    /** Returns the group's greatest stored revision, or zero for an empty group. */
    public function revision(string $group, ?ScopeRef $scope = null): int;

    /**
     * Writes values in one transaction, optionally matching the current group revision.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function set(#[SensitiveParameter] array $values, ?ScopeRef $scope = null, ?int $expectedRevision = null): void;

    /** Clears a setting while retaining its row and advancing the group revision. */
    public function forget(string $key, ?ScopeRef $scope = null): void;
}
