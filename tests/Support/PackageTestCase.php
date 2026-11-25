<?php

declare(strict_types=1);

namespace Qiick\Settings\Tests\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\TestCase;
use Qiick\Settings\Laravel\SettingsServiceProvider;
use RuntimeException;

abstract class PackageTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $connection = getenv('GITHUB_ACTIONS') === 'true' ? getenv('DB_CONNECTION') : false;

        if ($connection === false || $connection === 'sqlite') {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.url', null);
            $app['config']->set('database.connections.sqlite.database', ':memory:');

            return;
        }

        if (! in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) || getenv('DB_DATABASE') !== 'qiick_package') {
            throw new RuntimeException('Database tests require a package-matrix service.');
        }

        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.url", null);
        $app['config']->set("database.connections.{$connection}.host", '127.0.0.1');
        $app['config']->set("database.connections.{$connection}.unix_socket", '');
        $app['config']->set("database.connections.{$connection}.database", 'qiick_package');
    }
}
