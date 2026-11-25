<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use Qiick\Settings\Database\SettingsArchive;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** @internal Writes a verified settings backup as JSON. */
final class SettingsExportCommand extends Command
{
    protected $signature = 'settings:export';

    protected $description = 'Export settings and their ciphertext as JSON';

    public function handle(SettingsArchive $archive): int
    {
        try {
            $json = $archive->export();
        } catch (Throwable) {
            $this->error('Could not export the settings store.');

            return self::FAILURE;
        }

        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
