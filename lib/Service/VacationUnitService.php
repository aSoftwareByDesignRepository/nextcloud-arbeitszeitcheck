<?php

declare(strict_types=1);

/**
 * Org vacation unit (days|hours) — BANSS Phase C / Q3=A / Q8.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;

final class VacationUnitService
{
	/** @var bool */
	private static $healingPending = false;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getUnit(): string
	{
		$this->healPendingMigrationIfPossible();
		return $this->readUnitFromConfig();
	}

	/**
	 * Config-only unit read (no heal). Used by migration heal to avoid recursion.
	 */
	public function peekUnit(): string
	{
		return $this->readUnitFromConfig();
	}

	private function readUnitFromConfig(): string
	{
		$raw = strtolower(trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT,
			Constants::DEFAULT_VACATION_UNIT
		)));
		return $raw === Constants::VACATION_UNIT_HOURS
			? Constants::VACATION_UNIT_HOURS
			: Constants::VACATION_UNIT_DAYS;
	}

	/**
	 * Close the migrate crash window on every unit read (dashboard, absences, admin).
	 */
	private function healPendingMigrationIfPossible(): void
	{
		if (self::$healingPending) {
			return;
		}
		self::$healingPending = true;
		try {
			$migration = \OCP\Server::get(VacationUnitMigrationService::class);
			$migration->completePendingMigrationIfNeeded();
		} catch (\Throwable) {
			// Container/tests without migration service — leave pending for migrate().
		} finally {
			self::$healingPending = false;
		}
	}

	public function isHoursMode(): bool
	{
		return $this->getUnit() === Constants::VACATION_UNIT_HOURS;
	}

	public function isDaysMode(): bool
	{
		return !$this->isHoursMode();
	}

	/**
	 * Org conversion factor for days↔hours migration / entitlement rescale.
	 *
	 * Not the booking debit: full-day absences use VacationHoursDebitService
	 * (weekday schedule → model daily_hours → this factor as last resort).
	 */
	public function getHoursPerDay(): float
	{
		$raw = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_HOURS_PER_DAY,
			(string)Constants::DEFAULT_VACATION_HOURS_PER_DAY
		));
		$v = (float)str_replace(',', '.', $raw === '' ? (string)Constants::DEFAULT_VACATION_HOURS_PER_DAY : $raw);
		if (!is_finite($v) || $v < 0.25 || $v > 24.0) {
			return Constants::DEFAULT_VACATION_HOURS_PER_DAY;
		}
		return round($v, 2, PHP_ROUND_HALF_UP);
	}

	public function isClientConfirmedForHours(): bool
	{
		return $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_CLIENT_CONFIRMED,
			'0'
		) === '1';
	}

	/**
	 * Canonical rounding for entitlement / balance / consumption amounts.
	 */
	public function roundAmount(float $amount): float
	{
		if (!is_finite($amount)) {
			return 0.0;
		}
		return round($amount, 2, PHP_ROUND_HALF_UP);
	}

	/**
	 * Clamp annual entitlement in the active unit.
	 * Days: 0–366 (legacy). Hours: 0–4000 (~500 days × 8 h safety ceiling).
	 */
	public function clampEntitlement(float $amount): float
	{
		$max = $this->isHoursMode() ? 4000.0 : 366.0;
		return $this->roundAmount(max(0.0, min($max, $amount)));
	}

	/**
	 * Clamp opening carryover in the active unit.
	 */
	public function clampCarryover(float $amount): float
	{
		$max = $this->isHoursMode() ? 4000.0 : 366.0;
		$v = $this->roundAmount(max(0.0, min($max, $amount)));
		$capRaw = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
			''
		));
		if ($capRaw === '') {
			return $v;
		}
		$cap = (float)str_replace(',', '.', $capRaw);
		if (!is_finite($cap) || $cap < 0) {
			return $v;
		}
		$capMax = $this->isHoursMode() ? 4000.0 : 366.0;
		$cap = max(0.0, min($capMax, $cap));
		return min($v, $this->roundAmount($cap));
	}

	/**
	 * Convert a days magnitude to hours using the org migration factor.
	 */
	public function daysToHours(float $days): float
	{
		return $this->roundAmount($days * $this->getHoursPerDay());
	}

	/**
	 * Convert hours back to days using the org migration factor.
	 */
	public function hoursToDays(float $hours): float
	{
		$factor = $this->getHoursPerDay();
		if ($factor < 0.0001) {
			return 0.0;
		}
		return $this->roundAmount($hours / $factor);
	}

	/**
	 * Bachus: admins always enter familiar calendar days (0–366).
	 * In hours mode the stored entitlement/carryover amount is hours.
	 */
	public function adminDaysToStoredAmount(float $days): float
	{
		$days = $this->roundAmount(max(0.0, min(366.0, $days)));
		if (!$this->isHoursMode()) {
			return $days;
		}
		return $this->clampEntitlement($this->daysToHours($days));
	}

	/**
	 * Inverse of {@see adminDaysToStoredAmount()} for form prefills / tables.
	 */
	public function storedAmountToAdminDays(float $stored): float
	{
		if (!$this->isHoursMode()) {
			return $this->roundAmount(max(0.0, min(366.0, $stored)));
		}
		return $this->hoursToDays(max(0.0, $stored));
	}

	/**
	 * Ceiling for values already in the active storage unit.
	 */
	public function storedAmountCeiling(): float
	{
		return $this->isHoursMode() ? 4000.0 : 366.0;
	}

	/**
	 * @return array{unit: string, hours_per_day: float, client_confirmed: bool, migrated_at: string|null}
	 */
	public function getStatus(): array
	{
		$migrated = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATED_AT,
			''
		));
		return [
			'unit' => $this->getUnit(),
			'hours_per_day' => $this->getHoursPerDay(),
			'client_confirmed' => $this->isClientConfirmedForHours(),
			'migrated_at' => $migrated !== '' ? $migrated : null,
		];
	}
}
