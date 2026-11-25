<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use Qiick\Settings\Cache\SettingsGroupMemo;
use Qiick\Settings\Config\SettingsConfig;
use Qiick\Settings\Definition;
use Qiick\Settings\Models\Setting;
use Qiick\Settings\Support\DefinitionRegistry;
use SensitiveParameter;
use stdClass;
use ValueError;

/** @internal Transfers settings through validated, versioned JSON documents. */
final readonly class SettingsArchive
{
    public function __construct(
        private SettingsConfig $config,
        private DefinitionRegistry $definitions,
        private SettingsVerification $verification,
        private SettingsTable $table,
        private SettingsWriteLock $locks,
        private SettingsGroupMemo $memo,
    ) {}

    public function export(): string
    {
        $definitions = $this->definitions->definitions();
        $current = null;
        $rows = [];

        foreach (Setting::query()->useWritePdo()->orderBy('id')->cursor() as $row) {
            $this->verifyForTransfer($row, $definitions, $current);
            $scope = $this->verification->scopeOf($row);
            $rows[] = [
                'scope' => $scope->scope,
                'scope_id' => $scope->id,
                'group' => $row->group,
                'key' => $row->key,
                'value' => $this->verification->json($row, 'value'),
                'encryption' => $row->encryption,
                'revision' => $this->revision($row->revision),
                'metadata' => $this->verification->json($row, 'metadata'),
                'created_at' => $this->timestamp($row->getRawOriginal('created_at')),
                'updated_at' => $this->timestamp($row->getRawOriginal('updated_at')),
            ];
        }

        return json_encode(['version' => 1, 'settings' => $rows], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function restore(string $file, bool $isDryRun): ?int
    {
        if (! is_file($file) || ($contents = @file_get_contents($file)) === false) {
            throw new InvalidArgumentException('The settings archive file is unreadable.');
        }

        $document = json_decode($contents, flags: JSON_THROW_ON_ERROR);

        if (! $document instanceof stdClass || ($document->version ?? null) !== 1
            || ! is_array($document->settings ?? null)) {
            throw new InvalidArgumentException('The settings archive version or structure is invalid.');
        }

        $rows = [];
        $keys = [];
        $groups = [];
        $definitions = $this->definitions->definitions();
        $scopeColumn = $this->config->scopeIdColumn();
        $legacyColumn = $this->config->legacyScopeIdColumn();
        $current = null;

        foreach ($document->settings as $input) {
            $row = $this->storageRow($input, $scopeColumn);
            $settings = (new Setting)->setRawAttributes($row, sync: true);
            $this->verifyForTransfer($settings, $definitions, $current);
            $scope = $this->verification->scopeOf($settings);
            $group = $settings->group;
            $groupIdentity = serialize([$scope->scope, $scope->id, $group]);
            $key = $settings->key;

            if (isset($keys[$groupIdentity][$key])) {
                throw new InvalidArgumentException('The settings archive contains a duplicate setting.');
            }

            $keys[$groupIdentity][$key] = true;
            $groups[$groupIdentity] = [$scope, $group];

            if ($legacyColumn !== null) {
                $row[$legacyColumn] = ctype_digit($scope->id) ? $scope->id : null;
            }

            $rows[] = $row;
        }

        if ($isDryRun) {
            return count($rows);
        }

        $connection = $this->table->connection();

        return $connection->transaction(function () use ($connection, $rows, $groups): ?int {
            $this->locks->acquireAll($connection);
            $settings = $connection->table(Setting::TABLE);

            if ($settings->lockForUpdate()->first(['id']) !== null) {
                return null;
            }

            foreach (array_chunk($rows, 50) as $chunk) {
                $connection->table(Setting::TABLE)->insert($chunk);
            }

            $connection->afterCommit(function () use ($groups): void {
                foreach ($groups as [$scope, $group]) {
                    $this->memo->forget($scope, $group);
                }
            });

            return count($rows);
        });
    }

    /** @return array<string, int|string|null> */
    private function storageRow(#[SensitiveParameter] mixed $input, string $scopeColumn): array
    {
        if (! $input instanceof stdClass) {
            throw new InvalidArgumentException('A settings archive row must be an object.');
        }

        $fields = get_object_vars($input);
        $required = ['scope', 'scope_id', 'group', 'key', 'value', 'encryption', 'revision', 'metadata', 'created_at', 'updated_at'];

        if (count($fields) !== count($required) || array_diff($required, array_keys($fields)) !== []) {
            throw new InvalidArgumentException('A settings archive row has unexpected fields.');
        }

        $scope = $fields['scope'];
        $scopeId = $fields['scope_id'];
        $group = $fields['group'];
        $key = $fields['key'];
        $encryption = $fields['encryption'];

        if (! is_string($scope) || ! is_string($scopeId) || ! is_string($group) || ! is_string($key)
            || ($encryption !== null && ! is_string($encryption))) {
            throw new InvalidArgumentException('A settings archive row has invalid field types.');
        }

        return [
            'scope' => $scope,
            $scopeColumn => $scopeId,
            'group' => $group,
            'key' => $key,
            'value' => $this->json($fields['value']),
            'encryption' => $encryption,
            'revision' => $this->revision($fields['revision']),
            'metadata' => $this->json($fields['metadata']),
            'created_at' => $this->timestamp($fields['created_at']),
            'updated_at' => $this->timestamp($fields['updated_at']),
        ];
    }

    /** @param array<array-key, Definition> $definitions */
    private function verifyForTransfer(#[SensitiveParameter] Setting $row, array $definitions, ?Encrypter &$current): void
    {
        if ($row->encryption !== null) {
            $current ??= $this->verification->currentEncrypter();
        }

        if (! $this->verification->isValid($row, $definitions, $current)) {
            throw new InvalidArgumentException('A settings row did not pass transfer verification.');
        }
    }

    private function json(#[SensitiveParameter] mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function revision(mixed $revision): int
    {
        if (! is_int($revision) || $revision < 1 || $revision > 2147483647) {
            throw new InvalidArgumentException('A settings archive revision is outside the storage range.');
        }

        return $revision;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A settings archive timestamp must be a string.');
        }

        try {
            $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        } catch (ValueError) {
            throw new InvalidArgumentException('A settings archive timestamp is invalid.');
        }

        if ($timestamp === false || $timestamp->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('A settings archive timestamp is invalid.');
        }

        return $value;
    }
}
