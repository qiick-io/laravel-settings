<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDOException;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Database\SettingsWriteLock;
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
            $config->validateCacheOptions();
            $definitions->definitions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $hasTable = Schema::hasTable(Setting::TABLE);
            $hasWriteLocks = $hasTable && Schema::hasTable(SettingsWriteLock::TABLE);
            $lockIds = $hasWriteLocks
                ? Schema::getConnection()->table(SettingsWriteLock::TABLE)->useWritePdo()->orderBy('id')->pluck('id')->all()
                : [];
        } catch (PDOException) {
            $this->error('Could not inspect the settings table.');

            return self::FAILURE;
        }

        if (! $hasTable) {
            $this->error('The settings table is missing.');

            return self::FAILURE;
        }

        if (! $hasWriteLocks) {
            $this->error('The settings write locks table is missing.');

            return self::FAILURE;
        }

        if ($lockIds !== range(0, 63)) {
            $this->error('The settings write locks table must contain IDs 0 through 63.');

            return self::FAILURE;
        }

        $this->info('Settings configuration and table are ready.');

        return self::SUCCESS;
    }
}
