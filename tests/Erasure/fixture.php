<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Settings\Definition;
use Qiick\Settings\Erasure\SettingsEraser;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Qiick\Standards\Erasure\Support\ErasureFixture;
use Qiick\Standards\Erasure\Support\ErasurePerson;

/** Seeds global user settings and their in-process request memo. The package uses no cache backend, disks or extra connections. */
return new class implements ErasureFixture
{
    public function packageName(): string
    {
        return 'qiick/laravel-settings';
    }

    public function eraserClass(): string
    {
        return SettingsEraser::class;
    }

    public function connectionName(): ?string
    {
        return null;
    }

    public function tables(): array
    {
        return [
            'settings' => [
                'id' => 'other', 'scope' => 'other', 'scope_id' => 'personal', 'group' => 'other', 'key' => 'other',
                'value' => 'personal', 'encryption' => 'other', 'revision' => 'other', 'metadata' => 'personal',
                'created_at' => 'other', 'updated_at' => 'other',
            ],
            'settings_write_locks' => ['id' => 'other'],
        ];
    }

    public function retainedCleartextColumns(): array
    {
        return [];
    }

    public function diskPaths(ErasurePerson $person): array
    {
        return [];
    }

    public function additionalConnections(): array
    {
        return [];
    }

    public function makePerson(): ErasurePerson
    {
        return ErasurePerson::fresh('settings-user-'.Str::lower(Str::random(12)));
    }

    public function seed(ErasurePerson $person, ?string $organisationId): void
    {
        if (! app()->bound('fleet-erasure-settings-schema')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
            app()->instance('fleet-erasure-settings-schema', new ArraySettingsSchema([
                Definition::json('profile')->scopes('user'),
                Definition::string('token')->in('credentials')->scopes('user')->secret(),
            ]));
            app()->tag('fleet-erasure-settings-schema', SettingsSchema::class);
        }

        $settings = app(Settings::class);
        $scope = ScopeRef::of('user', $person->userId);
        $settings->set([
            'profile' => ['person' => $person->searchableValues()],
            'token' => $person->userPublicId,
        ], $scope);
        DB::table('settings')->where('scope', 'user')->where('scope_id', $person->userId)->update([
            'metadata' => json_encode(['person' => $person->userPublicId], JSON_THROW_ON_ERROR),
        ]);
        $settings->array('profile', $scope);
        $settings->secret('token', $scope);
    }

    public function supportsOrganisationScope(): bool
    {
        return false;
    }

    public function organisationScopedTables(): array
    {
        return [];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        return [];
    }

    public function call(Closure $erase): object
    {
        return $erase();
    }
};
