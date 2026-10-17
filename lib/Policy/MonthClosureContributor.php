<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

interface MonthClosureContributor
{
	public function id(): string;
	/**
	 * Feature flag gating this contributor — a key from
	 * {@see \OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::FEATURE_DEFAULTS}.
	 * Consumers must not call blockers/snapshot/contributions/canonicalHash
	 * while the flag is off: a disabled domain performs no domain work and
	 * must leave the closure payload bit-identical.
	 */
	public function featureFlag(): string;
	public function blockers(string $userId, string $month): array;
	public function snapshot(string $userId, string $month): array;
	public function contributions(string $userId, string $month): array;
	public function canonicalHash(string $userId, string $month): string;
}
