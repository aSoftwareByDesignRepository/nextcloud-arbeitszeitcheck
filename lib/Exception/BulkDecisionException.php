<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Exception;

/**
 * Bulk-decision failure carrying the HTTP status, a stable reason code and
 * the failing item index. Existence/scope failures serialize as a uniform
 * 404; already-decided and CAS failures serialize as 409.
 */
class BulkDecisionException extends BusinessRuleException
{
	public function __construct(
		string $reasonCode,
		string $message,
		private readonly int $httpStatus = 409,
		private readonly array $fields = [],
		?int $itemIndex = null,
		?int $itemId = null,
	) {
		parent::__construct($message, $reasonCode, array_filter([
			'item' => $itemIndex,
			'id' => $itemId,
		], static fn ($v) => $v !== null));
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
