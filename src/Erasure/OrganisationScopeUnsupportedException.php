<?php

declare(strict_types=1);

namespace Qiick\Settings\Erasure;

use LogicException;

/** @api Signals that user settings cannot be erased for a single organisation. */
final class OrganisationScopeUnsupportedException extends LogicException {}
