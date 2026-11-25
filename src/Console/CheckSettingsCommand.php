<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDOException;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\Support\DefinitionRegistry;

/** @internal Checks package configuration and table availability before deployment. */
final class CheckSettingsCommand extends Command
{
    protected $signature = 'settings:check';

    protected $description = 'Check the settings package for deployment';

    public function handle(SettingsConfig $config, DefinitionRegistry $definitions): int
    {
        try {
            $config->shouldLoadMigrations();
            $config->validateStorageOptions();
            $definitions->definitions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $hasTable = Schema::hasTable(Setting::TABLE);
        } catch (PDOException) {
            $this->error('Could not inspect the settings table.');

            return self::FAILURE;
        }

        if (! $hasTable) {
            $this->error('The settings table is missing.');

            return self::FAILURE;
        }

        $this->info('Settings configuration and table are ready.');

        return self::SUCCESS;
    }
}
