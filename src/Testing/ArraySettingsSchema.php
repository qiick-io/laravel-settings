<?php

declare(strict_types=1);

namespace Qiick\Settings\Testing;

use Qiick\Settings\Definition;
use Qiick\Settings\SettingsSchema;

/** @api Supplies a list of definitions for an application's tests. */
final readonly class ArraySettingsSchema implements SettingsSchema
{
    /** @param list<Definition> $definitions */
    public function __construct(private array $definitions) {}

    /** @return list<Definition> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}
