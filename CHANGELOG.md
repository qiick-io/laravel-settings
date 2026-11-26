# Changelog

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/qiick-io/laravel-settings/compare/830363d05e59b6f113ea639bb4df1e5d9119461e...develop
