<?php

declare(strict_types=1);

namespace Qiick\Settings\Support;

/** @internal Validates identifiers that must have the same identity on every database engine. */
final class StorageIdentifier
{
    public static function isValid(string $identifier, int $maximumLength): bool
    {
        // MySQL and MariaDB's binary collation can ignore trailing spaces.
        return preg_match('/\A[^\x00]{1,'.$maximumLength.'}\z/u', $identifier) === 1
            && ! str_ends_with($identifier, ' ');
    }
}
