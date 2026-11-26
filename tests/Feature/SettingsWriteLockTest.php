<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Qiick\Settings\Database\SettingsWriteLock;
use Qiick\Settings\ScopeRef;

it('takes server write locks in ascending order and touches each row once', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    $first = Mockery::mock(Builder::class);
    $second = Mockery::mock(Builder::class);
    $third = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('settings_write_locks')->times(3)->andReturn($first, $second, $third);

    foreach ([[$first, 3], [$second, 22], [$third, 36]] as [$query, $bucket]) {
        $query->shouldReceive('where')->with('id', $bucket)->once()->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $query->shouldReceive('value')->with('id')->once()->andReturn($bucket);
        $query->shouldReceive('update')->with(['id' => $bucket])->once()->andReturn(1);
    }

    (new SettingsWriteLock)->acquire($connection, ScopeRef::instance(), ['app', 'design', 'x', 'app']);
});

it('fails closed if a server write lock row is missing', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mariadb');
    $query = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('settings_write_locks')->once()->andReturn($query);
    $query->shouldReceive('where')->with('id', 36)->once()->andReturnSelf();
    $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
    $query->shouldReceive('value')->with('id')->once()->andReturnNull();
    $query->shouldNotReceive('update');

    expect(fn () => (new SettingsWriteLock)->acquire($connection, ScopeRef::instance(), ['app']))
        ->toThrow(RuntimeException::class, 'A settings write lock row is missing.');
});
