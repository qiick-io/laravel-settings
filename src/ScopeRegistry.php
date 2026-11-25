<?php

declare(strict_types=1);

namespace Qiick\Settings;

use InvalidArgumentException;
use Qiick\Settings\Support\StorageIdentifier;

/** @api Registers the scope names an application uses for settings. */
final class ScopeRegistry
{
    /** @var array<string, bool> */
    private array $instanceLikeScopes = [];

    public function __construct()
    {
        $this->register('instance', instanceLike: true);
        $this->register('organisation');
        $this->register('user');
    }

    public function register(string $scope, bool $instanceLike = false): void
    {
        if (! StorageIdentifier::isValid($scope, 32)) {
            throw new InvalidArgumentException('A settings scope name must fit in the scope column.');
        }

        if (isset($this->instanceLikeScopes[$scope])) {
            throw new InvalidArgumentException('A settings scope can only be registered once.');
        }

        $this->instanceLikeScopes[$scope] = $instanceLike;
    }

    /** @internal */
    public function isInstanceLike(string $scope): bool
    {
        return $this->instanceLikeScopes[$scope]
            ?? throw new InvalidArgumentException('The settings scope is not registered.');
    }
}
