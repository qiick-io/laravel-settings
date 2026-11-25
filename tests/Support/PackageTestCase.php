<?php

declare(strict_types=1);

namespace Qiick\Settings\Tests\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\TestCase;
use Qiick\Settings\Laravel\SettingsServiceProvider;

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
}
