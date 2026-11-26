<?php

declare(strict_types=1);

namespace Qiick\Settings;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Qiick\Settings\Support\StorageIdentifier;

/** @api An immutable reference to the owner of a settings group. */
final readonly class ScopeRef
{
    private function __construct(
        public string $scope,
        public string $id,
    ) {}

    public static function instance(): self
    {
        return new self('instance', '');
    }

    public static function organisation(string $uuid): self
    {
        if (! Str::isUuid($uuid)) {
            throw new InvalidArgumentException('An organisation settings scope requires a UUID.');
        }

        return new self('organisation', strtolower($uuid));
    }

    public static function user(Authenticatable $user): self
    {
        $id = $user->getAuthIdentifier();

        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException('A user settings scope requires a user key.');
        }

        return self::identified('user', (string) $id);
    }

    /**
     * Resolves registered scopes through the current Laravel application.
     */
    public static function of(string $scope, string $id): self
    {
        if (app(ScopeRegistry::class)->isInstanceLike($scope)) {
            if ($id !== '') {
                throw new InvalidArgumentException('An instance-like settings scope requires an empty identifier.');
            }

            return new self($scope, '');
        }

        if ($scope === 'organisation') {
            return self::organisation($id);
        }

        return self::identified($scope, $id);
    }

    private static function identified(string $scope, string $id): self
    {
        if (! StorageIdentifier::isValid($id, 36)) {
            throw new InvalidArgumentException('A settings scope identifier must fit in the scope ID column.');
        }

        return new self($scope, $id);
    }
}
