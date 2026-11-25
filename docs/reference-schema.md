# Reference schema

## `settings`

The package identifies a setting by `(scope, scope_id, group, key)`. The bigint row ID is internal.

| Column | Type | Default and nullability |
| --- | --- | --- |
| `id` | bigint primary key | Auto-increment |
| `scope` | string(32) | Required |
| `scope_id` | string(36) | Required, defaults to the empty string |
| `group` | string(64) | Required |
| `key` | string(191) | Required |
| `value` | JSON | Nullable. Secrets store ciphertext as a JSON string |
| `encryption` | string(16) | Nullable. `laravel-v1` identifies Laravel-encrypted values |
| `revision` | unsigned integer | Required, defaults to 1 |
| `metadata` | JSON | Nullable |
| `created_at`, `updated_at` | timestamps | Nullable, UTC |

`settings_scope_unique` covers the natural key and its `(scope, scope_id, group)` prefix supports group lookups. MySQL and MariaDB use binary collation for the natural key. Scope IDs identify different owner types and have no foreign key.

Every write assigns the next group revision to each changed key. Clearing a setting stores SQL NULL and retains its row and revision. Reads then use the declared default. The group's maximum revision advances monotonically across ordinary writes and clears.

## `settings_write_locks`

The internal table has one small unsigned integer primary key, `id`. The create migration seeds the 64 IDs from 0 through 63. These are shared lock buckets and contain no group or user data.

The writer hashes each `(scope, scope_id, group)` to a bucket, takes all relevant locks in ascending ID order, and reads the current revision in the same transaction. PostgreSQL, MySQL and MariaDB lock bucket rows with `SELECT … FOR UPDATE`. SQLite obtains the write reservation before reading group revisions, including when the writer joins an enclosing transaction.

Applications that disable package migrations supply both tables. See the [externa adoption notes](../UPGRADING.md#adopting-settings-in-externa-1x).
