<?php

declare(strict_types=1);

/**
 * Marker exception for malformed day-shape input parsed by
 * {@see AbsenceDayShape}. getMessage() carries the AbsenceDayShape::ERR_*
 * machine code; callers map it to a localized BusinessRuleException.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class ShapeInputException extends \InvalidArgumentException
{
}
