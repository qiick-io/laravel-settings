<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Qiick\Settings\Definition;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

it('sees the committed update from a separate process using the same cache', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('The process test shares the dedicated server database.');
    }

    $path = sys_get_temp_dir().'/qiick-settings-cache-'.bin2hex(random_bytes(12));
    config(['cache.stores.settings-shared' => ['driver' => 'file', 'path' => $path], 'settings.cache.store' => 'settings-shared']);
    app()->instance('shared-settings-schema', new ArraySettingsSchema([Definition::string('name')]));
    app()->tag('shared-settings-schema', SettingsSchema::class);

    try {
        $settings = app(Settings::class);
        $settings->set(['name' => 'first process']);
        expect($settings->string('name'))->toBe('first process')
            ->and(File::allFiles($path))->toHaveCount(1);

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
            ->and(File::allFiles($path))->toBe([]);
        app()->forgetScopedInstances();
        expect(app(Settings::class)->string('name'))->toBe('second process');
    } finally {
        File::deleteDirectory($path);
    }
});
