<?php

declare(strict_types=1);

/**
 * Conflict/state violation on the work-location catalog or policies.
 * The reason code is the spec §9.8 machine code (WORK_LOCATION_*,
 * NOT_FOUND) — controllers map codes to HTTP status; no message content
 * is needed to distinguish not-found from not-yours (uniform 404).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class WorkLocationConflictException extends BusinessRuleException
{
	public function __construct(string $reasonCode)
	{
		parent::__construct($reasonCode, $reasonCode);
	}
}
