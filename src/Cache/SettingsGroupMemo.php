<?php

declare(strict_types=1);

namespace Qiick\Settings\Cache;

use Illuminate\Database\Eloquent\Collection;
use Qiick\Settings\Database\SettingsTable;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Secret;
use Qiick\Settings\Support\DefinitionRegistry;
use SensitiveParameter;
use Throwable;

/** @internal Keeps committed group values for one request or job. */
final class SettingsGroupMemo
{
    /** @var array<string, Collection<array-key, Setting>> */
    private array $groups = [];

    public function __construct(
        private readonly SettingsTable $table,
        private readonly DefinitionRegistry $definitions,
    ) {}

    /** @return Collection<array-key, Setting> */
    public function rows(ScopeRef $scope, string $group): Collection
    {
        $identity = $this->identity($scope, $group);
        $isInTransaction = $this->table->connection()->transactionLevel() > 0;

        if (! $isInTransaction && isset($this->groups[$identity])) {
            return $this->groups[$identity];
        }

        $rows = $this->table->group($scope, $group)->get(['key', 'value', 'encryption'])->keyBy('key');

        if ($isInTransaction) {
            return $rows;
        }

        $memoRows = $this->memoRows($rows, $scope, $group);

        if ($memoRows === null) {
            return $rows;
        }

        return $this->groups[$identity] = $memoRows;
    }

    public function forget(ScopeRef $scope, string $group): void
    {
        unset($this->groups[$this->identity($scope, $group)]);
    }

    /**
     * @param  Collection<array-key, Setting>  $rows
     * @return Collection<array-key, Setting>|null
     */
    private function memoRows(#[SensitiveParameter] Collection $rows, ScopeRef $scope, string $group): ?Collection
    {
        $definitions = $this->definitions->definitions();
        $memoRows = new Collection;

        foreach ($rows as $row) {
            $definition = $definitions[$row->key] ?? null;

            // An orphaned row can hold an old secret. Keep it out of the memo.
            if ($definition === null || $definition->group() !== $group || ! in_array($scope->scope, $definition->scopeNames(), true)) {
                continue;
            }

            if ($definition->isSecret() && $row->getRawOriginal('value') !== null && ! $this->isCiphertext($row)) {
                return null;
            }

            $memoRows->put($row->key, $row);
        }

        return $memoRows;
    }

    private function isCiphertext(#[SensitiveParameter] Setting $row): bool
    {
        if ($row->encryption !== Setting::LARAVEL_ENCRYPTION || ! is_string($row->value)) {
            return false;
        }

        try {
            // A marker alone does not prove that the stored value is ciphertext.
            Secret::fromCiphertext($row->value)->reveal();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function identity(ScopeRef $scope, string $group): string
    {
        return serialize([$scope->scope, $scope->id, $group]);
    }
}
