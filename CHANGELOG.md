# Changelog

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The README has the qiick banner, a threat model and Packagist install instructions, and `docs/standards-traceability.md` maps the design's standards to the code and tests.

## [0.1.0-beta.1] - 2026-11-26

First beta of `qiick/laravel-settings`. Public APIs and schema can change before 1.0.0.

### Upgrade notes

- Install the beta using its tagged version, register the application's settings schemas, and run `php artisan migrate`, `php artisan settings:check` and `php artisan settings:verify`.
- The package creates `settings` and seeds `settings_write_locks`. Products adopting an existing `settings` table should follow [UPGRADING.md](UPGRADING.md#010-beta1) before migrating.
- Restore backups only into an empty `settings` table. The application's current `APP_KEY` must match the key used to export the archive.

### Added

- Composer package scaffold, Laravel discovery, configuration publishing and the `settings:check` deploy command.
- Shared compatibility matrix, four database targets and per-class mutation testing.
- The `settings` table with a non-null natural key, JSON columns, revision and encryption metadata.
- The internal Eloquent setting model and table availability in `settings:check`.
- Settings scope references for the instance, organisations, users and application-registered scopes.
- Tagged application schemas with typed definitions, explicit scopes and lazy defaults.
- Typed settings reads and transactional writes with monotonic group revisions.
- Laravel-encrypted secrets with strict validation and redacted representations.
- Request- and job-scoped settings memoization invalidated after commit.
- Storage verification, previous-key rotation and versioned JSON backup and restore commands.
- `SettingsUpdatedEvent` for committed group writes, with scope, group and key names.
- Fleet `SettingsEraser` for user-scoped rows and memo entries, with dry-run reporting.

### Fixed

- Resolve tagged schemas once per request or job and preserve the cause of schema failures.
- Memoize declared values in groups that contain orphaned settings rows.

[Unreleased]: https://github.com/qiick-io/laravel-settings/compare/v0.1.0-beta.1...develop
[0.1.0-beta.1]: https://github.com/qiick-io/laravel-settings/compare/830363d05e59b6f113ea639bb4df1e5d9119461e...v0.1.0-beta.1
