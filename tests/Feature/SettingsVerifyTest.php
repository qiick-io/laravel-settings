<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Definition;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

enum VerifiedSettingsPolicy: string
{
    case Required = 'required';
}

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app()->instance('verification-settings-schema', new ArraySettingsSchema([
        Definition::string('name'),
        Definition::string('token')->secret(),
        Definition::string('secondary')->secret(),
        Definition::enum('policy', VerifiedSettingsPolicy::class),
    ]));
    app()->tag('verification-settings-schema', SettingsSchema::class);
});

it('verifies declared rows, encrypted values and cleared secrets', function (): void {
    $settings = app(Settings::class);
    $settings->set(['name' => 'plain setting', 'token' => 'synthetic secret', 'secondary' => null, 'policy' => VerifiedSettingsPolicy::Required]);

    $this->artisan('settings:verify')
        ->expectsOutput('Verified 4 settings rows.')
        ->assertSuccessful();
});

it('fails verification for rows that cannot round-trip through the declared settings API', function (string $policy): void {
    DB::table('settings')->insert([
        ['scope' => 'instance', 'group' => 'app', 'key' => 'policy', 'value' => $policy, 'encryption' => null],
        ['scope' => 'instance', 'group' => 'app', 'key' => 'name', 'value' => json_encode(app(StringEncrypter::class)->encryptString('synthetic secret'), JSON_THROW_ON_ERROR), 'encryption' => 'laravel-v1'],
    ]);

    $this->artisan('settings:verify')
        ->expectsOutput('2 settings rows failed verification.')
        ->assertExitCode(1);
})->with(['"invalid backing value"', '9', 'false']);

it('fails verification without printing stored values or undeclared identifiers', function (): void {
    DB::table('settings')->insert([
        ['scope' => 'instance', 'group' => 'app', 'key' => 'token', 'value' => '"synthetic plaintext"', 'encryption' => null],
        ['scope' => 'instance', 'group' => 'app', 'key' => 'secondary', 'value' => '"synthetic invalid ciphertext"', 'encryption' => 'laravel-v1'],
        ['scope' => 'instance', 'group' => 'app', 'key' => 'synthetic undeclared identifier', 'value' => null, 'encryption' => null],
    ]);

    $this->artisan('settings:verify')
        ->expectsOutput('3 settings rows failed verification.')
        ->doesntExpectOutputToContain('synthetic')
        ->assertExitCode(1);
});

it('fails verification if the row uses a different group or an unauthorized scope', function (): void {
    DB::table('settings')->insert([
        ['scope' => 'instance', 'scope_id' => '', 'group' => 'other', 'key' => 'name', 'value' => null],
        ['scope' => 'user', 'scope_id' => '42', 'group' => 'app', 'key' => 'name', 'value' => null],
        ['scope' => 'instance', 'scope_id' => '42', 'group' => 'app', 'key' => 'name', 'value' => null],
    ]);

    $this->artisan('settings:verify')
        ->expectsOutput('3 settings rows failed verification.')
        ->assertExitCode(1);
});

it('reports a database inspection failure without connection details', function (): void {
    DB::statement('DROP TABLE settings');

    $this->artisan('settings:verify')
        ->expectsOutput('Could not verify the settings store.')
        ->doesntExpectOutputToContain('SQLSTATE')
        ->assertExitCode(1);
});

it('rejects a single encrypted row with an unsupported algorithm', function (): void {
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token',
        'value' => json_encode(app(StringEncrypter::class)->encryptString('synthetic secret'), JSON_THROW_ON_ERROR),
        'encryption' => 'future-v2',
    ]);

    $this->artisan('settings:verify')
        ->expectsOutput('1 settings rows failed verification.')
        ->assertExitCode(1);
});
