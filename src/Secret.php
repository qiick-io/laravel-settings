<?php

declare(strict_types=1);

namespace Qiick\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/** @api Holds encrypted setting data and redacts it in diagnostic output and serialization. */
final readonly class Secret implements JsonSerializable, Stringable
{
    private const string REDACTED = '[redacted]';

    private function __construct(#[SensitiveParameter] private string $ciphertext) {}

    /** @internal */
    public static function fromCiphertext(#[SensitiveParameter] string $ciphertext): self
    {
        return new self($ciphertext);
    }

    public function reveal(): string
    {
        try {
            return app(StringEncrypter::class)->decryptString($this->ciphertext);
        } catch (DecryptException) {
            throw new UndecryptableSetting('The settings secret could not be decrypted.');
        }
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /** @return array{value: string} */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('A serialized setting secret cannot be restored.');
    }
}
