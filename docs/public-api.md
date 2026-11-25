# Public API

## Classes

Laravel discovers the package's provider through Composer.

- `Qiick\Settings\Definition` (class)
- `Qiick\Settings\ScopeRef` (class)
- `Qiick\Settings\ScopeRegistry` (class)
- `Qiick\Settings\Secret` (class)
- `Qiick\Settings\Settings` (interface)
- `Qiick\Settings\SettingsSchema` (interface)
- `Qiick\Settings\SettingTypeMismatch` (class)
- `Qiick\Settings\StaleSetting` (class)
- `Qiick\Settings\UndecryptableSetting` (class)
- `Qiick\Settings\Testing\ArraySettingsSchema` (class)

`ScopeRef` exposes its scope name and identifier as readonly strings. Use `instance()` for instance settings, `organisation($uuid)` for an organisation, and `user($user)` for a user. An organisation UUID is normalized to lowercase.

Register a custom scope on the application's `ScopeRegistry` before passing its name to `ScopeRef::of()`. An instance-like scope uses the empty identifier. Other identifiers must fit in the 36-character scope ID column. Scope names and identifiers exclude trailing spaces to preserve identity across database engines.

Declare types with `Definition::bool()`, `int()`, `float()`, `string()`, `json()` or `enum()`. The fluent methods `in()`, `scopes()`, `default()` and `secret()` return a new definition. Defaults are described in the [README](../README.md#declaring-settings).

Tag `SettingsSchema` implementations with `SettingsSchema::class` in the Laravel container. The combined schemas can declare a key once and must use registered scopes.

`Secret::reveal()` returns plaintext to the caller. Normal string, JSON, debugger and PHP serialization representations redact the value.

`Settings::revision($group, $scope)` returns the maximum revision stored for the group in that scope. An empty group starts at zero. Writes and clears advance the revision monotonically. Pass the last revision read to `set()` when saving a group edit. A conditional write accepts one group. A stale revision raises `StaleSetting`.

## Configuration

Publish `config/settings.php` with the `settings-config` tag.

- `settings.migrations.load` controls whether Laravel discovers the package migrations. Defaults to `true`.
- `settings.columns.scope_id` names the canonical string scope identifier column. Defaults to `scope_id`.
- `settings.columns.legacy_scope_id` names an optional numeric identifier column to keep in sync. Defaults to `null`.
- `settings.encryption.strict` controls whether `secret()` throws `UndecryptableSetting` on invalid ciphertext. Defaults to `true`. When false, an undecryptable secret is treated as absent.

## Commands

- `settings:check` validates configuration and tagged schemas, then checks for the storage tables. It exits nonzero on failure.

## Storage

When `settings.migrations.load` is enabled, the package creates the `settings` and `settings_write_locks` tables. See the [reference schema](reference-schema.md).

## Deprecation policy

Starting with 1.0.0, public APIs are versioned using Semantic Versioning. A deprecation in a minor release includes a migration path in the upgrade guide and changelog. The deprecated API remains available until the next major release. Classes marked `@internal` may change as the implementation evolves.
