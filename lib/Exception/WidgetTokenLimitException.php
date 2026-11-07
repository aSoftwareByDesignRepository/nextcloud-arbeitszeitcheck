<?php

declare(strict_types=1);

/**
 * Thrown when a user already holds the maximum number of active widget
 * tokens — a bounded mint must refuse instead of growing the table without
 * limit (suite widget spec E.6: tokens are per-device, so a high cap is
 * still generous).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class WidgetTokenLimitException extends \RuntimeException
{
}
