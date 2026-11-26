<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\ScopeRegistry;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Symfony\Component\Console\Output\BufferedOutput;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app(ScopeRegistry::class)->register('project', instanceLike: true);
    app()->instance('archive-settings-schema', new ArraySettingsSchema([
        Definition::string('name')->default('fallback')->scopes('instance', 'user', 'project'),
        Definition::string('token')->secret(),
        Definition::float('ratio')->in('display'),
    ]));
    app()->tag('archive-settings-schema', SettingsSchema::class);
});

function settingsArchiveFile(mixed $document): string
{
    $file = tempnam(sys_get_temp_dir(), 'qiick-settings-');
    file_put_contents($file, is_string($document) ? $document : json_encode($document, JSON_THROW_ON_ERROR));

    return $file;
}

/** @return array<string, mixed> */
function settingsArchiveRow(array $changes = []): array
{
    return array_replace([
        'scope' => 'instance',
        'scope_id' => '',
        'group' => 'app',
        'key' => 'name',
        'value' => 'from archive',
        'encryption' => null,
        'revision' => 3,
        'metadata' => null,
        'created_at' => null,
        'updated_at' => null,
    ], $changes);
}

it('round-trips JSON metadata timestamps and ciphertext without exposing row IDs', function (): void {
    $settings = app(Settings::class);
    $plain = '<info>literal markup</info>';
    $settings->set(['name' => $plain, 'token' => 'synthetic backup secret', 'ratio' => 2.0]);
    $timestamp = '2026-11-25 11:00:00';
    DB::table('settings')->where('key', 'token')->update([
        'metadata' => '{"source":"fixture","empty_object":{}}',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
        'revision' => 5,
    ]);
    $ciphertext = json_decode(DB::table('settings')->where('key', 'token')->value('value'), true);
    expect(Artisan::call('settings:export'))->toBe(0);
    $output = Artisan::output();
    $archive = json_decode($output, flags: JSON_THROW_ON_ERROR);
    $rows = collect($archive->settings)->keyBy('key');
    $token = $rows->get('token');

    expect($archive->version)->toBe(1)
        ->and($output)->not->toContain('synthetic backup secret')
        ->and($rows->get('name')->value)->toBe($plain)
        ->and($rows->get('ratio')->value)->toBe(2.0)
        ->and($token->value)->toBe($ciphertext)
        ->and(get_object_vars($token))->not->toHaveKey('id')
        ->and($token->revision)->toBe(5)
        ->and($token->metadata->source)->toBe('fixture')
        ->and($token->metadata->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($token->created_at)->toBe($timestamp)
        ->and($token->updated_at)->toBe($timestamp);

    DB::table('settings')->delete();
    $file = settingsArchiveFile($output);

    try {
        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('Imported 3 settings rows.')
            ->assertSuccessful();
        $restored = DB::table('settings')->where('key', 'token')->first();
        expect(json_decode($restored->value, true))->toBe($ciphertext)
            ->and(json_decode($restored->metadata)->empty_object)->toBeInstanceOf(stdClass::class)
            ->and($restored->created_at)->toBe($timestamp)
            ->and($restored->updated_at)->toBe($timestamp)
            ->and($settings->revision('app'))->toBe(5)
            ->and($settings->string('name'))->toBe($plain)
            ->and($settings->float('ratio'))->toBe(2.0)
            ->and($settings->secret('token')->reveal())->toBe('synthetic backup secret');
    } finally {
        unlink($file);
    }
});

it('validates a dry run and refuses to overwrite an existing settings table', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'already present']);
    $file = settingsArchiveFile(['version' => 1, 'settings' => [settingsArchiveRow()]]);

    try {
        $this->artisan('settings:import', ['file' => $file, '--dry-run' => true])
            ->expectsOutput('Validated 1 settings rows.')
            ->assertSuccessful();

        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('Settings import requires an empty settings table.')
            ->assertExitCode(1);
        expect($settings->string('name'))->toBe('already present')
            ->and($settings->revision('app'))->toBe(1)
            ->and(DB::table('settings')->count())->toBe(1);
    } finally {
        unlink($file);
    }
});

