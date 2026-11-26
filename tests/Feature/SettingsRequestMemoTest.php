<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Settings\Cache\SettingsGroupMemo;
use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\ScopeRegistry;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Qiick\Settings\UndecryptableSetting;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app(ScopeRegistry::class)->register('tenant');
    app()->instance('request-settings-schema', new ArraySettingsSchema([
        Definition::string('name')->scopes('instance', 'user', 'tenant'),
        Definition::string('token')->secret(),
        Definition::string('footer')->in('design'),
    ]));
    app()->tag('request-settings-schema', SettingsSchema::class);
});

it('loads each group once per request without accessing the Laravel cache', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'from database']);
    Event::fake([CacheHit::class, CacheMissed::class, KeyWritten::class]);
    DB::enableQueryLog();

    try {
        expect($settings->string('name'))->toBe('from database')
            ->and($settings->all('app'))->toBe(['name' => 'from database']);
        Event::assertNotDispatched(CacheHit::class);
        Event::assertNotDispatched(CacheMissed::class);
        Event::assertNotDispatched(KeyWritten::class);
        expect(DB::getQueryLog())->toHaveCount(1);

        app()->forgetScopedInstances();
        expect(app(Settings::class)->string('name'))->toBe('from database')
            ->and(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('uses an existing value as soon as a new request starts with its key declared', function (): void {
    DB::table('settings')->insert(['scope' => 'instance', 'group' => 'app', 'key' => 'banner', 'value' => '"stored"']);
    expect(app(Settings::class)->all('app'))->toBe(['name' => null]);

    app()->forgetScopedInstances();
    app()->instance('request-settings-schema', new ArraySettingsSchema([
        Definition::string('name'),
        Definition::string('banner')->default('default'),
    ]));

    expect(app(Settings::class)->string('banner'))->toBe('stored');
});

it('reuses a memo through rollback and refreshes it after the outer commit', function (): void {
    $settings = app(Settings::class);
    $memo = app(SettingsGroupMemo::class);
    $scope = ScopeRef::instance();
    $settings->set(['name' => 'before']);
    $committed = $memo->rows($scope, 'app');
    DB::beginTransaction();

    try {
        $settings->set(['name' => 'aborted']);
        expect($settings->string('name'))->toBe('aborted')
            ->and($memo->rows($scope, 'app'))->not->toBe($committed);
    } finally {
        DB::rollBack();
    }

    expect($memo->rows($scope, 'app'))->toBe($committed)
        ->and($settings->string('name'))->toBe('before');

    DB::beginTransaction();

    try {
        $settings->set(['name' => 'after']);
        expect($settings->string('name'))->toBe('after');
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    expect($memo->rows($scope, 'app'))->not->toBe($committed)
        ->and($settings->string('name'))->toBe('after');
});

it('refreshes memoized values when reading the revision before an edit', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'initial memo']);
    expect($settings->string('name'))->toBe('initial memo');
    DB::table('settings')->where('key', 'name')->update(['value' => '"latest value"', 'revision' => 2]);

    expect($settings->revision('app'))->toBe(2)
        ->and($settings->forEdit('app')['name'])->toBe('latest value');
});

it('isolates groups by scope name, owner ID and group name', function (): void {
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
        ->and($settings->string('footer'))->toBe('design');
});

it('memoizes only ciphertext for secrets and clears encrypted values after commit', function (): void {
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic plaintext']);
    $ciphertext = DB::table('settings')->where('key', 'token')->value('value');
    $memo = app(SettingsGroupMemo::class);
    $scope = ScopeRef::instance();
    $rows = $memo->rows($scope, 'app');

    expect($settings->secret('token')->reveal())->toBe('synthetic plaintext')
        ->and($memo->rows($scope, 'app'))->toBe($rows)
        ->and($rows->get('token')->getRawOriginal('value'))->toBe($ciphertext)
        ->and(serialize($rows))->not->toContain('synthetic plaintext');

    $settings->forget('token');
    $tombstones = $memo->rows($scope, 'app');
    expect($settings->secret('token'))->toBeNull()
        ->and($memo->rows($scope, 'app'))->toBe($tombstones)
        ->and($tombstones->get('token')->getRawOriginal('value'))->toBeNull();
});

it('keeps invalid secret rows out of the memo', function (?string $encryption, string $value): void {
    app(Settings::class)->set(['name' => 'public row before the invalid secret']);
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token', 'value' => $value, 'encryption' => $encryption,
    ]);

    $memo = app(SettingsGroupMemo::class);
    $scope = ScopeRef::instance();
    expect($memo->rows($scope, 'app'))->not->toBe($memo->rows($scope, 'app'))
        ->and(fn () => app(Settings::class)->secret('token'))->toThrow(UndecryptableSetting::class);
})->with([
    'unmarked plaintext' => [null, '"synthetic plaintext"'],
    'mislabeled plaintext' => ['laravel-v1', '"synthetic plaintext"'],
    'nonstring encrypted value' => ['laravel-v1', 'false'],
]);

it('keeps encrypted strings missing their encryption marker out of the memo', function (): void {
    $ciphertext = app(StringEncrypter::class)->encryptString('synthetic unmarked secret');
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token', 'value' => json_encode($ciphertext, JSON_THROW_ON_ERROR),
    ]);

    $memo = app(SettingsGroupMemo::class);
    $scope = ScopeRef::instance();
    expect($memo->rows($scope, 'app'))->not->toBe($memo->rows($scope, 'app'))
        ->and(fn () => app(Settings::class)->secret('token'))->toThrow(UndecryptableSetting::class);
});

it('memoizes declared values once despite undeclared, misgrouped or mis-scoped rows', function (string $key): void {
    $scope = ScopeRef::of('user', '42');
    $settings = app(Settings::class);
    $settings->set(['name' => 'kept'], $scope);
    DB::table('settings')->insert([
        'scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => $key, 'value' => '"synthetic ignored value"',
    ]);
    DB::enableQueryLog();

    try {
        for ($index = 0; $index < 5; $index++) {
            expect($settings->string('name', $scope))->toBe('kept');
        }

        expect(DB::getQueryLog())->toHaveCount(1)
            ->and($settings->all('app', $scope))->toBe(['name' => 'kept']);
        $memo = app(SettingsGroupMemo::class);
        $rows = $memo->rows($scope, 'app');
        expect($rows->keys()->all())->toBe(['name'])
            ->and(serialize($rows))->not->toContain('synthetic ignored value');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with(['undeclared', 'footer', 'token']);
