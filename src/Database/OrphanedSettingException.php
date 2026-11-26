<?php

declare(strict_types=1);

namespace Qiick\Settings\Database;

use RuntimeException;

/** @internal Identifies an unexportable orphan by its database row ID only. */
final class OrphanedSettingException extends RuntimeException
{
    public function __construct(int $rowId)
    {
        parent::__construct("Cannot export settings row {$rowId} because its key is no longer declared.");
    }
}
