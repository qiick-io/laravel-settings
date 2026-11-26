# Upgrading

This package has yet to publish a release.

Before the first stable release, schema changes can update a table's original create migration. Read the upgrade notes for each pre-release to see whether it requires recreating the database. After 1.0.0, released migrations are immutable.

## Adopting settings in externa 1.x

Adopt the package after its first stable release.

Keep the existing `settings` table and set `settings.migrations.load` to `false`. Use `scope_ref` as `settings.columns.scope_id` and `scope_id` as `settings.columns.legacy_scope_id`. Register `project` as an instance-like scope. The package writes the canonical string reference and keeps numeric legacy IDs in sync.

Use an additive product migration for the new settings columns and the internal `settings_write_locks` table. Seed that table with IDs 0 through 63 before enabling settings writes. Preserve the existing scope reference uniqueness and follow the product's encrypted-value backfill and verification plan. See the [reference schema](docs/reference-schema.md).