it('writes the full backup document even in quiet mode', function (): void {
    app(Settings::class)->set(['name' => 'stored value']);
    $output = new BufferedOutput;

    expect(Artisan::call('settings:export', ['--quiet' => true], $output))->toBe(0);
    $document = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    expect($document['version'])->toBe(1)
        ->and($document['settings'][0]['value'])->toBe('stored value');
});

it('diagnoses the first orphaned row without printing its key, scope identity or contents', function (): void {
    app(Settings::class)->set(['name' => 'kept']);
    $rowId = DB::table('settings')->insertGetId([
        'scope' => 'user', 'scope_id' => 'synthetic-private-id', 'group' => 'app',
        'key' => 'synthetic-retired-key', 'value' => '"synthetic plaintext"',
    ]);

    $this->artisan('settings:export')
        ->expectsOutput("Cannot export settings row {$rowId} because its key is no longer declared.")
        ->doesntExpectOutputToContain('synthetic')
        ->assertExitCode(1);
});

it('validates every archived row before writing', function (array $changes): void {
    $file = settingsArchiveFile(['version' => 1, 'settings' => [
        settingsArchiveRow(),
        settingsArchiveRow(array_replace(['key' => 'token', 'value' => null], $changes)),
    ]]);

    try {
        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('The settings archive is invalid or incompatible.')
            ->doesntExpectOutputToContain('synthetic')
            ->assertExitCode(1);

        expect(DB::table('settings')->count())->toBe(0);
    } finally {
        unlink($file);
    }
})->with([
    'undeclared key' => [['key' => 'synthetic unknown']],
    'duplicate key' => [['key' => 'name']],
    'plaintext secret' => [['value' => 'synthetic plaintext']],
    'unreadable secret' => [['encryption' => 'laravel-v1', 'value' => 'synthetic invalid']],
    'invalid scope ID' => [['scope_id' => 10]],
    'invalid scope name' => [['scope' => false]],
    'wrong declared value type' => [['key' => 'ratio', 'group' => 'display', 'value' => '2.5']],
    'invalid group' => [['group' => false]],
    'invalid key' => [['key' => false]],
    'invalid encryption' => [['encryption' => false]],
    'invalid revision type' => [['revision' => '11']],
    'zero revision' => [['revision' => 0]],
    'overflowed revision' => [['revision' => 2147483648]],
    'invalid timestamp type' => [['updated_at' => 10]],
    'invalid timestamp' => [['created_at' => '2026-02-31 12:00:00']],
    'unparseable timestamp' => [['created_at' => 'wednesday morning']],
    'timestamp with a null byte' => [['created_at' => "2026-11-25 11:00:00\0"]],
    'unexpected ID' => [['id' => 10]],
]);

it('rejects malformed files and unsupported archive formats', function (mixed $document): void {
    $file = settingsArchiveFile($document);

    try {
        $this->artisan('settings:import', ['file' => $file, '--dry-run' => true])
            ->expectsOutput('The settings archive is invalid or incompatible.')
            ->assertExitCode(1);
    } finally {
        unlink($file);
    }
})->with([
    'malformed JSON' => ['{'],
    'nonobject document' => [[1, []]],
    'unknown version' => [['version' => 2, 'settings' => []]],
    'invalid rows' => [['version' => 1, 'settings' => false]],
    'nonobject row' => [['version' => 1, 'settings' => [false]]],
    'missing field' => [['version' => 1, 'settings' => [['scope' => 'instance']]]],
]);

it('refuses transfer until ciphertext has been moved off a previous key', function (): void {
    app(Settings::class)->set(['token' => 'synthetic old key value']);
    expect(Artisan::call('settings:export'))->toBe(0);
    $file = settingsArchiveFile(Artisan::output());
    $previousKey = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$previousKey]]);
    app()->forgetInstance('encrypter');

    try {
        $this->artisan('settings:export')
            ->expectsOutput('Could not export the settings store.')
            ->doesntExpectOutputToContain('synthetic')
            ->assertExitCode(1);
        DB::table('settings')->delete();
        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('The settings archive is invalid or incompatible.')
            ->doesntExpectOutputToContain('synthetic')
            ->assertExitCode(1);
        expect(DB::table('settings')->count())->toBe(0);
    } finally {
        unlink($file);
    }
});

