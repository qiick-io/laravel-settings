<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\SettingTypeMismatch;
use Qiick\Settings\StaleSetting;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Qiick\Settings\UndecryptableSetting;

uses(DatabaseMigrations::class);

enum RuntimePasswordPolicy: string implements JsonSerializable
{
    case Optional = 'optional';
    case Required = 'required';

    public function jsonSerialize(): string
    {
        return 'external presentation';
    }
}

enum RuntimeIntegerPolicy: int
{
    case Required = 1;
}

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app()->instance('runtime-settings-schema', new ArraySettingsSchema([
        Definition::string('token')->secret(),
        Definition::string('name')->default('default name')->scopes('instance', 'user'),
        Definition::bool('enabled')->default(false)->scopes('instance', 'user'),
        Definition::int('limit')->default(5)->scopes('instance', 'user'),
        Definition::float('ratio')->default(1.0)->scopes('instance', 'user'),
        Definition::json('modules')->default(fn () => ['one'])->scopes('instance', 'user'),
        Definition::enum('policy', RuntimePasswordPolicy::class)->default(RuntimePasswordPolicy::Optional)->scopes('instance', 'user'),
        Definition::string('footer')->in('design'),
        Definition::string('42')->in('13'),
    ]));
    app()->tag('runtime-settings-schema', SettingsSchema::class);
});

it('round-trips the declared scalar, array and enum types', function (): void {
    $settings = app(Settings::class);
    expect($settings->string('name'))->toBe('default name')
        ->and($settings->int('limit'))->toBe(5)
        ->and($settings->bool('enabled'))->toBeFalse()
        ->and($settings->float('ratio'))->toBe(1.0)
        ->and($settings->array('modules'))->toBe(['one'])
        ->and($settings->enum('policy', RuntimePasswordPolicy::class))->toBe(RuntimePasswordPolicy::Optional);

    $settings->set([
        'name' => 'saved name', 'limit' => 0, 'enabled' => true, 'ratio' => 2.0,
        'modules' => ['two' => false], 'policy' => RuntimePasswordPolicy::Required,
    ]);

    expect(app(Settings::class))->toBe($settings)
        ->and($settings->string('name'))->toBe('saved name')
        ->and($settings->int('limit'))->toBe(0)
        ->and($settings->bool('enabled'))->toBeTrue()
        ->and($settings->float('ratio'))->toBe(2.0)
        ->and($settings->array('modules'))->toBe(['two' => false])
        ->and($settings->enum('policy', RuntimePasswordPolicy::class))->toBe(RuntimePasswordPolicy::Required)
        ->and(json_decode(DB::table('settings')->where('key', 'policy')->value('value'), true))->toBe('required');
});

