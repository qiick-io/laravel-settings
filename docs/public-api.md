# Public API

## Classes

The scaffold exposes no classes as application extension points. Laravel discovers the package's provider through Composer.

## Configuration

Publish `config/settings.php` with the `settings-config` tag.

- `settings.migrations.load` controls whether Laravel discovers the package migrations. Defaults to `true`.

## Commands

- `settings:check` checks the package for deployment and exits nonzero when it finds an error.

## Deprecation policy

Starting with 1.0.0, public APIs are versioned using Semantic Versioning. A deprecation in a minor release includes a migration path in the upgrade guide and changelog. The deprecated API remains available until the next major release. Classes marked `@internal` may change as the implementation evolves.
