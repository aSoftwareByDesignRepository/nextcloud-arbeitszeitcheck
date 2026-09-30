<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Middleware;

use Exception;

class KioskUnauthorizedException extends Exception
{
	public function __construct(string $message = 'Kiosk terminal authentication failed', int $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
