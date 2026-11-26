<?php

declare(strict_types=1);

namespace Qiick\Settings;

use RuntimeException;

/** @api A stored setting could not be read as its declared type. */
final class SettingTypeMismatch extends RuntimeException {}
