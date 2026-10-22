<?php

declare(strict_types=1);

/**
 * Internal engine signal: a vacation policy layer selected
 * `vacation_mode = 'tariff_rule_based'` but its referenced tariff rule set is
 * unavailable — either `tariff_rule_set_id` is NULL (the table has no foreign
 * key, so nothing prevents a missing reference) or the rule set row was
 * deleted after the policy was written (dangling reference).
 *
 * This is never a client-visible error: {@see VacationEntitlementEngine}
 * catches it per layer, records an `undeliverable` marker in the evaluation
 * trace, logs it, and degrades to the next configured layer so a single stale
 * policy row can never take down employee-facing surfaces (admin user detail,
 * absence preview, allocation writes).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Exception;

final class TariffRuleSetUnavailableException extends \RuntimeException
{
	public function __construct(
		private readonly ?int $ruleSetId,
		string $message,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getRuleSetId(): ?int
	{
		return $this->ruleSetId;
	}
}
