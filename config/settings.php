<?php

declare(strict_types=1);

use Qiick\Settings\Config\SettingsConfig;

return [
    'cache' => [
        'store' => null,
        'ttl' => SettingsConfig::defaultCacheTtl(),
        'prefix' => SettingsConfig::DEFAULT_CACHE_PREFIX,
    ],
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
