<?php

declare(strict_types=1);

namespace Qiick\Settings\Events;

use Qiick\Settings\Erasure\ErasureReport;

/** @api Reports a committed settings erasure without identifying the person. */
final readonly class SubjectErasedEvent
{
    public function __construct(public ErasureReport $report) {}
}
