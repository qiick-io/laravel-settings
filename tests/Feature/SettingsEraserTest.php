<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Qiick\Settings\Definition;
use Qiick\Settings\Erasure\ErasureReport;
use Qiick\Settings\Erasure\OrganisationScopeUnsupportedException;
use Qiick\Settings\Erasure\SettingsEraser;
use Qiick\Settings\Events\SettingsUpdatedEvent;
use Qiick\Settings\Events\SubjectErasedEvent;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

function erasedSettingsUserPublicId(): string
{
    return 'e8c6c069-4527-41da-9483-c65bbdad5d6b';
}

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app()->instance('erasure-settings-schema', new ArraySettingsSchema([
        Definition::string('name')->scopes('instance', 'user', 'organisation'),
        Definition::string('token')->in('credentials')->scopes('user')->secret(),
    ]));
    app()->tag('erasure-settings-schema', SettingsSchema::class);
});

it('keeps the fleet signature and report shape', function (): void {
    $eraser = new ReflectionClass(SettingsEraser::class);
    $method = $eraser->getMethod('erase');
    $shape = array_map(
        static fn (ReflectionParameter $parameter): array => [
            $parameter->getName(), (string) $parameter->getType(), $parameter->isDefaultValueAvailable() ? [$parameter->getDefaultValue()] : [],
        ],
        $method->getParameters(),
    );

    expect($eraser->isFinal())->toBeTrue()
        ->and($method->isPublic())->toBeTrue()
        ->and((string) $method->getReturnType())->toBe(ErasureReport::class)
        ->and($shape)->toBe([
            ['userId', 'string', []], ['userPublicId', 'string', []], ['mode', 'string', []],
            ['publicIds', 'array', [[]]], ['identifiers', 'array', [[]]], ['organisationId', '?string', [null]], ['dryRun', 'bool', [false]],
        ]);

    $report = new ErasureReport('qiick/laravel-settings', 'anonymise', true, [
        'settings' => ['deleted' => 2, 'anonymised' => 1, 'kept' => 1, 'reason' => 'legal_obligation'],
        'cache:settings' => ['deleted' => 1, 'anonymised' => 0, 'kept' => 0, 'reason' => null],
    ]);
    expect($report->changed())->toBe(4)->and($report->toArray()['dry_run'])->toBeTrue();
});

it('classifies all package columns for fleet erasure', function (): void {
    $classified = [
        'settings' => [
            'id' => 'other', 'scope' => 'other', 'scope_id' => 'personal', 'group' => 'other', 'key' => 'other',
            'value' => 'personal', 'encryption' => 'other', 'revision' => 'other', 'metadata' => 'personal',
            'created_at' => 'other', 'updated_at' => 'other',
        ],
        'settings_write_locks' => ['id' => 'other'],
    ];

    foreach ($classified as $table => $columns) {
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing(array_keys($columns));
    }
});

it('deletes exactly the person’s settings and memo in either full erasure mode', function (string $mode): void {
    $settings = app(Settings::class);
    $person = ScopeRef::of('user', '042');
    $other = ScopeRef::of('user', '42');
    $organisation = ScopeRef::organisation(erasedSettingsUserPublicId());
    $settings->set(['name' => 'synthetic person name', 'token' => 'synthetic person secret'], $person);
    $settings->set(['name' => 'other person'], $other);
    $settings->set(['name' => 'organisation'], $organisation);
    $settings->set(['name' => 'global']);
    DB::table('settings')->where('scope', 'user')->where('scope_id', '042')->update([
        'metadata' => json_encode(['private' => [erasedSettingsUserPublicId()]], JSON_THROW_ON_ERROR),
    ]);
    $otherRows = DB::table('settings')->where('scope_id', '!=', '042')->orderBy('id')->get()->all();
    expect($settings->string('name', $person))->toBe('synthetic person name')
        ->and($settings->secret('token', $person)->reveal())->toBe('synthetic person secret');
    Event::fake([SubjectErasedEvent::class, SettingsUpdatedEvent::class]);
    $eraser = app(SettingsEraser::class);

    $preview = $eraser->erase('042', erasedSettingsUserPublicId(), $mode, dryRun: true);
    expect($preview->changed())->toBe(4)
        ->and($settings->string('name', $person))->toBe('synthetic person name')
        ->and(DB::table('settings')->count())->toBe(5);
    Event::assertNotDispatched(SubjectErasedEvent::class);

    $report = $eraser->erase('042', erasedSettingsUserPublicId(), $mode, identifiers: ['synthetic person name']);
    expect($report)->toBeInstanceOf(ErasureReport::class)
        ->and($report->toArray())->toBe([
            'package' => 'qiick/laravel-settings', 'mode' => $mode, 'dry_run' => false,
            'stores' => [
                'settings' => ['deleted' => 2, 'anonymised' => 0, 'kept' => 0, 'reason' => null],
                'cache:settings' => ['deleted' => 2, 'anonymised' => 0, 'kept' => 0, 'reason' => null],
            ],
        ])
        ->and($report->stores)->toBe($preview->stores)
        ->and(DB::table('settings')->orderBy('id')->get()->all())->toEqual($otherRows);
    Event::assertDispatchedTimes(SubjectErasedEvent::class, 1);
    Event::assertDispatched(SubjectErasedEvent::class, fn (SubjectErasedEvent $event): bool => get_object_vars($event) === ['report' => $report]);
    Event::assertNotDispatched(SettingsUpdatedEvent::class);

    expect($eraser->erase('042', erasedSettingsUserPublicId(), $mode)->changed())->toBe(0)
        ->and($settings->string('name', $person))->toBeNull()
        ->and($settings->secret('token', $person))->toBeNull();
    Event::assertDispatchedTimes(SubjectErasedEvent::class, 2);
})->with(['delete', 'anonymise']);

