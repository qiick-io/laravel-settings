<?php

declare(strict_types=1);

namespace Qiick\Settings;

/** @api Stores opaque settings snapshots in an application-provided cache. */
interface SettingsCache
{
    public function get(string $key): ?string;

    public function put(string $key, string $payload, int $seconds): void;

    public function forget(string $key): void;
}
