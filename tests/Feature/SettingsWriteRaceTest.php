<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Definition;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;
use Qiick\Settings\Tests\Support\SettingsWriteRace;

uses(DatabaseMigrations::class);

it('allows only one editor to claim revision zero on separate connections', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('The two-process race runs on each server database.');
    }

    app()->instance('race-settings-schema', new ArraySettingsSchema([
        Definition::string('left'),
        Definition::string('right'),
    ]));
    app()->tag('race-settings-schema', SettingsSchema::class);

    expect((new SettingsWriteRace)->outcomes())->toBe(['saved', 'stale'])
        ->and(app(Settings::class)->revision('app'))->toBe(1)
        ->and(DB::table('settings')->count())->toBe(1);
});
