<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Exception;

class SpecialEntitlementException extends BusinessRuleException
{
	public function __construct(
		string $reasonCode,
		string $message = '',
		private readonly int $httpStatus = 409,
		private readonly array $fields = [],
	) {
		parent::__construct($message !== '' ? $message : $reasonCode, $reasonCode);
	}

	public function httpStatus(): int
	{
		return $this->httpStatus;
	}

	public function fields(): array
	{
		return $this->fields;
	}
}
