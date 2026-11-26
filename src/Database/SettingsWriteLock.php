<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use Illuminate\Database\Connection;
use Qiick\Settings\ScopeRef;
use RuntimeException;

/** @internal Serializes mutations of each group even before its first setting exists. */
final class SettingsWriteLock
{
    public const string TABLE = 'settings_write_locks';

    /** @param list<string> $groups */
    public function acquire(Connection $connection, ScopeRef $scope, array $groups): void
    {
        $buckets = [];

        foreach ($groups as $group) {
            $bucket = crc32(serialize([$scope->scope, $scope->id, $group])) & 63;
            $buckets[$bucket] = $bucket;
        }

        sort($buckets, SORT_NUMERIC);

        if ($connection->getDriverName() === 'sqlite') {
            // A first write reserves the SQLite database as BEGIN IMMEDIATE
            // would, including when Laravel joins a caller's transaction.
            $locked = $connection->table(self::TABLE)
                ->whereIn('id', $buckets)
                ->update(['id' => $connection->raw('id')]);

            if ($locked !== count($buckets)) {
                throw new RuntimeException('The settings write lock rows are missing.');
            }

            return;
        }

        foreach ($buckets as $bucket) {
            $row = $connection->table(self::TABLE)->where('id', $bucket);

            if ($row->lockForUpdate()->value('id') === null) {
                throw new RuntimeException('A settings write lock row is missing.');
            }

            // Under snapshot isolation, PostgreSQL must detect transactions
            // whose snapshots predate the preceding writer's lock.
            $row->update(['id' => $bucket]);
        }
    }
}
