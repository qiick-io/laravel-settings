# Contributing

Open pull requests against `develop` with a Conventional Commit title and a description of the behavior and its tests. Read the [repository instructions](AGENTS.md) before changing the package.

```bash
composer update
composer test
composer lint
composer analyse
bash scripts/mutate-per-class.sh
```

Mutation testing requires PCOV or Xdebug. Tests use an in-memory SQLite database by default. CI also runs the suite on MySQL, MariaDB and PostgreSQL when the change affects database code.
