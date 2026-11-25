<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Qiick\Settings\Config\SettingsConfig;

/** @internal Reports package configuration errors as a deployment check. */
final class CheckSettingsCommand extends Command
{
    protected $signature = 'settings:check';

    protected $description = 'Check the settings package for deployment';

    public function handle(SettingsConfig $config): int
    {
        try {
            $config->shouldLoadMigrations();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Settings configuration is valid.');

        return self::SUCCESS;
    }
}
