<?php

declare(strict_types=1);

/**
 * Thrown when a request reaches a controller without an authenticated
 * user session. HTTP transports map this exception to 401 Unauthorized.
 *
 * The message is a catalog msgid (or any caller-provided text); wire
 * boundaries apply the catalog-keyed pass-through, so both raw msgids and
 * pre-translated messages serialize correctly. HTTP status is derived from
 * the exception TYPE — never from matching message text, which would break
 * the moment the message is localized.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class NotAuthenticatedException extends \RuntimeException
{
	public function __construct(
		string $message = 'User not authenticated',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}
}
