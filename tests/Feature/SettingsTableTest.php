<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('creates the settings columns and named natural-key indexes', function (): void {
    $columns = array_column(Schema::getColumns('settings'), null, 'name');
    $indexes = array_column(Schema::getIndexes('settings'), null, 'name');

    expect(array_keys($columns))->toBe([
        'id', 'scope', 'scope_id', 'group', 'key', 'value', 'encryption', 'revision', 'metadata', 'created_at', 'updated_at',
    ])
        ->and($indexes['settings_scope_unique']['unique'])->toBeTrue()
        ->and($indexes['settings_scope_unique']['columns'])->toBe(['scope', 'scope_id', 'group', 'key'])
        ->and($indexes)->not->toHaveKey('settings_scope_group_index');

    foreach (['id', 'scope', 'scope_id', 'group', 'key', 'revision'] as $column) {
        expect($columns[$column]['nullable'])->toBeFalse();
    }

    foreach (['value', 'encryption', 'metadata', 'created_at', 'updated_at'] as $column) {
        expect($columns[$column]['nullable'])->toBeTrue();
    }
});

it('supplies the instance scope and initial revision defaults', function (): void {
    $id = DB::table('settings')->insertGetId(['scope' => 'instance', 'group' => 'app', 'key' => 'enabled']);
    $row = DB::table('settings')->find($id);

    expect($id)->toBeGreaterThan(0)
        ->and($row->scope_id)->toBe('')
        ->and($row->revision)->toEqual(1)
        ->and($row->value)->toBeNull()
        ->and($row->encryption)->toBeNull()
        ->and($row->metadata)->toBeNull()
        ->and($row->created_at)->toBeNull()
        ->and($row->updated_at)->toBeNull();
});

it('rejects a null scope ID', function (): void {
    try {
        DB::table('settings')->insert(['scope' => 'instance', 'scope_id' => null, 'group' => 'app', 'key' => 'enabled']);
        test()->fail('An explicit NULL scope ID must fail.');
    } catch (QueryException $exception) {
        expect((string) $exception->getCode())->toBeIn(['23000', '23502']);
    }
});

it('rejects a duplicate instance setting through the unique constraint', function (): void {
    $setting = ['scope' => 'instance', 'scope_id' => '', 'group' => 'app', 'key' => 'enabled'];
    DB::table('settings')->insert($setting);

    expect(fn () => DB::table('settings')->insert($setting))->toThrow(UniqueConstraintViolationException::class);
});

it('distinguishes every case-sensitive part of the natural key', function (): void {
    $setting = ['scope' => 'user', 'scope_id' => 'example', 'group' => 'app', 'key' => 'enabled'];

    DB::table('settings')->insert([
        $setting,
        array_replace($setting, ['scope' => 'User']),
        array_replace($setting, ['scope_id' => 'Example']),
        array_replace($setting, ['group' => 'App']),
        array_replace($setting, ['key' => 'Enabled']),
    ]);

    expect(DB::table('settings')->count())->toBe(5);
});

it('rolls back and recreates the package table through Artisan', function (): void {
    $this->artisan('migrate:rollback')->assertSuccessful();
    expect(Schema::hasTable('settings'))->toBeFalse();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('settings'))->toBeTrue();
});
