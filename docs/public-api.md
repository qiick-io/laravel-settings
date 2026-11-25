# Public API

## Classes

Laravel discovers the package's provider through Composer.

## Configuration

Publish `config/settings.php` with the `settings-config` tag.

- `settings.migrations.load` controls whether Laravel discovers the package migrations. Defaults to `true`.

## Commands

- `settings:check` validates configuration and checks that the `settings` table can be inspected. It exits nonzero on failure.

## Storage

The package creates the `settings` table when `settings.migrations.load` is enabled. Each setting has a unique natural key of `scope`, `scope_id`, `group` and `key`. The internal Eloquent model handles the stored JSON and stays outside the public API.

## Deprecation policy

Starting with 1.0.0, public APIs are versioned using Semantic Versioning. A deprecation in a minor release includes a migration path in the upgrade guide and changelog. The deprecated API remains available until the next major release. Classes marked `@internal` may change as the implementation evolves.
