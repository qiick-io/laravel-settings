<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Qiick\Settings\Models\Setting;

uses(DatabaseMigrations::class);

it('round-trips JSON values with their original type', function (mixed $value): void {
    $setting = new Setting;
    $setting->forceFill([
        'scope' => 'instance',
        'group' => 'app',
        'key' => 'example',
        'value' => $value,
        'metadata' => ['source' => 'test'],
    ])->save();
    $stored = $setting->fresh();

    expect($stored->value)->toBe($value)
        ->and($stored->metadata)->toBe(['source' => 'test'])
        ->and($stored->revision)->toBe(1)
        ->and($stored->scope_id)->toBe('');
})->with([
    'boolean' => [false],
    'integer' => [18],
    'float' => [2.25],
    'whole float' => [2.0],
    'ciphertext string' => ['synthetic-ciphertext'],
    'list' => [[false, 'item']],
    'object' => [['item' => 8]],
    'null' => [null],
]);

it('keeps raw values and the internal ID out of model serialization', function (): void {
    $setting = new Setting;
    $setting->setRawAttributes([
        'id' => 42,
        'key' => 'example',
        'value' => '"sensitive"',
        'metadata' => '{"source":"private"}',
        'revision' => '7',
    ]);

    expect($setting->toArray())->toBe(['key' => 'example', 'revision' => 7]);

    $setting->makeVisible(['id', 'value', 'metadata'])->makeHidden('revision');

    expect($setting->toArray())->toBe(['key' => 'example']);
});

it('writes and reads timestamps in UTC when the application uses another timezone', function (): void {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');
    Date::setTestNow(CarbonImmutable::parse('2026-11-25 12:45:00', 'UTC'));

    try {
        $setting = new Setting;
        $setting->forceFill(['scope' => 'instance', 'group' => 'app', 'key' => 'example'])->save();
        $row = DB::table('settings')->find($setting->getKey());

        expect($row->created_at)->toBe('2026-11-25 12:45:00')
            ->and($row->updated_at)->toBe('2026-11-25 12:45:00')
            ->and($setting->fresh()->created_at->format(DATE_ATOM))->toBe('2026-11-25T12:45:00+00:00');
    } finally {
        Date::setTestNow();
        date_default_timezone_set($timezone);
    }
});

it('accepts Unix timestamps supplied as numeric strings', function (): void {
    $setting = new Setting;
    $setting->created_at = '1';

    expect($setting->getAttributes()['created_at'])->toBe('1970-01-01 00:00:01');
});