it('restores the stored scope string and writes the legacy numeric column', function (): void {
    $file = settingsArchiveFile(['version' => 1, 'settings' => [settingsArchiveRow(['scope' => 'user', 'scope_id' => '042'])]]);
    Schema::table('settings', fn ($table) => $table->renameColumn('scope_id', 'scope_ref'));
    Schema::table('settings', fn ($table) => $table->unsignedBigInteger('scope_id')->nullable());
    config(['settings.migrations.load' => false, 'settings.columns.scope_id' => 'scope_ref', 'settings.columns.legacy_scope_id' => 'scope_id']);

    try {
        $this->artisan('settings:import', ['file' => $file])->assertSuccessful();
        $row = DB::table('settings')->first();
        expect($row->scope_ref)->toBe('042')
            ->and((int) $row->scope_id)->toBe(42)
            ->and(app(Settings::class)->string('name', ScopeRef::of('user', '042')))->toBe('from archive');
    } finally {
        unlink($file);
    }
});

it('requires a readable archive file', function (): void {
    $this->artisan('settings:import', ['file' => sys_get_temp_dir()])
        ->expectsOutput('The settings archive is invalid or incompatible.')
        ->assertExitCode(1);
});

it('rolls back all inserted rows if a later chunk fails', function (): void {
    $rows = array_map(
        fn (int $id): array => settingsArchiveRow(['scope' => 'user', 'scope_id' => (string) $id]),
        range(1, 51),
    );
    $file = settingsArchiveFile(['version' => 1, 'settings' => $rows]);
    $bindingCounts = [];
    DB::listen(function (QueryExecuted $query) use (&$bindingCounts): void {
        if (str_starts_with($query->sql, 'insert into ') && str_contains($query->sql, 'settings')) {
            $bindingCounts[] = count($query->bindings);

            if (count($bindingCounts) === 2) {
                throw new RuntimeException('Synthetic storage error.');
            }
        }
    });

    try {
        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('Could not import the settings store.')
            ->doesntExpectOutputToContain('Synthetic')
            ->assertExitCode(1);
        expect($bindingCounts)->toBe([500, 10])->and(DB::table('settings')->count())->toBe(0);
    } finally {
        unlink($file);
    }
});

it('checks every write lock before restoring an archive', function (): void {
    $file = settingsArchiveFile(['version' => 1, 'settings' => [settingsArchiveRow()]]);
    DB::table('settings_write_locks')->where('id', 63)->delete();

    try {
        $this->artisan('settings:import', ['file' => $file])
            ->expectsOutput('Could not import the settings store.')
            ->assertExitCode(1);
        expect(DB::table('settings')->count())->toBe(0);
    } finally {
        unlink($file);
    }
});

it('invalidates empty group snapshots for every imported scope after commit', function (): void {
    $file = settingsArchiveFile(['version' => 1, 'settings' => [
        settingsArchiveRow(['revision' => 2147483647]),
        settingsArchiveRow(['scope' => 'project', 'value' => 'project value']),
        settingsArchiveRow(['group' => 'display', 'key' => 'ratio', 'value' => 2.5]),
    ]]);
    $settings = app(Settings::class);
    $project = ScopeRef::of('project', '');
    expect($settings->string('name'))->toBe('fallback')
        ->and($settings->string('name', $project))->toBe('fallback')
        ->and($settings->float('ratio'))->toBeNull();

    try {
        $this->artisan('settings:import', ['file' => $file])->assertSuccessful();
        expect($settings->string('name'))->toBe('from archive')
            ->and($settings->string('name', $project))->toBe('project value')
            ->and($settings->float('ratio'))->toBe(2.5)
            ->and($settings->revision('app'))->toBe(2147483647);
    } finally {
        unlink($file);
    }
});
