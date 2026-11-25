<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\ScopeRegistry;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsCache;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Qiick\Settings\Tests\Support\SettingsCacheProbe;
use Qiick\Settings\UndecryptableSetting;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app(ScopeRegistry::class)->register('tenant');
    app()->instance('cache-settings-schema', new ArraySettingsSchema([
        Definition::string('name')->default('fallback')->scopes('instance', 'user', 'tenant'),
        Definition::string('token')->secret(),
        Definition::string('footer')->in('design'),
    ]));
    app()->tag('cache-settings-schema', SettingsSchema::class);
    app()->instance(SettingsCache::class, new SettingsCacheProbe);
});

it('uses request memoization and the shared cache for the same committed group version', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'persisted']);
    $settings->set(['name' => 'user value'], ScopeRef::of('user', '42'));
    config(['settings.cache.prefix' => 'synthetic-prefix', 'settings.cache.ttl' => 400]);

    expect($settings->string('name'))->toBe('persisted')
        ->and($settings->all('app')['name'])->toBe('persisted')
        ->and($cache->reads)->toHaveCount(1)
        ->and($cache->lifetimes)->toBe([400])
        ->and(array_key_first($cache->entries))->toStartWith('synthetic-prefix:');

    app()->forgetScopedInstances();
    $settings = app(Settings::class);
    expect($settings->string('name'))->toBe('persisted')
        ->and($cache->reads)->toHaveCount(2)
        ->and($cache->lifetimes)->toBe([400])
        ->and($settings->string('name', ScopeRef::of('user', '42')))->toBe('user value')
        ->and($cache->entries)->toHaveCount(2);
});

it('invalidates a group only after the outer commit and reads transaction-local data directly', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'before']);
    $settings->string('name');
    $cachedBefore = $cache->entries;
    $cache->deletions = [];

    DB::beginTransaction();
    try {
        $settings->set(['name' => 'after']);
        expect($settings->string('name'))->toBe('after')
            ->and($cache->entries)->toBe($cachedBefore)
            ->and($cache->deletions)->toBe([]);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    expect($cache->entries)->toBe([])
        ->and($cache->deletions)->toBe([['key' => array_key_first($cachedBefore), 'transaction' => 0]])
        ->and($settings->string('name'))->toBe('after')
        ->and(array_key_first($cache->entries))->not->toBe(array_key_first($cachedBefore));
});

it('leaves shared cache entries intact when an enclosing transaction rolls back', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'before']);
    $settings->string('name');
    $cachedBefore = $cache->entries;
    $cache->deletions = [];

    DB::beginTransaction();
    try {
        $settings->set(['name' => 'aborted']);
        expect($settings->string('name'))->toBe('aborted');
    } finally {
        DB::rollBack();
    }

    expect($cache->entries)->toBe($cachedBefore)->not->toBe([])
        ->and($cache->deletions)->toBe([])
        ->and($settings->string('name'))->toBe('before');
});

it('uses the database when any cache operation fails', function (): void {
    $cache = app(SettingsCache::class);
    $cache->isUnavailable = true;
    $settings = app(Settings::class);
    $settings->set(['name' => 'available']);

    expect($settings->string('name'))->toBe('available')
        ->and($cache->reads)->toHaveCount(1)
        ->and($cache->deletions)->toHaveCount(1)
        ->and($cache->entries)->toBe([]);
});

it('caches ciphertext and bypasses caching for mislabeled plaintext', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic plaintext']);
    $secret = $settings->secret('token');
    $ciphertext = json_decode(DB::table('settings')->where('key', 'token')->value('value'), true);
    $payload = array_values($cache->entries)[0];
    $rows = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    expect($secret->reveal())->toBe('synthetic plaintext')
        ->and(json_decode($rows[0]['value'], true, flags: JSON_THROW_ON_ERROR))->toBe($ciphertext)
        ->and($payload)->not->toContain('synthetic plaintext');

    $cache->entries = [];
    app()->forgetScopedInstances();
    DB::table('settings')->where('key', 'token')->update(['value' => '"synthetic raw plaintext"']);
    $settings = app(Settings::class);

    expect($settings->string('name'))->toBe('fallback')
        ->and($cache->entries)->toBe([])
        ->and(fn () => $settings->secret('token'))->toThrow(UndecryptableSetting::class);
});

it('uses a new shared key after records are recreated at an earlier revision', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'previous incarnation']);
    $settings->string('name');
    $firstKey = array_key_first($cache->entries);

    DB::table('settings')->delete();
    DB::table('settings')->insert(['scope' => 'instance', 'group' => 'app', 'key' => 'name', 'value' => '"new incarnation"']);
    app()->forgetScopedInstances();

    expect(app(Settings::class)->revision('app'))->toBe(1)
        ->and(app(Settings::class)->string('name'))->toBe('new incarnation')
        ->and($cache->reads[array_key_last($cache->reads)])->not->toBe($firstKey);
});

