<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use Qiick\Settings\Database\SettingsKeyRotation;
use Throwable;

/** @internal Moves ciphertext from previous Laravel keys to the current key. */
final class SettingsReencryptCommand extends Command
{
    protected $signature = 'settings:reencrypt {--dry-run : Count rows without updating them}';

    protected $description = 'Re-encrypt settings stored with a previous Laravel key';

    public function handle(SettingsKeyRotation $rotation): int
    {
        $isDryRun = $this->option('dry-run') === true;

        try {
            $result = $rotation->run($isDryRun);
        } catch (Throwable) {
            $this->error('Could not finish settings re-encryption.');

            return self::FAILURE;
        }

        $eligible = $result['eligible'];

        if ($isDryRun) {
            $this->info("{$eligible} settings rows need re-encryption.");
        } else {
            $this->info("Re-encrypted {$eligible} settings rows.");
        }

        if ($result['unreadable'] > 0) {
            $this->error("{$result['unreadable']} encrypted settings rows could not be read.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
