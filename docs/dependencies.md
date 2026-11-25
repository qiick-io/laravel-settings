# Runtime dependencies

| Dependency | Constraint | Use and minimum | Extension or replacement | Maintainer and license | Risk | Exit plan |
| --- | --- | --- | --- | --- | --- | --- |
| `php` | `^8.3` | Shared PHP runtime floor for the package and its Laravel 12 applications. | — | PHP project, PHP License 3.01 | New runtime versions can change behavior. | Keep the public API stable as the fleet raises its PHP floor. |
| `illuminate/console` | `^12.0 \|\| ^13.0` | Registers the deploy check as an Artisan command on both supported Laravel majors. | Extends `Illuminate\Console\Command` for Artisan integration. | Laravel, MIT | Artisan command lifecycle can change across majors. | Adapt the command wrapper while retaining the signature and exit codes. |
| `illuminate/contracts` | `^12.0 \|\| ^13.0` | Reads package configuration through Laravel's repository contract on both supported majors. | — | Laravel, MIT | A new major could change repository behavior. | Adapt the typed reader while retaining the published configuration keys. |
| `illuminate/support` | `^12.0 \|\| ^13.0` | The provider integrates with Laravel's application discovery and configuration publishing. | Extends `Illuminate\Support\ServiceProvider` for Laravel discovery. | Laravel, MIT | Changes to provider discovery or configuration lifecycle. | Adapt provider wiring inside the package while retaining the published configuration contract. |
