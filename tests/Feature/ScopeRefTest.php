<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Settings\ScopeRef;
use Qiick\Settings\ScopeRegistry;

it('uses an empty identifier for the instance', function (): void {
    expect(ScopeRef::instance()->scope)->toBe('instance')
        ->and(ScopeRef::instance()->id)->toBe('')
        ->and(ScopeRef::of('instance', ''))->toEqual(ScopeRef::instance());
});

it('canonicalizes a valid organisation UUID for both factories', function (): void {
    $uuid = '019AC1EB-C03F-76F8-811E-9DE4E8E953C8';
    $organisation = ScopeRef::organisation($uuid);

    expect($organisation->scope)->toBe('organisation')
        ->and($organisation->id)->toBe(strtolower($uuid))
        ->and(ScopeRef::of('organisation', $uuid))->toEqual($organisation);
});

it('rejects invalid organisation identifiers for both factories', function (): void {
    expect(fn () => ScopeRef::organisation('synthetic-invalid-id'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScopeRef::of('organisation', 'synthetic-invalid-id'))->toThrow(InvalidArgumentException::class);
});

it('converts an authenticatable key to a string and preserves existing string keys', function (int|string $id, string $identifier): void {
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->once()->andReturn($id);
    $scope = ScopeRef::user($user);

    expect($scope->scope)->toBe('user')
        ->and($scope->id)->toBe($identifier)
        ->and(ScopeRef::of('user', $identifier))->toEqual($scope);
})->with([
    'integer' => [42, '42'],
    'string with a leading zero' => ['042', '042'],
    'UUID' => ['019ac1eb-c03f-76f8-811e-9de4e8e953c8', '019ac1eb-c03f-76f8-811e-9de4e8e953c8'],
]);

it('rejects an authenticatable without a supported key', function (mixed $id): void {
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->once()->andReturn($id);

    expect(fn () => ScopeRef::user($user))->toThrow(InvalidArgumentException::class);
})->with([[null], [true], [[]]]);

it('resolves custom scopes from the current application', function (): void {
    expect(fn () => ScopeRef::of('project', ''))->toThrow(InvalidArgumentException::class);

    $registry = $this->app->make(ScopeRegistry::class);
    $registry->register('project', instanceLike: true);
    $registry->register('team');

    expect(ScopeRef::of('project', '')->scope)->toBe('project')
        ->and(ScopeRef::of('project', '')->id)->toBe('')
        ->and(ScopeRef::of('team', 'team-1')->scope)->toBe('team')
        ->and(ScopeRef::of('team', 'team-1')->id)->toBe('team-1');
});

it('rejects a nonempty identifier for an instance-like scope', function (): void {
    $this->app->make(ScopeRegistry::class)->register('project', instanceLike: true);

    expect(fn () => ScopeRef::of('instance', '42'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ScopeRef::of('project', '42'))->toThrow(InvalidArgumentException::class);
});

it('rejects identifiers that cannot round trip through the scope column', function (string $id): void {
    expect(fn () => ScopeRef::of('user', $id))->toThrow(InvalidArgumentException::class);
})->with([
    'empty identifier' => [''],
    'overlength identifier' => [str_repeat('a', 37)],
    'invalid UTF-8' => ["\xff"],
    'null byte' => ["a\0b"],
    'space padding' => ['id '],
]);

it('accepts the 36-character scope identifier limit', function (): void {
    $id = str_repeat('é', 36);

    expect(ScopeRef::of('user', $id)->id)->toBe($id);
});

it('rejects invalid and duplicate scope registrations', function (): void {
    $registry = $this->app->make(ScopeRegistry::class);

    expect(fn () => $registry->register(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(str_repeat('a', 33)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('project '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('instance'))->toThrow(InvalidArgumentException::class);

    $name = str_repeat('a', 32);
    $registry->register($name);

    expect(ScopeRef::of($name, 'id')->scope)->toBe($name)
        ->and(fn () => $registry->register($name, instanceLike: true))->toThrow(InvalidArgumentException::class);
});
