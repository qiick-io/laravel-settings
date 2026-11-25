<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use OverflowException;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;

/** @internal Uses the same primary connection and canonical scope column for every lookup. */
final readonly class SettingsTable
{
    public function __construct(private SettingsConfig $config) {}

    public function connection(): Connection
    {
        return (new Setting)->getConnection();
    }

    /** @return Builder<Setting> */
    public function group(ScopeRef $scope, string $group): Builder
    {
        return Setting::query()
            ->useWritePdo()
            ->where('scope', $scope->scope)
            ->where($this->config->scopeIdColumn(), $scope->id)
            ->where('group', $group);
    }

    public function revision(ScopeRef $scope, string $group): int
    {
        return $this->integerRevision($this->group($scope, $group)->max('revision'));
    }

    public function lockedRevision(ScopeRef $scope, string $group): int
    {
        return $this->integerRevision($this->group($scope, $group)->lockForUpdate()->pluck('revision')->max());
    }

    public static function nextRevision(int $revision): int
    {
        if ($revision >= 2147483647) {
            throw new OverflowException('The settings group revision reached its storage limit.');
        }

        return $revision + 1;
    }

    private function integerRevision(mixed $maximum): int
    {
        return is_numeric($maximum) ? (int) $maximum : 0;
    }
}
