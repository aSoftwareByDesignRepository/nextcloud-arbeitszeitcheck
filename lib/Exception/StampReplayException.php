<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Exception;

class StampReplayException extends \Exception
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
