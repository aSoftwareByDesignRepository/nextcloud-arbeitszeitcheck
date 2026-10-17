<?php

declare(strict_types=1);

/**
 * CAS conflict on a work-location day override: missing, malformed, or
 * stale revision. Controllers map this to HTTP 409 `REVISION_CONFLICT`
 * telling the client to reload and retry — never merged, never silent
 * last-writer-wins.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class WorkLocationDayConflictException extends BusinessRuleException
{
	public function __construct(string $message)
	{
		parent::__construct($message, 'REVISION_CONFLICT');
	}
}
