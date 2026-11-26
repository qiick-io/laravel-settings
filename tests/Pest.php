<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Settings\Tests\Support\PackageTestCase;

pest()->extend(PackageTestCase::class)->in('Feature');
pest()->extend(PackageTestCase::class)->use(DatabaseMigrations::class)->in('Erasure');
