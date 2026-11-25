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

- [Public API and deprecation policy](docs/public-api.md)
- [Security policy](SECURITY.md)
- [Upgrade guide](UPGRADING.md)

## License

[MIT](LICENSE)
