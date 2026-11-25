<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;
use RuntimeException;

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

    /** @return Builder<Setting> */
    public function recentFirst(ScopeRef $scope, string $group): Builder
    {
        return $this->group($scope, $group)->orderByDesc('revision')->orderByDesc('id');
    }

    /** @return array{revision: int, identity: string} */
    public function cacheVersion(ScopeRef $scope, string $group): array
    {
        return $this->versionOf($this->recentFirst($scope, $group)->first(['id', 'revision']));
    }

    /** @return array{revision: int, identity: string} */
    public function lockedVersion(ScopeRef $scope, string $group): array
    {
        return $this->versionOf($this->recentFirst($scope, $group)->lockForUpdate()->first(['id', 'revision']));
    }

    /** @return array{revision: int, identity: string} */
    public function versionOf(?Setting $row): array
    {
        if ($row === null) {
            return ['revision' => 0, 'identity' => ''];
        }

        $identity = $row->getRawOriginal('id');

        if (! is_int($identity) && ! is_string($identity)) {
            throw new RuntimeException('A settings row has an invalid identity.');
        }

        return [
            'revision' => $row->revision,
            'identity' => (string) $identity,
        ];
    }

    private function integerRevision(mixed $maximum): int
    {
        return is_numeric($maximum) ? (int) $maximum : 0;
    }
}
