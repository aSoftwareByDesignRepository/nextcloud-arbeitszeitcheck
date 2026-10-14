<?php

declare(strict_types=1);

/**
 * Thrown when the per-user absence mutation lock is held by a concurrent
 * request for the same user. Fail-fast lock contention must surface as a
 * retryable busy response — never a masked generic error
 * (lock_contention_opaque_error). Wire code matches the time-tracking
 * `BusinessRuleCode::LOCKED` convention.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

use OCA\ArbeitszeitCheck\BusinessRuleCode;

class AbsenceMutationBusyException extends BusinessRuleException
{
	public function __construct(string $message)
	{
		parent::__construct($message, BusinessRuleCode::LOCKED);
	}
}
