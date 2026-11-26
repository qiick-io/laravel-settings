<?php

declare(strict_types=1);

namespace Qiick\Settings;

use RuntimeException;

/** @api The settings group changed since the caller read its revision. */
final class StaleSetting extends RuntimeException {}
