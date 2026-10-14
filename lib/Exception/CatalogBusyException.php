<?php

declare(strict_types=1);

/**
 * Thrown when the custom-absence-type catalog write lock is held by a
 * concurrent admin mutation. Fail-fast lock contention must surface as a
 * retryable HTTP 409 — never a masked 400/500 (lock_contention_opaque_error).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class CatalogBusyException extends BusinessRuleException
{
	public function __construct(string $message)
	{
		parent::__construct($message, 'ABSENCE_TYPE_CATALOG_BUSY');
	}
}
