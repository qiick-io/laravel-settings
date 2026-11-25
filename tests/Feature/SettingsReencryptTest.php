<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Definition;
use Qiick\Settings\Settings;
use Qiick\Settings\SettingsSchema;
use Qiick\Settings\Testing\ArraySettingsSchema;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
    app()->instance('rotation-settings-schema', new ArraySettingsSchema([
        Definition::string('token')->secret(),
        Definition::string('current')->secret(),
    ]));
    app()->tag('rotation-settings-schema', SettingsSchema::class);
});

it('rotates previous keys only and leaves the database untouched in dry run', function (): void {
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic previous-key value']);
    $retiredCiphertext = app(StringEncrypter::class)->encryptString('synthetic undeclared value');
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'retired', 'encryption' => 'laravel-v1',
        'value' => json_encode($retiredCiphertext, JSON_THROW_ON_ERROR),
    ]);
    $previousKey = config('app.key');
    $currentKey = random_bytes(32);
    config(['app.key' => 'base64:'.base64_encode($currentKey), 'app.previous_keys' => [$previousKey]]);
    app()->forgetInstance('encrypter');
    $settings->set(['current' => 'synthetic current-key value']);
    $oldCiphertext = DB::table('settings')->where('key', 'token')->value('value');
    $currentCiphertext = DB::table('settings')->where('key', 'current')->value('value');
    $revision = $settings->revision('app');
    expect($settings->secret('token')->reveal())->toBe('synthetic previous-key value');

    $this->artisan('settings:reencrypt', ['--dry-run' => true])
        ->expectsOutput('2 settings rows need re-encryption.')
        ->assertSuccessful();

    expect(DB::table('settings')->where('key', 'token')->value('value'))->toBe($oldCiphertext)
        ->and($settings->revision('app'))->toBe($revision);

    Date::setTestNow('2026-11-25 11:00:00 UTC');
    $database = DB::connection()->getPdo();
    $database->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    try {
        $this->artisan('settings:reencrypt')
            ->expectsOutput('Re-encrypted 2 settings rows.')
            ->assertSuccessful();
    } finally {
        Date::setTestNow();
        $database->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    $currentOnly = new Encrypter($currentKey, 'AES-256-CBC');
    $rotated = DB::table('settings')->where('key', 'token')->value('value');
    $rotatedRetired = DB::table('settings')->where('key', 'retired')->value('value');
    expect(fn () => $currentOnly->decryptString(json_decode($oldCiphertext, true)))->toThrow(DecryptException::class)
        ->and($currentOnly->decryptString(json_decode($rotated, true)))->toBe('synthetic previous-key value')
        ->and($currentOnly->decryptString(json_decode($rotatedRetired, true)))->toBe('synthetic undeclared value')
        ->and(DB::table('settings')->where('key', 'current')->value('value'))->toBe($currentCiphertext)
        ->and(DB::table('settings')->where('key', 'token')->value('updated_at'))->toBe('2026-11-25 11:00:00');

    config(['app.previous_keys' => []]);
    app()->forgetInstance('encrypter');
    expect($settings->secret('token')->reveal())->toBe('synthetic previous-key value')
        ->and($settings->revision('app'))->toBe($revision + 2);

    $this->artisan('settings:reencrypt')
        ->expectsOutput('Re-encrypted 0 settings rows.')
        ->assertSuccessful();
});

it('reports invalid stored ciphertext without printing or changing it', function (string $value): void {
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token',
        'value' => $value, 'encryption' => 'laravel-v1',
    ]);

    $this->artisan('settings:reencrypt')
        ->expectsOutput('1 encrypted settings rows could not be read.')
        ->doesntExpectOutputToContain('synthetic')
        ->assertExitCode(1);

    expect(DB::table('settings')->where('key', 'token')->value('revision'))->toEqual(1);
})->with(['"synthetic invalid ciphertext"', 'false']);

it('continues after encountering an invalid stored scope', function (): void {
    $ciphertext = json_encode(app(StringEncrypter::class)->encryptString('synthetic secret'), JSON_THROW_ON_ERROR);
    DB::table('settings')->insert([
        ['scope' => 'unregistered', 'scope_id' => 'other', 'group' => 'app', 'key' => 'invalid', 'value' => $ciphertext, 'encryption' => 'laravel-v1'],
        ['scope' => 'instance', 'scope_id' => '', 'group' => 'app', 'key' => 'token', 'value' => $ciphertext, 'encryption' => 'laravel-v1'],
    ]);
    $previousKey = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$previousKey]]);
    app()->forgetInstance('encrypter');

    $this->artisan('settings:reencrypt')
        ->expectsOutput('Re-encrypted 1 settings rows.')
        ->expectsOutput('1 encrypted settings rows could not be read.')
        ->assertExitCode(1);
});

it('fails without modifying ciphertext when the group write lock is unavailable', function (): void {
    $ciphertext = json_encode(app(StringEncrypter::class)->encryptString('synthetic secret'), JSON_THROW_ON_ERROR);
    DB::table('settings')->insert([
        'scope' => 'instance', 'group' => 'app', 'key' => 'token', 'value' => $ciphertext, 'encryption' => 'laravel-v1',
    ]);
    DB::table('settings_write_locks')->where('id', 36)->delete();

    $this->artisan('settings:reencrypt')
        ->expectsOutput('Could not finish settings re-encryption.')
        ->assertExitCode(1);

    expect(DB::table('settings')->where('key', 'token')->value('value'))->toBe($ciphertext);
});

it('refreshes the memo after rotating a previously read secret', function (): void {
    $settings = app(Settings::class);
    $settings->set(['token' => 'synthetic rotation secret']);
    $previousKey = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$previousKey]]);
    app()->forgetInstance('encrypter');
    expect($settings->secret('token')->reveal())->toBe('synthetic rotation secret');

    $this->artisan('settings:reencrypt')->assertSuccessful();
    config(['app.previous_keys' => []]);
    app()->forgetInstance('encrypter');

    expect($settings->secret('token')->reveal())->toBe('synthetic rotation secret');
});
