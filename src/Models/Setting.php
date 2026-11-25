<?php

declare(strict_types=1);

namespace Qiick\Settings\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Stores JSON values or ciphertext without interpreting the declared setting type.
 *
 * @internal
 */
final class Setting extends Model
{
    public const string TABLE = 'settings';

    protected $table = self::TABLE;

    /** @return non-empty-array<array-key, string> */
    public function getHidden(): array
    {
        return [...parent::getHidden(), 'id', 'value', 'metadata'];
    }

    /** Database timestamps are stored without a timezone and always represent UTC. */
    protected function asDateTime($value): DateTimeInterface
    {
        if (is_string($value) && ! is_numeric($value)) {
            $value = Date::parse($value, 'UTC');
        }

        return parent::asDateTime($value)->utc();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'revision' => 'integer',
            'metadata' => 'array',
        ];
    }
}
