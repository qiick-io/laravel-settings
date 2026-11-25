<?php

declare(strict_types=1);

namespace Qiick\Settings;

use RuntimeException;

/** @api A stored setting secret could not be decrypted with the application's keys. */
final class UndecryptableSetting extends RuntimeException {}
