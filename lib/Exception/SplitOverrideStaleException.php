<?php

declare(strict_types=1);

/**
 * Thrown when an approved split-day override no longer applies after the
 * corrected times were applied (spec split-day-working-model §9.7).
 *
 * The proposal was valid when submitted, but the approval-time recompute
 * reports the override inapplicable (e.g. a force_split whose corrected
 * clock-out no longer reaches the scheduled interruption). That is a stale
 * decision basis — a conflict, not a bad request: HTTP 409
 * `SPLIT_OVERRIDE_STALE`, telling the client to reload and re-decide.
 *
 * Extends {@see ConcurrentDecisionException} so every existing approval seam
 * that maps concurrent-decision failures to HTTP 409 maps this failure the
 * same way; the wire code is overridden to the normative
 * `SPLIT_OVERRIDE_STALE`.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

class SplitOverrideStaleException extends ConcurrentDecisionException
{
	public const CODE = 'SPLIT_OVERRIDE_STALE';

	public function __construct(string $message)
	{
		parent::__construct($message);
	}

	public function getReasonCode(): ?string
	{
		return self::CODE;
	}

	/**
	 * Same envelope as the parent, but with the normative wire code —
	 * {@see BusinessRuleException::toHttpPayload()} reads the pinned
	 * 'already_decided' reason code, so the code is corrected here.
	 *
	 * @return array{success: false, error: string, message: string, error_code: string}
	 */
	public function toHttpPayload(): array
	{
		$payload = parent::toHttpPayload();
		$payload['error_code'] = self::CODE;
		return $payload;
	}
}
