# Laravel Settings

Scoped, typed settings and encrypted secrets for qiick Laravel products.

The package is under development. It provides the `settings` table, Laravel package discovery, publishable configuration and the `settings:check` deploy command.

## Requirements

PHP 8.3 or later in the 8.x series, and Laravel 12 or 13.

## Configuration

Publish the configuration if the application needs to override a default. Run the migrations before the deploy check.

```bash
php artisan vendor:publish --tag=settings-config
php artisan migrate
php artisan settings:check
```

## Declaring settings

Implement `Qiick\Settings\SettingsSchema` and tag the implementation in your Laravel service provider with `SettingsSchema::class`. Declare each setting key once across all tagged schemas.

```php
return [
    Definition::bool('two_factor_required')->default(false),
    Definition::json('sidebar_modules')->default(fn () => ['overview' => true]),
    Definition::enum('password_policy', PasswordPolicy::class)->default(PasswordPolicy::Required),
    Definition::string('oidc_client_secret')->secret(),
];
```

Definitions use the `app` group and allow only the `instance` scope by default. Use `in()` and `scopes()` to opt into other groups and scopes. Closures supply defaults on demand.

`json()` accepts PHP lists and associative arrays. An enum definition accepts instances of the declared backed enum and stores their backing values. A stored backing value that fails validation falls back to the default and reports `SettingTypeMismatch`.

Secret definitions use `string()->secret()` and have a null default. The package reads secrets from encrypted database values. Products that provide their own environment fallback handle it in application code.

## Reading and editing settings

Resolve `Qiick\Settings\Settings` from the Laravel container. Typed reads use the stored value or the declared default. Ordinary group reads omit secrets, and `forEdit()` exposes a `has_<key>` flag. Call `secret($key)?->reveal()` when the caller needs plaintext.

Typed reads throw `InvalidArgumentException` for an undeclared key, a type or enum mismatch, or a scope the definition does not allow. When a stored non-secret value fails validation, the reader reports `SettingTypeMismatch` and returns the declared default.

Read the group's `revision($group, $scope)` before reading its edit values. Pass that revision as `expectedRevision` to `set()` when saving. A conditional write targets one group and throws `StaleSetting` if another write changed its revision. The revision is monotonic across writes and clears. An empty group starts at zero.

`forget()` clears the stored value and advances the revision, so subsequent reads use the default. An unconditional `set()` can update several groups in one transaction.

- [Public API and deprecation policy](docs/public-api.md)
- [Security policy](SECURITY.md)
- [Reference schema](docs/reference-schema.md)
- [Upgrade guide](UPGRADING.md)

## License

[MIT](LICENSE)
