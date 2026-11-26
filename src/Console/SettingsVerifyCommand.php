<?php

declare(strict_types=1);

namespace Qiick\Settings\Console;

use Illuminate\Console\Command;
use Qiick\Settings\Database\SettingsVerification;
use Throwable;

/** @internal Checks declared settings and their stored encryption. */
final class SettingsVerifyCommand extends Command
{
    protected $signature = 'settings:verify';

    protected $description = 'Verify stored settings and their encryption';

    public function handle(SettingsVerification $verification): int
    {
        try {
            $result = $verification->inspect();
        } catch (Throwable) {
            $this->error('Could not verify the settings store.');

            return self::FAILURE;
        }

        if ($result['invalid'] > 0) {
            $this->error("{$result['invalid']} settings rows failed verification.");

            return self::FAILURE;
        }

        $this->info("Verified {$result['total']} settings rows.");

        return self::SUCCESS;
    }
}