it('rejects organisation-only erasure before touching global user settings or memo', function (bool $isDryRun): void {
    $settings = app(Settings::class);
    $person = ScopeRef::of('user', '042');
    $settings->set(['name' => 'global user settings'], $person);
    expect($settings->string('name', $person))->toBe('global user settings');
    Event::fake([SubjectErasedEvent::class]);
    DB::enableQueryLog();

    try {
        expect(fn () => app(SettingsEraser::class)->erase('042', erasedSettingsUserPublicId(), 'delete', organisationId: erasedSettingsUserPublicId(), dryRun: $isDryRun))
            ->toThrow(OrganisationScopeUnsupportedException::class, 'Settings erasure does not support an organisation scope.')
            ->and($settings->string('name', $person))->toBe('global user settings')
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    Event::assertNotDispatched(SubjectErasedEvent::class);
})->with([false, true]);

it('dispatches a zero report when the only memo entry is an empty group', function (): void {
    Event::fake([SubjectErasedEvent::class]);
    $settings = app(Settings::class);
    expect($settings->string('name', ScopeRef::of('user', '042')))->toBeNull();
    $report = app(SettingsEraser::class)->erase('042', erasedSettingsUserPublicId(), 'delete');
    $empty = ['deleted' => 0, 'anonymised' => 0, 'kept' => 0, 'reason' => null];
    expect($report->changed())->toBe(0)->and($report->stores)->toBe(['settings' => $empty, 'cache:settings' => $empty]);
    Event::assertDispatchedTimes(SubjectErasedEvent::class, 1);
});

it('clears memo and dispatches only after a caller’s transaction commits', function (): void {
    $settings = app(Settings::class);
    $person = ScopeRef::of('user', '042');
    $settings->set(['name' => 'still set'], $person);
    expect($settings->string('name', $person))->toBe('still set');
    $seen = [];
    Event::listen(SubjectErasedEvent::class, function (SubjectErasedEvent $event) use ($settings, $person, &$seen): void {
        $seen[] = [DB::transactionLevel(), $settings->string('name', $person), $event->report->changed()];
    });
    $eraser = app(SettingsEraser::class);
    DB::beginTransaction();

    try {
        $eraser->erase('042', erasedSettingsUserPublicId(), 'delete');
        expect($seen)->toBe([])->and($settings->string('name', $person))->toBeNull();
    } finally {
        DB::rollBack();
    }

    expect($seen)->toBe([])->and($settings->string('name', $person))->toBe('still set');
    DB::beginTransaction();

    try {
        $eraser->erase('042', erasedSettingsUserPublicId(), 'delete');
        expect($seen)->toBe([]);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    expect($seen)->toBe([[0, null, 2]]);
});

it('rolls back a failed delete and keeps personal data out of the query exception', function (): void {
    $settings = app(Settings::class);
    $person = ScopeRef::of('user', '042');
    $settings->set(['name' => 'synthetic personal value'], $person);
    expect($settings->string('name', $person))->toBe('synthetic personal value');
    Event::fake([SubjectErasedEvent::class]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'delete from') && str_contains($query->sql, 'settings')) {
            throw new QueryException('settings', $query->sql, $query->bindings, new RuntimeException('synthetic personal value'));
        }
    });

    try {
        app(SettingsEraser::class)->erase('042', erasedSettingsUserPublicId(), 'delete');
        test()->fail('The eraser must propagate a failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Could not erase user settings.')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(DB::table('settings')->count())->toBe(1)
        ->and($settings->string('name', $person))->toBe('synthetic personal value');
    Event::assertNotDispatched(SubjectErasedEvent::class);
});

it('uses the configured canonical identifier column and ignores the legacy numeric value', function (): void {
    Schema::table('settings', fn ($table) => $table->renameColumn('scope_id', 'scope_ref'));
    Schema::table('settings', fn ($table) => $table->unsignedBigInteger('scope_id')->nullable());
    config(['settings.columns.scope_id' => 'scope_ref', 'settings.columns.legacy_scope_id' => 'scope_id']);
    $settings = app(Settings::class);
    $settings->set(['name' => 'erase'], ScopeRef::of('user', '042'));
    $settings->set(['name' => 'keep'], ScopeRef::of('user', '42'));

    $report = app(SettingsEraser::class)->erase('042', erasedSettingsUserPublicId(), 'delete');
    expect($report->stores['settings']['deleted'])->toBe(1)
        ->and(DB::table('settings')->pluck('scope_ref')->all())->toBe(['42']);
});

it('rejects malformed fleet erasure arguments without quoting those arguments', function (array $arguments, string $message): void {
    $arguments = array_replace(['userId' => '042', 'userPublicId' => erasedSettingsUserPublicId(), 'mode' => 'delete'], $arguments);
    expect(fn () => app(SettingsEraser::class)->erase(...$arguments))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'mode' => [['mode' => 'private-mode'], 'The erasure mode must be delete or anonymise.'],
    'subject public ID' => [['userPublicId' => 'private-id'], 'Every public ID must be a UUID.'],
    'additional public ID' => [['publicIds' => ['private-id']], 'Every public ID must be a UUID.'],
    'additional ID type' => [['publicIds' => [42]], 'Every public ID must be a UUID.'],
    'empty identifier' => [['identifiers' => [' ']], 'Every identifier must be a non-empty string.'],
    'identifier type' => [['identifiers' => [42]], 'Every identifier must be a non-empty string.'],
]);
