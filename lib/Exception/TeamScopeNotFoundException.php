<?php

declare(strict_types=1);

/**
 * Thrown when a team-scoped report is requested for a team the caller may
 * not see or that does not exist. HTTP transports map this to a uniform
 * 404: a non-admin caller must not learn whether a foreign team id exists
 * (no existence oracle — spec C-R8/NN-1, TH-C1).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class TeamScopeNotFoundException extends \RuntimeException
{
	public function __construct(
		string $message = 'Team not found',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}
}