it('keeps scopes isolated and restricts writes to declared keys and types', function (): void {
    $settings = app(Settings::class);
    $user = ScopeRef::of('user', '42');
    $settings->set([
        'name' => 'user name', 'limit' => 9, 'enabled' => true, 'ratio' => 2.5,
        'modules' => ['user-module'], 'policy' => RuntimePasswordPolicy::Required,
    ], $user);

    expect($settings->string('name', $user))->toBe('user name')
        ->and($settings->string('name'))->toBe('default name')
        ->and($settings->int('limit', $user))->toBe(9)
        ->and($settings->bool('enabled', $user))->toBeTrue()
        ->and($settings->float('ratio', $user))->toBe(2.5)
        ->and($settings->array('modules', $user))->toBe(['user-module'])
        ->and($settings->enum('policy', RuntimePasswordPolicy::class, $user))->toBe(RuntimePasswordPolicy::Required)
        ->and($settings->revision('app', $user))->toBe(1)
        ->and($settings->all('app', $user)['name'])->toBe('user name')
        ->and($settings->forEdit('app', $user))->not->toHaveKey('has_token')
        ->and($settings->float('missing'))->toBeNull()
        ->and($settings->array('missing'))->toBeNull()
        ->and($settings->enum('policy', RuntimeIntegerPolicy::class))->toBeNull()
        ->and($settings->bool('missing'))->toBeFalse()
        ->and($settings->string('enabled'))->toBeNull()
        ->and(fn () => $settings->set(['enabled' => true, 'never_declared' => 'synthetic']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set(['footer' => 'disallowed'], $user))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set(['limit' => '4']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->forget('never_declared'))->toThrow(InvalidArgumentException::class)
        ->and($settings->bool('enabled'))->toBeFalse();
});

it('reports mismatched database values without including the stored value', function (): void {
    $reporter = Mockery::mock(ExceptionHandler::class);
    $reporter->shouldReceive('report')->times(4)->with(Mockery::on(static function (Throwable $exception): bool {
        return $exception instanceof SettingTypeMismatch && ! str_contains((string) $exception, 'synthetic-invalid-value');
    }));
    app()->instance(ExceptionHandler::class, $reporter);
    DB::table('settings')->insert([
        ['scope' => 'instance', 'group' => 'app', 'key' => 'limit', 'value' => '"synthetic-invalid-value"', 'encryption' => null],
        ['scope' => 'instance', 'group' => 'app', 'key' => 'policy', 'value' => '"synthetic-invalid-value"', 'encryption' => null],
        ['scope' => 'instance', 'group' => 'app', 'key' => 'enabled', 'value' => 'true', 'encryption' => 'laravel-v1'],
    ]);
    $settings = app(Settings::class);

    expect($settings->int('limit'))->toBe(5)
        ->and($settings->enum('policy', RuntimePasswordPolicy::class))->toBe(RuntimePasswordPolicy::Optional);
    DB::table('settings')->where('key', 'policy')->update(['value' => '1']);
    expect($settings->enum('policy', RuntimePasswordPolicy::class))->toBe(RuntimePasswordPolicy::Optional)
        ->and($settings->bool('enabled'))->toBeFalse();
});

it('stores ciphertext and exposes only a presence flag for secret settings', function (): void {
    $settings = app(Settings::class);
    $plaintext = 'synthetic database secret';
    expect($settings->secret('token'))->toBeNull()->and($settings->forEdit('app')['has_token'])->toBeFalse();
    $settings->set(['name' => 'editable', 'token' => $plaintext]);
    $row = DB::table('settings')->where('key', 'token')->first();
    $ciphertext = json_decode($row->value, true);
    $secret = $settings->secret('token');

    expect($row->encryption)->toBe('laravel-v1')
        ->and($ciphertext)->toBeString()->not->toContain($plaintext)
        ->and($secret->reveal())->toBe($plaintext)
        ->and(array_values(get_mangled_object_vars($secret)))->toBe([$ciphertext])
        ->and($settings->string('token'))->toBeNull()
        ->and($settings->secret('name'))->toBeNull()
        ->and($settings->secret('token', ScopeRef::of('user', '42')))->toBeNull()
        ->and($settings->all('app'))->toHaveKey('name', 'editable')
        ->and($settings->all('app'))->not->toHaveKey('token')
        ->and($settings->all('app'))->not->toHaveKey('has_token')
        ->and($settings->forEdit('app'))->not->toHaveKey('token')
        ->and($settings->forEdit('app')['has_token'])->toBeTrue()
        ->and($settings->forEdit('app')['name'])->toBe('editable');

    $settings->set(['token' => null]);
    expect($settings->secret('token'))->toBeNull()
        ->and($settings->forEdit('app')['has_token'])->toBeFalse()
        ->and(DB::table('settings')->where('key', 'token')->first()->encryption)->toBeNull();
});

it('fails closed when a stored secret is corrupt or plaintext', function (?string $encryption, string $value): void {
    $settings = app(Settings::class);
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token',
        'value' => $value, 'encryption' => $encryption,
    ]);

    expect(fn () => $settings->secret('token'))->toThrow(UndecryptableSetting::class);
    config(['settings.encryption.strict' => false]);
    expect($settings->secret('token'))->toBeNull();
})->with([
    [null, '"synthetic-invalid-payload"'],
    ['laravel-v1', '"synthetic-invalid-payload"'],
    ['unknown', '"synthetic-invalid-payload"'],
    ['laravel-v1', 'false'],
]);

it('uses previous Laravel keys for stored secrets during rotation', function (): void {
    $settings = app(Settings::class);
    $previousKey = config('app.key');
    $settings->set(['token' => 'synthetic rotation value']);
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.previous_keys' => [$previousKey],
    ]);
    app()->forgetInstance('encrypter');

    expect($settings->secret('token')->reveal())->toBe('synthetic rotation value')
        ->and(app(StringEncrypter::class)->getAllKeys())->toHaveCount(2);
});

it('advances the group revision even when writing a different key or clearing a value', function (): void {
    $settings = app(Settings::class);
    expect($settings->revision('app'))->toBe(0);
    $settings->set(['name' => 'created'], expectedRevision: 0);
    expect($settings->revision('app'))->toBe(1);
    $settings->set(['enabled' => true], expectedRevision: 1);
    expect($settings->revision('app'))->toBe(2);
    $settings->forget('name');

    expect($settings->revision('app'))->toBe(3)
        ->and($settings->string('name'))->toBe('default name')
        ->and(DB::table('settings')->where('key', 'name')->first()->value)->toBeNull()
        ->and(fn () => $settings->set(['name' => 'stale'], expectedRevision: 1))->toThrow(StaleSetting::class);

    $settings->set(['name' => 'recreated'], expectedRevision: 3);
    expect($settings->revision('app'))->toBe(4)->and($settings->string('name'))->toBe('recreated');
});