it('rejects malformed shared entries and reloads the database', function (string $payload): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'from database']);
    $settings->string('name');
    $key = array_key_first($cache->entries);
    $cache->entries[$key] = $payload;
    app()->forgetScopedInstances();

    expect(app(Settings::class)->string('name'))->toBe('from database')
        ->and($cache->entries[$key])->not->toBe($payload);
})->with([
    'invalid JSON' => ['{'],
    'object' => ['{"name":null}'],
    'scalar' => ['false'],
    'non-row' => ['[false]'],
    'invalid key' => ['[{"key":false,"value":null,"encryption":null}]'],
    'missing value' => ['[{"key":"name","encryption":null}]'],
    'missing encryption' => ['[{"key":"name","value":null}]'],
    'invalid value' => ['[{"key":"name","value":42,"encryption":null}]'],
    'invalid encryption' => ['[{"key":"name","value":null,"encryption":42}]'],
]);

it('does not write a snapshot under a revision that changed before the rows were read', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'before race']);
    $cache->beforeRead = function () use ($cache): void {
        $cache->beforeRead = null;
        DB::table('settings')->where('key', 'name')->update(['value' => '"after race"', 'revision' => 2]);
    };

    expect($settings->string('name'))->toBe('after race')
        ->and($cache->entries)->toBe([])
        ->and($settings->string('name'))->toBe('after race')
        ->and($cache->entries)->toHaveCount(1);
});

it('keeps undeclared and out-of-scope database values out of snapshots', function (): void {
    $cache = app(SettingsCache::class);
    $scope = ScopeRef::of('user', '42');
    DB::table('settings')->insert([
        ['scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => 'name', 'value' => '"allowed value"'],
        ['scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => 'token', 'value' => '"out of scope"'],
        ['scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => 'footer', 'value' => '"other group"'],
        ['scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => 'undeclared', 'value' => '"undeclared value"'],
    ]);

    expect(app(Settings::class)->all('app', $scope))->toBe(['name' => 'allowed value'])
        ->and(array_values($cache->entries)[0])->toContain('allowed value')
        ->and(array_values($cache->entries)[0])->not->toContain('out of scope')
        ->and(array_values($cache->entries)[0])->not->toContain('other group')
        ->and(array_values($cache->entries)[0])->not->toContain('undeclared value');
});

it('rejects an unsafe cached secret and replaces it with the database ciphertext', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic permitted value']);
    $settings->secret('token');
    $key = array_key_first($cache->entries);
    $cache->entries[$key] = json_encode([
        ['key' => 'token', 'value' => '"synthetic corrupt value"', 'encryption' => 'laravel-v1'],
    ], JSON_THROW_ON_ERROR);
    app()->forgetScopedInstances();

    expect(app(Settings::class)->secret('token')->reveal())->toBe('synthetic permitted value')
        ->and($cache->entries[$key])->not->toContain('synthetic corrupt value')
        ->and($cache->entries[$key])->not->toContain('synthetic permitted value');
});

it('refuses to cache a plaintext secret or a nonstring encrypted value', function (?string $encryption, string $value): void {
    $cache = app(SettingsCache::class);
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token',
        'value' => $value, 'encryption' => $encryption,
    ]);

    expect(app(Settings::class)->string('name'))->toBe('fallback')
        ->and($cache->entries)->toBe([]);
})->with([[null, '"synthetic plaintext"'], ['laravel-v1', 'false']]);

it('preserves secret state when refreshing the request memo from shared snapshots', function (): void {
    $cache = app(SettingsCache::class);
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic secret']);
    expect($settings->secret('token')->reveal())->toBe('synthetic secret');
    app()->forgetScopedInstances();
    $settings = app(Settings::class);
    expect($settings->secret('token')->reveal())->toBe('synthetic secret')
        ->and($cache->lifetimes)->toHaveCount(1);

    $settings->forget('token');
    expect($settings->secret('token'))->toBeNull()
        ->and($cache->lifetimes)->toHaveCount(2)
        ->and($cache->entries)->toHaveCount(1);
    app()->forgetScopedInstances();
    expect(app(Settings::class)->secret('token'))->toBeNull()
        ->and($cache->lifetimes)->toHaveCount(2);
});

it('isolates memoized groups by scope name, owner ID and group name', function (): void {
    $settings = app(Settings::class);
    $user = ScopeRef::of('user', '42');
    $otherUser = ScopeRef::of('user', '84');
    $tenant = ScopeRef::of('tenant', '42');
    $settings->set(['name' => 'user one'], $user);
    $settings->set(['name' => 'user two'], $otherUser);
    $settings->set(['name' => 'tenant one'], $tenant);
    $settings->set(['name' => 'instance', 'footer' => 'design']);

    expect($settings->string('name', $user))->toBe('user one')
        ->and($settings->string('name', $otherUser))->toBe('user two')
        ->and($settings->string('name', $tenant))->toBe('tenant one')
        ->and($settings->string('name'))->toBe('instance')
        ->and($settings->string('footer'))->toBe('design')
        ->and(app(SettingsCache::class)->entries)->toHaveCount(5);
});

it('refreshes previously memoized values when reading the revision before an edit', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'initial memo']);
    expect($settings->string('name'))->toBe('initial memo');
    DB::table('settings')->where('key', 'name')->update(['value' => '"latest value"', 'revision' => 2]);

    expect($settings->revision('app'))->toBe(2)
        ->and($settings->forEdit('app')['name'])->toBe('latest value');
});
