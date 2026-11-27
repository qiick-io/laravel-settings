# Contributing

Open pull requests against `develop` with a Conventional Commit title and a description of the behavior and its tests. Read the [repository instructions](AGENTS.md) before changing the package.

```bash
composer update
composer test
composer lint
composer analyse
bash scripts/mutate-per-class.sh
```

Every file in `src/` outside `src/Testing/` (product test fakes) must reach 95%. The few files where pest creates no mutant (interfaces, events, exceptions) are listed with their reason in `no_mutant_files`; any other file with no mutant fails. Mutation testing requires PCOV or Xdebug. Tests use an in-memory SQLite database by default. CI also runs the suite on MySQL, MariaDB and PostgreSQL when the change affects database code.
