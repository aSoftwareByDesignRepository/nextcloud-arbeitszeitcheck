<?php

declare(strict_types=1);

/**
 * Thrown when two managers decide the same pending time entry concurrently.
 * The loser of the atomic status-guarded write must surface HTTP 409, not
 * silently overwrite the winner's decision.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class ConcurrentDecisionException extends BusinessRuleException
{
	public function __construct(string $message)
	{
		parent::__construct($message, 'already_decided');
	}
}
