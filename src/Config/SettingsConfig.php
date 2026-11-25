<?php

declare(strict_types=1);

namespace Qiick\Settings\Config;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal Reads and validates package options from Laravel configuration. */
final readonly class SettingsConfig
{
    public function __construct(private Repository $config) {}

    public static function defaultMigrationLoading(): bool
    {
        return true;
    }

    public function shouldLoadMigrations(): bool
    {
        $shouldLoadMigrations = $this->config->get('settings.migrations.load', self::defaultMigrationLoading());

        if (! is_bool($shouldLoadMigrations)) {
            throw new InvalidArgumentException('settings.migrations.load must be a boolean.');
        }

        return $shouldLoadMigrations;
    }
}
