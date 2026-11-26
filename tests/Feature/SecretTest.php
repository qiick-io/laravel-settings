<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\StringEncrypter;
use Qiick\Settings\Secret;
use Qiick\Settings\UndecryptableSetting;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
});

it('holds only ciphertext and reveals the plaintext on demand', function (): void {
    $plaintext = 'synthetic secret value';
    $ciphertext = app(StringEncrypter::class)->encryptString($plaintext);
    $secret = Secret::fromCiphertext($ciphertext);

    expect(array_values(get_mangled_object_vars($secret)))->toBe([$ciphertext])
        ->and($secret->reveal())->toBe($plaintext)
        ->and(array_values(get_mangled_object_vars($secret)))->toBe([$ciphertext]);
});

it('redacts secrets in strings JSON debugger and PHP serialization', function (): void {
    $plaintext = 'synthetic value kept private';
    $ciphertext = app(StringEncrypter::class)->encryptString($plaintext);
    $secret = Secret::fromCiphertext($ciphertext);

    ob_start();

    try {
        var_dump($secret);
        $debug = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $serialized = serialize($secret);

    expect((string) $secret)->toBe('[redacted]')
        ->and(json_encode($secret))->toBe('"[redacted]"')
        ->and($debug)->toContain('[redacted]')->not->toContain($plaintext)->not->toContain($ciphertext)
        ->and($serialized)->toContain('[redacted]')->not->toContain($plaintext)->not->toContain($ciphertext)
        ->and(fn () => unserialize($serialized))->toThrow(LogicException::class);
});

it('redacts an invalid payload from decryption exceptions', function (): void {
    $payload = 'synthetic invalid payload';
    $secret = Secret::fromCiphertext($payload);

    try {
        $secret->reveal();
        test()->fail('Invalid ciphertext was accepted.');
    } catch (UndecryptableSetting $exception) {
        expect((string) $exception)->not->toContain($payload)
            ->and($exception->getPrevious())->toBeNull();
    }
});
