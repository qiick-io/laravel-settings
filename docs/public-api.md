# Public API

## Classes

Laravel discovers the package's provider through Composer.

- `Qiick\Settings\Definition` (class)
- `Qiick\Settings\ScopeRef` (class)
- `Qiick\Settings\ScopeRegistry` (class)
- `Qiick\Settings\SettingsSchema` (interface)
- `Qiick\Settings\Testing\ArraySettingsSchema` (class)

`ScopeRef` exposes its scope name and identifier as readonly strings. Use `instance()` for instance settings, `organisation($uuid)` for an organisation, and `user($user)` for a user. An organisation UUID is normalized to lowercase.

Register a custom scope on the application's `ScopeRegistry` before passing its name to `ScopeRef::of()`. An instance-like scope uses the empty identifier. Other identifiers must fit in the 36-character scope ID column. Scope names and identifiers exclude trailing spaces to preserve identity across database engines.

Declare types with `Definition::bool()`, `int()`, `float()`, `string()`, `json()` or `enum()`. The fluent methods `in()`, `scopes()`, `default()` and `secret()` return a new definition. Defaults are described in the [README](../README.md#declaring-settings).

Tag `SettingsSchema` implementations with `SettingsSchema::class` in the Laravel container. The combined schemas can declare a key once and must use registered scopes.

## Configuration

Publish `config/settings.php` with the `settings-config` tag.

- `settings.migrations.load` controls whether Laravel discovers the package migrations. Defaults to `true`.

## Commands

- `settings:check` validates configuration and tagged schemas, then checks that the `settings` table can be inspected. It exits nonzero on failure.

## Storage

The package creates the `settings` table when `settings.migrations.load` is enabled. Each setting has a unique natural key of `scope`, `scope_id`, `group` and `key`. The internal Eloquent model handles the stored JSON and stays outside the public API.

## Deprecation policy

Starting with 1.0.0, public APIs are versioned using Semantic Versioning. A deprecation in a minor release includes a migration path in the upgrade guide and changelog. The deprecated API remains available until the next major release. Classes marked `@internal` may change as the implementation evolves.
