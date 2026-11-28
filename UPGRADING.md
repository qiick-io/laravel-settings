# Upgrading

Public APIs can change during the 0.x pre-releases. Review the notes for the version you are installing or upgrading to.

Before the first stable release, schema changes can update a table's original create migration. Read the upgrade notes for each pre-release to see whether it requires recreating the database. After 1.0.0, released migrations are immutable.

## 0.1.0-beta.1 to 0.1.0-beta.2

No public API, configuration or schema change since `0.1.0-beta.1`: an application on the beta needs no database refresh. Install from Packagist (`composer require qiick/laravel-settings:^0.1@beta`, which resolves `0.1.0-beta.2`) and remove the `repositories.qiick-settings` VCS entry the first beta needed.

Wire `Qiick\Settings\Erasure\SettingsEraser` into the product's erasure orchestrator for full user erasures (see the README). Products that keep their own settings storage until adoption move it to the package before the stable release.

## 0.1.0-beta.1

This is the first release. On a fresh database, install the package, register the application's `SettingsSchema` implementations, and run `php artisan migrate`. The migrations create the `settings` table and seed all 64 write-lock rows. Run `php artisan settings:check` and `php artisan settings:verify` before deploying.

For a pre-release product adopting the package schema, move the existing definitions, defaults and secret declarations into tagged `SettingsSchema` implementations. Move storage calls to `Settings::set()` and the typed readers. Keep the product's authorization policies and domain methods in the product.

Back up the existing settings before replacing a product-owned create migration. A database built from the package migrations must contain only one create migration for the `settings` table. Move settings from the old schema using the product's migration plan. If the old store already works with `settings:export`, run it before replacing the schema and restore the archive into the empty `settings` table under the same `APP_KEY`. Run `settings:import <file> --dry-run` before restoring, then verify the restored rows.

## Adopting settings in externa 1.x

Adopt the package after its first stable release.

Keep the existing `settings` table and set `settings.migrations.load` to `false`. Use `scope_ref` as `settings.columns.scope_id` and `scope_id` as `settings.columns.legacy_scope_id`. Register `project` as an instance-like scope. The package writes the canonical string reference and keeps numeric legacy IDs in sync.

Use an additive product migration for the new settings columns and the internal `settings_write_locks` table. Seed that table with IDs 0 through 63 before enabling settings writes. Preserve the existing scope reference uniqueness and follow the product's encrypted-value backfill and verification plan. See the [reference schema](docs/reference-schema.md).
