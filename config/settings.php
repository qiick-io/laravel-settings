<?php

declare(strict_types=1);

use Qiick\Settings\Config\SettingsConfig;

return [
    'migrations' => [
        'load' => SettingsConfig::defaultMigrationLoading(),
    ],
];
