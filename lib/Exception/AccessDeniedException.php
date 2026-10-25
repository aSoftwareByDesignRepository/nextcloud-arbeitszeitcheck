<?php

declare(strict_types=1);

/**
 * Thrown when an authenticated user attempts an action outside their
 * permission scope. HTTP transports map this exception to 403 Forbidden.
 *
 * The message may carry a fuller user-facing explanation (msgid or
 * pre-translated — the wire boundary translates catalog keys and passes
 * anything else through verbatim). Status classification keys off the
 * exception TYPE so a localized "Zugriff verweigert." never collapses to
 * a 500 the way a message-substring match on the English source would.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class AccessDeniedException extends \RuntimeException
{
	public function __construct(
		string $message = 'Access denied',
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}
}
