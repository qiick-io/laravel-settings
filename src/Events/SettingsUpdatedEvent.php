<?php

declare(strict_types=1);

namespace Qiick\Settings\Events;

use Qiick\Settings\ScopeRef;

/** @api Identifies the keys changed by a committed settings write. */
final readonly class SettingsUpdatedEvent
{
    /** @param list<string> $keys */
    public function __construct(
        public ScopeRef $scope,
        public string $group,
        public array $keys,
    ) {}
}
