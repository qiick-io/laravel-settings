<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Definition;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

it('sees a separate process commit on the next request', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('The process test shares the dedicated server database.');
    }

    app()->instance('process-settings-schema', new ArraySettingsSchema([Definition::string('name')]));
    app()->tag('process-settings-schema', SettingsSchema::class);
    $settings = app(Settings::class);
    $settings->set(['name' => 'first process']);
    expect($settings->string('name'))->toBe('first process');

    DB::disconnect();
    $pid = pcntl_fork();

    if ($pid === 0) {
        try {
            app()->forgetScopedInstances();
            app(Settings::class)->set(['name' => 'second process']);
            DB::disconnect();
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }

    expect($pid)->toBeGreaterThan(0);
    pcntl_waitpid($pid, $status);
    expect(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0)
        ->and($settings->string('name'))->toBe('first process');

    app()->forgetScopedInstances();
    expect(app(Settings::class)->string('name'))->toBe('second process');
});
