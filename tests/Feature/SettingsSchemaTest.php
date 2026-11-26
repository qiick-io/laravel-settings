<?php

declare(strict_types=1);

use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRegistry;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Support\DefinitionRegistry;
use Qiick\Settings\Testing\ArraySettingsSchema;

it('collects definitions from every tagged schema in the application', function (): void {
    $this->app->make(ScopeRegistry::class)->register('project', instanceLike: true);
    $enabled = Definition::bool('enabled');
    $modules = Definition::json('modules')->scopes('project');
    $this->app->instance('first-schema', new ArraySettingsSchema([$enabled]));
    $this->app->instance('second-schema', new ArraySettingsSchema([$modules]));
    $this->app->tag(['first-schema', 'second-schema'], SettingsSchema::class);

    expect($this->app->make(DefinitionRegistry::class)->definitions())->toBe([
        'enabled' => $enabled,
        'modules' => $modules,
    ]);
});

it('resolves tagged schemas once per scope and refreshes them for the next request or job', function (): void {
    $builds = 0;
    $this->app->bind('transient-schema', function () use (&$builds): ArraySettingsSchema {
        $builds++;

        return new ArraySettingsSchema([Definition::int('limit')->default($builds)]);
    });
    $this->app->tag('transient-schema', SettingsSchema::class);

    $registry = $this->app->make(DefinitionRegistry::class);
    expect($registry->definitions()['limit']->defaultValue())->toBe(1)
        ->and($registry->definitions()['limit']->defaultValue())->toBe(1)
        ->and($this->app->make(DefinitionRegistry::class))->toBe($registry)
        ->and($builds)->toBe(1);

    $this->app->forgetScopedInstances();
    $nextRegistry = $this->app->make(DefinitionRegistry::class);
    expect($nextRegistry)->not->toBe($registry)
        ->and($nextRegistry->definitions()['limit']->defaultValue())->toBe(2)
        ->and($builds)->toBe(2);
});

it('rejects duplicate setting keys across groups and schemas', function (): void {
    $first = Mockery::mock(SettingsSchema::class);
    $second = Mockery::mock(SettingsSchema::class);
    $first->shouldReceive('definitions')->once()->andReturn([Definition::bool('enabled')]);
    $second->shouldReceive('definitions')->once()->andReturn([Definition::bool('enabled')->in('security')]);
    $this->app->instance('first-schema', $first);
    $this->app->instance('second-schema', $second);
    $this->app->tag(['first-schema', 'second-schema'], SettingsSchema::class);

    expect(fn () => $this->app->make(DefinitionRegistry::class)->definitions())
        ->toThrow(InvalidArgumentException::class, 'Settings key enabled is declared more than once.');
});

it('detects an unknown scope in a tagged definition during the deploy check', function (): void {
    $schema = Mockery::mock(SettingsSchema::class);
    $schema->shouldReceive('definitions')->once()->andReturn([Definition::bool('enabled')->scopes('unknown')]);
    $this->app->instance('product-schema', $schema);
    $this->app->tag('product-schema', SettingsSchema::class);

    $this->artisan('settings:check')
        ->expectsOutput('The settings scope is not registered.')
        ->assertExitCode(1);
});

it('does not expose a product exception message in the deploy check output', function (): void {
    $schema = Mockery::mock(SettingsSchema::class);
    $schema->shouldReceive('definitions')->once()->andThrow(new RuntimeException('synthetic private configuration'));
    $this->app->instance('product-schema', $schema);
    $this->app->tag('product-schema', SettingsSchema::class);

    $this->artisan('settings:check')
        ->expectsOutput('A settings schema failed to declare its definitions.')
        ->doesntExpectOutput('synthetic private configuration')
        ->assertExitCode(1);
});

it('preserves a failed schema’s cause and retries the schema on the next resolution', function (): void {
    $cause = new RuntimeException('synthetic schema error');
    $schema = Mockery::mock(SettingsSchema::class);
    $schema->shouldReceive('definitions')->once()->andThrow($cause);
    $schema->shouldReceive('definitions')->once()->andReturn([Definition::string('available')]);
    $this->app->instance('recoverable-schema', $schema);
    $this->app->tag('recoverable-schema', SettingsSchema::class);
    $registry = $this->app->make(DefinitionRegistry::class);

    try {
        $registry->definitions();
        test()->fail('Schema resolution must throw the typed error.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->toBe('A settings schema failed to declare its definitions.')
            ->and($exception->getPrevious())->toBe($cause);
    }

    expect($registry->definitions())->toHaveKey('available');
});

it('rejects services and definitions that do not implement the schema contract', function (mixed $service, string $message): void {
    $this->app->instance('product-schema', $service);
    $this->app->tag('product-schema', SettingsSchema::class);

    expect(fn () => $this->app->make(DefinitionRegistry::class)->definitions())
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'tagged service' => [new stdClass, 'A tagged settings schema must implement SettingsSchema.'],
    'definition' => [new class implements SettingsSchema
    {
        public function definitions(): array
        {
            return ['invalid'];
        }
    }, 'A settings schema must return Definition instances.'],
]);
