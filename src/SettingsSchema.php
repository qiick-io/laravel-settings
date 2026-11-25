<?php

declare(strict_types=1);

namespace Qiick\Settings;

/**
 * Declares the settings owned by a Laravel application.
 *
 * Tag implementations with SettingsSchema::class in the application container.
 *
 * @api
 */
interface SettingsSchema
{
    /** @return list<Definition> */
    public function definitions(): array;
}