it('accepts a conditional batch only within one group and writes unconditional groups atomically', function (): void {
    $settings = app(Settings::class);

    expect(fn () => $settings->set(['name' => 'app', 'footer' => 'design'], expectedRevision: 0))
        ->toThrow(InvalidArgumentException::class, 'one settings group')
        ->and($settings->revision('app'))->toBe(0)
        ->and($settings->revision('design'))->toBe(0);

    $settings->set(['name' => 'app', 'footer' => 'design']);
    expect($settings->revision('app'))->toBe(1)
        ->and($settings->revision('design'))->toBe(1)
        ->and($settings->all('design'))->toBe(['footer' => 'design']);
});

it('rolls back values and revisions with an enclosing transaction', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'before']);

    DB::beginTransaction();
    try {
        $settings->set(['name' => 'inside', 'footer' => 'inside']);
        expect($settings->string('name'))->toBe('inside')->and($settings->revision('app'))->toBe(2);
    } finally {
        DB::rollBack();
    }

    expect($settings->string('name'))->toBe('before')
        ->and($settings->revision('app'))->toBe(1)
        ->and($settings->revision('design'))->toBe(0);
});

it('rejects a batch whose JSON or group revision cannot be stored', function (): void {
    $settings = app(Settings::class);
    $settings->set([]);
    expect($settings->revision('app'))->toBe(0);
    expect(fn () => $settings->set(['name' => 'first', 'modules' => [INF]]))
        ->toThrow(InvalidArgumentException::class, 'cannot be encoded as JSON');

    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'design', 'key' => 'footer', 'revision' => 2147483646, 'value' => '"previous"',
    ]);
    $settings->set(['footer' => 'current']);
    expect($settings->revision('design'))->toBe(2147483647);

    expect(fn () => $settings->set(['name' => 'first', 'footer' => 'new']))
        ->toThrow(OverflowException::class)
        ->and($settings->revision('app'))->toBe(0)
        ->and($settings->string('footer'))->toBe('current');
});

it('uses the configured scope ID column and dual writes the legacy identifier', function (): void {
    Schema::table('settings', fn (Blueprint $table) => $table->renameColumn('scope_id', 'scope_ref'));
    Schema::table('settings', fn (Blueprint $table) => $table->unsignedBigInteger('scope_id')->nullable());
    config(['settings.migrations.load' => false, 'settings.columns.scope_id' => 'scope_ref', 'settings.columns.legacy_scope_id' => 'scope_id']);

    $settings = app(Settings::class);
    $user = ScopeRef::of('user', '042');
    $settings->set(['name' => 'user'], $user);
    $settings->set(['name' => 'instance']);

    $userRow = DB::table('settings')->where('scope', 'user')->first();
    DB::table('settings')->where('scope', 'user')->update(['scope_id' => 50]);
    $settings->set(['name' => 'updated user'], $user);

    expect($userRow->scope_ref)->toBe('042')
        ->and((int) $userRow->scope_id)->toBe(42)
        ->and((int) DB::table('settings')->where('scope', 'user')->first()->scope_id)->toBe(42)
        ->and(DB::table('settings')->where('scope', 'instance')->first()->scope_id)->toBeNull()
        ->and($settings->string('name', $user))->toBe('updated user')
        ->and($settings->string('name'))->toBe('instance');
});

it('uses fixed identity-free locks and refuses a write when its lock row is missing', function (): void {
    expect(DB::table('settings_write_locks')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all())
        ->toBe(range(0, 63));
    DB::table('settings_write_locks')->where('id', 36)->delete();

    expect(fn () => app(Settings::class)->set(['name' => 'blocked']))->toThrow(RuntimeException::class)
        ->and(DB::table('settings')->count())->toBe(0);
});

it('supports numeric keys and groups and normalizes a string database revision', function (): void {
    $settings = app(Settings::class);
    $settings->set(['42' => 'numeric']);
    $database = DB::connection()->getPdo();
    $database->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

    try {
        expect($settings->string('42'))->toBe('numeric')
            ->and($settings->revision('13'))->toBe(1);
    } finally {
        $database->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }
});

it('preserves the creation time and records the last write in UTC', function (): void {
    $settings = app(Settings::class);
    Date::setTestNow('2026-11-25 09:00:00 UTC');

    try {
        $settings->set(['name' => 'original']);
        Date::setTestNow('2026-11-25 10:00:00 UTC');
        $settings->set(['name' => 'updated']);
        $row = DB::table('settings')->where('key', 'name')->first();

        expect($row->created_at)->toBe('2026-11-25 09:00:00')
            ->and($row->updated_at)->toBe('2026-11-25 10:00:00');
    } finally {
        Date::setTestNow();
    }
});
