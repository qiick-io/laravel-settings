<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Settings\Definition;
use Qiick\Settings\Events\SettingsUpdatedEvent;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\StaleSetting;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app()->instance('event-settings-schema', new ArraySettingsSchema([
        Definition::string('name')->scopes('organisation'),
        Definition::string('token')->scopes('organisation')->secret(),
        Definition::string('42')->scopes('organisation')->in('display'),
    ]));
    app()->tag('event-settings-schema', SettingsSchema::class);
});

it('dispatches one event per group containing only scope, group and key names', function (): void {
    Event::fake([SettingsUpdatedEvent::class]);
    $scope = ScopeRef::organisation('ad823eed-0a8e-41e5-8567-e14e67d81cab');
    app(Settings::class)->set(['name' => 'synthetic public value', 'token' => 'synthetic secret', '42' => 'display value'], $scope);

    Event::assertDispatchedTimes(SettingsUpdatedEvent::class, 2);
    Event::assertDispatched(SettingsUpdatedEvent::class, fn (SettingsUpdatedEvent $event): bool => get_object_vars($event) === [
        'scope' => $scope, 'group' => 'app', 'keys' => ['name', 'token'],
    ]);
    Event::assertDispatched(SettingsUpdatedEvent::class, fn (SettingsUpdatedEvent $event): bool => get_object_vars($event) === [
        'scope' => $scope, 'group' => 'display', 'keys' => ['42'],
    ]);
});

it('dispatches after the outer commit with the affected memo cleared', function (): void {
    $settings = app(Settings::class);
    $scope = ScopeRef::organisation('ad823eed-0a8e-41e5-8567-e14e67d81cab');
    $settings->set(['name' => 'before'], $scope);
    expect($settings->string('name', $scope))->toBe('before');
    $seen = [];
    Event::listen(SettingsUpdatedEvent::class, function (SettingsUpdatedEvent $event) use ($settings, &$seen): void {
        $seen[] = [DB::transactionLevel(), $event->keys, $settings->string('name', $event->scope)];
    });
    DB::beginTransaction();

    try {
        $settings->forget('name', $scope);
        expect($seen)->toBe([]);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    expect($seen)->toBe([[0, ['name'], null]]);
});

it('emits no update event for rollback, stale revisions, or an empty write', function (): void {
    Event::fake([SettingsUpdatedEvent::class]);
    $settings = app(Settings::class);
    $scope = ScopeRef::organisation('ad823eed-0a8e-41e5-8567-e14e67d81cab');
    DB::beginTransaction();

    try {
        $settings->set(['name' => 'aborted'], $scope);
    } finally {
        DB::rollBack();
    }

    expect(fn () => $settings->set(['name' => 'stale'], $scope, expectedRevision: 1))->toThrow(StaleSetting::class);
    $settings->set([], $scope);
    Event::assertNotDispatched(SettingsUpdatedEvent::class);
});
