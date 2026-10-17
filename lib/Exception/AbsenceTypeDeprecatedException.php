<?php

declare(strict_types=1);

/**
 * New request against a deprecated absence type whose create path is closed
 * (e.g. `home_office` once `legacy_home_absence_create_enabled` is off).
 * Maps to HTTP 409 `ABSENCE_TYPE_DEPRECATED`; `replacement` points the
 * client at the successor mechanism so it can steer the user instead of
 * just failing.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class AbsenceTypeDeprecatedException extends BusinessRuleException
{
	public function __construct(string $message, string $replacement)
	{
		parent::__construct($message, 'ABSENCE_TYPE_DEPRECATED', ['replacement' => $replacement]);
	}
}
