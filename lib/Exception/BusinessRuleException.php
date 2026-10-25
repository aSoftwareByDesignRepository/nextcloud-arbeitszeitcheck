<?php

declare(strict_types=1);

/**
 * Thrown when a service rejects a user request because of a known
 * business rule (e.g. "User is already clocked in"). HTTP transports map
 * this exception to a 400 Bad Request response.
 *
 * Two message conventions are supported — both reach the wire correctly:
 * throwers may pass a raw catalog msgid (English source string, optionally
 * with already-substituted parameters) or a message already translated at
 * throw time. toHttpPayload() applies the catalog-keyed WireText pass at
 * the boundary, which translates msgids for the current user and returns
 * anything else verbatim — so neither convention leaks raw English, and
 * callers must NOT skip the boundary pass "because it is already done".
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class BusinessRuleException extends \RuntimeException
{
	/**
	 * @param array<string, mixed> $details Machine-readable fields for clients
	 *                                     that localize the message themselves
	 *                                     (mobile locale ≠ Nextcloud user language).
	 */
	public function __construct(
		string $message,
		private readonly ?string $reasonCode = null,
		private readonly array $details = [],
	) {
		parent::__construct($message);
	}

	public function getReasonCode(): ?string
	{
		return $this->reasonCode;
	}

	/**
	 * Scalar-only copy of constructor details (no nested objects).
	 *
	 * @return array<string, bool|float|int|string>
	 */
	public function getDetails(): array
	{
		$out = [];
		foreach ($this->details as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}
			if (is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	/**
	 * JSON envelope for HTTP 400 business-rule failures.
	 *
	 * @return array{success: false, error: string, message: string, error_code?: string, error_details?: array<string, bool|float|int|string>}
	 */
	public function toHttpPayload(): array
	{
		$message = \OCA\ArbeitszeitCheck\Support\WireText::t($this->getMessage());
		$payload = [
			'success' => false,
			'error' => $message,
			'message' => $message,
		];
		if ($this->reasonCode !== null && $this->reasonCode !== '') {
			$payload['error_code'] = $this->reasonCode;
		}
		$details = $this->getDetails();
		if ($details !== []) {
			$payload['error_details'] = $details;
		}
		return $payload;
	}
}
