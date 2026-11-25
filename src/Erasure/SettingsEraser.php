<?php

declare(strict_types=1);

namespace Qiick\Settings\Erasure;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Qiick\Settings\Cache\SettingsGroupMemo;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Database\SettingsTable;
use Qiick\Settings\Database\SettingsWriteLock;
use Qiick\Settings\Events\SubjectErasedEvent;
use Qiick\Settings\Models\Setting;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** @api Erases the settings stored for one person using the fleet erasure contract. */
final readonly class SettingsEraser
{
    public const string DELETE = 'delete';

    public const string ANONYMISE = 'anonymise';

    public function __construct(
        private SettingsConfig $config,
        private SettingsTable $table,
        private SettingsWriteLock $locks,
        private SettingsGroupMemo $memo,
        private Dispatcher $events,
    ) {}

    /**
     * @param  'delete'|'anonymise'  $mode
     * @param  list<string>  $publicIds
     * @param  list<string>  $identifiers
     */
    public function erase(
        #[SensitiveParameter] string $userId,
        #[SensitiveParameter] string $userPublicId,
        #[SensitiveParameter] string $mode,
        #[SensitiveParameter] array $publicIds = [],
        #[SensitiveParameter] array $identifiers = [],
        ?string $organisationId = null,
        bool $dryRun = false,
    ): ErasureReport {
        $this->validate($userPublicId, $mode, $publicIds, $identifiers);

        try {
            $connection = $this->table->connection();
            $rows = $connection->table(Setting::TABLE)->useWritePdo()
                ->where('scope', 'user')->where($this->config->scopeIdColumn(), $userId);
            $isFullErasure = $organisationId === null;

            return $connection->transaction(function () use ($connection, $rows, $userId, $mode, $isFullErasure, $dryRun): ErasureReport {
                if ($isFullErasure && ! $dryRun) {
                    // A new group may be created after any enumeration of this user's groups.
                    $this->locks->acquireAll($connection);
                    $rowCount = $rows->delete();
                } else {
                    $rowCount = $rows->count();
                }

                $memoCount = $this->memo->countScope('user', $userId);
                $report = new ErasureReport('qiick/laravel-settings', $mode, $dryRun, [
                    'settings' => $this->counts($rowCount, $isFullErasure),
                    'cache:settings' => $this->counts($memoCount, $isFullErasure),
                ]);

                if (! $dryRun) {
                    $connection->afterCommit(function () use ($userId, $isFullErasure, $report): void {
                        if ($isFullErasure) {
                            $this->memo->forgetScope('user', $userId);
                        }

                        $this->events->dispatch(new SubjectErasedEvent($report));
                    });
                }

                return $report;
            });
        } catch (Throwable) {
            // A database exception can include the subject's identifier in its query bindings.
            throw new RuntimeException('Could not erase user settings.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $publicIds
     * @param  array<array-key, mixed>  $identifiers
     */
    private function validate(
        #[SensitiveParameter] string $userPublicId,
        #[SensitiveParameter] string $mode,
        #[SensitiveParameter] array $publicIds,
        #[SensitiveParameter] array $identifiers,
    ): void {
        if (! in_array($mode, [self::DELETE, self::ANONYMISE], true)) {
            throw new InvalidArgumentException('The erasure mode must be delete or anonymise.');
        }

        foreach ([$userPublicId, ...$publicIds] as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                throw new InvalidArgumentException('Every public ID must be a UUID.');
            }
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Every identifier must be a non-empty string.');
            }
        }
    }

    /** @return array{deleted: int, anonymised: int, kept: int, reason: ?string} */
    private function counts(int $count, bool $isFullErasure): array
    {
        return [
            'deleted' => $isFullErasure ? $count : 0,
            'anonymised' => 0,
            'kept' => $isFullErasure ? 0 : $count,
            'reason' => ! $isFullErasure && $count > 0 ? 'outside_organisation_scope' : null,
        ];
    }
}
