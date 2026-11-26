<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Qiick\Settings\Database\SettingsArchive;
use Throwable;

/** @internal Restores a verified settings backup into an empty store. */
final class SettingsImportCommand extends Command
{
    protected $signature = 'settings:import {file : Path to the JSON settings archive} {--dry-run : Validate the archive without writing}';

    protected $description = 'Import a settings archive into an empty settings table';

    public function handle(SettingsArchive $archive): int
    {
        $file = $this->argument('file');
        $isDryRun = $this->option('dry-run') === true;

        try {
            if (! is_string($file)) {
                throw new InvalidArgumentException('An archive file is required.');
            }

            $count = $archive->restore($file, $isDryRun);
        } catch (InvalidArgumentException|JsonException) {
            $this->error('The settings archive is invalid or incompatible.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Could not import the settings store.');

            return self::FAILURE;
        }

        if ($count === null) {
            $this->error('Settings import requires an empty settings table.');

            return self::FAILURE;
        }

        $this->info(($isDryRun ? 'Validated ' : 'Imported ').$count.' settings rows.');

        return self::SUCCESS;
    }
}
