<?php

declare(strict_types=1);

use Qiick\Settings\Config\SettingsConfig;

return [
    'migrations' => [
        'load' => SettingsConfig::defaultMigrationLoading(),
    ],
    'columns' => [
        'scope_id' => SettingsConfig::DEFAULT_SCOPE_ID_COLUMN,
        'legacy_scope_id' => null,
    ],
    'encryption' => [
        'strict' => SettingsConfig::defaultStrictEncryption(),
    ],
];
