<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Qiick\Settings\Cache\SettingsGroupMemo;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;
use SensitiveParameter;

/** @internal Rotates Laravel ciphertext while preserving the setting and its scope. */
final readonly class SettingsKeyRotation
{
    public function __construct(
        private SettingsTable $table,
        private SettingsWriteLock $locks,
        private SettingsVerification $verification,
        private SettingsGroupMemo $memo,
    ) {}

    /** @return array{eligible: int, unreadable: int} */
    public function run(bool $isDryRun): array
    {
        $active = app(Encrypter::class);
        $current = $this->verification->currentEncrypter();
        $counts = ['eligible' => 0, 'unreadable' => 0];

        foreach (Setting::query()->useWritePdo()->where('encryption', Setting::LARAVEL_ENCRYPTION)->lazyById() as $row) {
            try {
                $scope = $this->verification->scopeOf($row);
            } catch (InvalidArgumentException) {
                $counts['unreadable']++;

                continue;
            }

            $status = $isDryRun
                ? $this->replacement($row, $current, $active, isDryRun: true)['status']
                : $this->rotate($row, $scope, $current, $active);

            if ($status !== 'current') {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /** @return 'current'|'eligible'|'unreadable' */
    private function rotate(#[SensitiveParameter] Setting $row, ScopeRef $scope, Encrypter $current, Encrypter $active): string
    {
        $connection = $this->table->connection();
        $group = $row->group;
        $id = $this->table->identityOf($row);

        return $connection->transaction(function () use ($connection, $scope, $group, $id, $current, $active): string {
            $this->locks->acquire($connection, $scope, [$group]);
            $revision = $this->table->lockedRevision($scope, $group);
            $query = $this->table->group($scope, $group)->where('id', $id)->where('encryption', Setting::LARAVEL_ENCRYPTION);
            $latest = $query->lockForUpdate()->first();

            if ($latest === null) {
                return 'current';
            }

            $replacement = $this->replacement($latest, $current, $active, isDryRun: false);

            if ($replacement['ciphertext'] !== null) {
                $query->toBase()->update([
                    'value' => json_encode($replacement['ciphertext'], JSON_THROW_ON_ERROR),
                    'revision' => SettingsTable::nextRevision($revision),
                    'updated_at' => Date::now('UTC')->toDateTimeString(),
                ]);
                $connection->afterCommit(fn () => $this->memo->forget($scope, $group));
            }

            return $replacement['status'];
        });
    }

    /** @return array{status: 'current'|'eligible'|'unreadable', ciphertext: ?string} */
    private function replacement(#[SensitiveParameter] Setting $row, Encrypter $current, Encrypter $active, bool $isDryRun): array
    {
        $value = $row->value;

        if (! is_string($value)) {
            return ['status' => 'unreadable', 'ciphertext' => null];
        }

        try {
            $current->decryptString($value);

            return ['status' => 'current', 'ciphertext' => null];
        } catch (DecryptException) {
            // Check the active encrypter's previous keys below.
        }

        try {
            $plaintext = $active->decryptString($value);
        } catch (DecryptException) {
            return ['status' => 'unreadable', 'ciphertext' => null];
        }

        return [
            'status' => 'eligible',
            'ciphertext' => $isDryRun ? null : $current->encryptString($plaintext),
        ];
    }
}
