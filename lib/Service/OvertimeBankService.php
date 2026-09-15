<?php

declare(strict_types=1);

/**
 * Overtime bank: configurable cap (default 100 h) and payout-eligible hours above the cap.
 *
 * Effective balance = raw YTD cumulative balance minus confirmed payouts in the same year.
 * Banked hours = min(max(0, effective), cap). Payout eligible = max(0, effective - cap).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCP\IConfig;

class OvertimeBankService
{
	public const DEFAULT_BANK_MAX_HOURS = 100.0;
	public const MIN_BANK_MAX_HOURS = 1.0;
	public const MAX_BANK_MAX_HOURS = 500.0;
	public const DEFAULT_BANK_YELLOW_PERCENT = 80;
	public const DEFAULT_BANK_RED_PERCENT = 95;

	public function __construct(
		private readonly IConfig $config,
		private readonly OvertimeService $overtimeService,
		private readonly OvertimePayoutMapper $payoutMapper,
		private readonly ?OvertimeAdjustmentMapper $adjustmentMapper = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0') === '1';
	}

	public function getBankMaxHours(): float
	{
		$raw = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)self::DEFAULT_BANK_MAX_HOURS);
		$value = (float)str_replace(',', '.', trim($raw));
		if (!is_finite($value)) {
			return self::DEFAULT_BANK_MAX_HOURS;
		}

		return max(self::MIN_BANK_MAX_HOURS, min(self::MAX_BANK_MAX_HOURS, round($value, 2)));
	}

	/**
	 * @return array{yellow_percent: int, red_percent: int}
	 */
	public function getBankFillThresholds(): array
	{
		$yellow = $this->readPercent(Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, self::DEFAULT_BANK_YELLOW_PERCENT);
		$red = $this->readPercent(Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, self::DEFAULT_BANK_RED_PERCENT);
		if ($yellow > $red) {
			$yellow = $red;
		}

		return ['yellow_percent' => $yellow, 'red_percent' => $red];
	}

	/**
	 * Bank status for dashboard and APIs.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   bank_max_hours: float,
	 *   raw_balance: float,
	 *   total_payouts_ytd: float,
	 *   total_adjustments_ytd: float,
	 *   effective_balance: float,
	 *   banked_hours: float,
	 *   bank_room_hours: float,
	 *   payout_eligible_hours: float,
	 *   bank_fill_percent: float,
	 *   bank_state: string,
	 *   as_of_date: string
	 * }
	 */
	public function getBankStatus(string $userId, ?\DateTimeInterface $asOf = null): array
	{
		$bankMax = $this->getBankMaxHours();
		$enabled = $this->isEnabled();

		$asOfDt = $asOf !== null
			? \DateTime::createFromInterface($asOf)
			: new \DateTime();
		$asOfDt->setTime(23, 59, 59);

		$year = (int)$asOfDt->format('Y');
		$yearStart = new \DateTime(sprintf('%04d-01-01 00:00:00', $year));
		$rawData = $this->overtimeService->calculateOvertime($userId, $yearStart, $asOfDt);
		$rawBalance = (float)($rawData['cumulative_balance'] ?? 0.0);

		$totalPayoutsYtd = $this->payoutMapper->sumHoursPaidForYearThroughMonth(
			$userId,
			$year,
			(int)$asOfDt->format('n')
		);
		// Legacy-safe: when the bank feature is off, payouts are not applied to the
		// displayed Saldo (same as pre-adjustment behaviour). Adjustments always apply
		// so admin Nullung works with or without the bank.
		$payoutsApplied = $enabled ? $totalPayoutsYtd : 0.0;
		$totalAdjustmentsYtd = $this->sumAdjustmentsThrough($userId, $year, $asOfDt);
		$effectiveBalance = round($rawBalance - $payoutsApplied + $totalAdjustmentsYtd, 2);

		$bankedHours = 0.0;
		$bankRoom = $bankMax;
		$payoutEligible = 0.0;
		$fillPercent = 0.0;
		$bankState = 'disabled';

		if ($enabled) {
			if ($effectiveBalance > 0) {
				$bankedHours = min($effectiveBalance, $bankMax);
				$bankRoom = max(0.0, $bankMax - $bankedHours);
				$payoutEligible = max(0.0, $effectiveBalance - $bankMax);
				$fillPercent = $bankMax > 0 ? min(100.0, round(($bankedHours / $bankMax) * 100, 1)) : 0.0;
			}
			$bankState = $this->classifyBankFill($fillPercent, $payoutEligible, $effectiveBalance);
		}

		$lastPayout = null;
		if ($enabled) {
			$latest = $this->payoutMapper->findLatestForUserInYear($userId, $year);
			if ($latest !== null) {
				$lastPayout = $this->payoutEntityToAuditArray($latest);
			}
		}

		return [
			'enabled' => $enabled,
			'bank_max_hours' => $bankMax,
			'raw_balance' => round($rawBalance, 2),
			'total_payouts_ytd' => $totalPayoutsYtd,
			'total_adjustments_ytd' => $totalAdjustmentsYtd,
			'effective_balance' => $effectiveBalance,
			'banked_hours' => round($bankedHours, 2),
			'bank_room_hours' => round($bankRoom, 2),
			'payout_eligible_hours' => round($payoutEligible, 2),
			'bank_fill_percent' => $fillPercent,
			'bank_state' => $bankState,
			'as_of_date' => $asOfDt->format('Y-m-d'),
			'last_payout' => $lastPayout,
		];
	}

	/**
	 * Snapshot at end of a calendar month (for payout processing).
	 *
	 * @return array{
	 *   raw_balance: float,
	 *   effective_balance: float,
	 *   payout_eligible_hours: float,
	 *   bank_max_hours: float,
	 *   total_payouts_before_month: float
	 * }
	 */
	public function getMonthEndSnapshot(string $userId, int $year, int $month): array
	{
		$this->assertValidMonth($month);
		$lastDay = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
		$lastDay->modify('last day of this month');
		$lastDay->setTime(23, 59, 59);

		$bankMax = $this->getBankMaxHours();
		$yearStart = new \DateTime(sprintf('%04d-01-01 00:00:00', $year));
		$rawData = $this->overtimeService->calculateOvertime($userId, $yearStart, $lastDay);
		$rawBalance = (float)($rawData['cumulative_balance'] ?? 0.0);

		$payoutsBefore = $this->payoutMapper->sumHoursPaidForYear($userId, $year, $month);
		$adjustments = $this->sumAdjustmentsThrough($userId, $year, $lastDay);
		$effectiveBalance = round($rawBalance - $payoutsBefore + $adjustments, 2);
		$payoutEligible = max(0.0, round($effectiveBalance - $bankMax, 2));

		return [
			'raw_balance' => round($rawBalance, 2),
			'effective_balance' => $effectiveBalance,
			'payout_eligible_hours' => $payoutEligible,
			'bank_max_hours' => $bankMax,
			'total_payouts_before_month' => $payoutsBefore,
			'total_adjustments_ytd' => $adjustments,
		];
	}

	private function sumAdjustmentsThrough(string $userId, int $year, \DateTimeInterface $through): float
	{
		if ($this->adjustmentMapper === null) {
			return 0.0;
		}
		try {
			return $this->adjustmentMapper->sumHoursDeltaForYearThroughDate($userId, $year, $through);
		} catch (\Throwable $e) {
			// Table may not exist yet during upgrade — keep Saldo readable.
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Overtime adjustments sum failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return 0.0;
		}
	}

	/**
	 * Traffic-light style state for the overtime bank fill level.
	 */
	public function classifyBankFill(float $fillPercent, float $payoutEligible, float $effectiveBalance): string
	{
		if ($effectiveBalance < 0) {
			return 'undertime';
		}
		if ($payoutEligible >= 0.01) {
			return 'payout_eligible';
		}
		$thresholds = $this->getBankFillThresholds();
		if ($fillPercent >= $thresholds['red_percent']) {
			return 'bank_red';
		}
		if ($fillPercent >= $thresholds['yellow_percent']) {
			return 'bank_yellow';
		}

		return 'bank_green';
	}

	public function sanitizeBankMaxHours(mixed $value): float
	{
		$number = (float)str_replace(',', '.', trim((string)$value));
		if (!is_finite($number)) {
			return self::DEFAULT_BANK_MAX_HOURS;
		}

		return max(self::MIN_BANK_MAX_HOURS, min(self::MAX_BANK_MAX_HOURS, round($number, 2)));
	}

	public function sanitizePercent(mixed $value, int $default): int
	{
		$number = (int)round((float)str_replace(',', '.', trim((string)$value)));
		if ($number < 0 || $number > 100) {
			return $default;
		}

		return $number;
	}

	private function readPercent(string $key, int $default): int
	{
		return $this->sanitizePercent(
			$this->config->getAppValue('arbeitszeitcheck', $key, (string)$default),
			$default
		);
	}

	/**
	 * Frozen overtime-bank facts for tamper-evident month-closure snapshots.
	 *
	 * @return array<string, mixed>|null null when the bank feature is disabled
	 */
	public function buildClosureAuditBlock(string $userId, int $year, int $month, ?OvertimePayout $payoutAtSeal = null): ?array
	{
		if (!$this->isEnabled()) {
			return null;
		}

		$this->assertValidMonth($month);
		$snap = $this->getMonthEndSnapshot($userId, $year, $month);
		$bankMax = (float)$snap['bank_max_hours'];
		$effective = (float)$snap['effective_balance'];
		$banked = $effective > 0 ? min($effective, $bankMax) : 0.0;

		$block = [
			'enabled' => true,
			'bank_max_hours' => $bankMax,
			'raw_balance_eom' => (float)$snap['raw_balance'],
			'effective_balance_eom' => $effective,
			'payout_eligible_eom' => (float)$snap['payout_eligible_hours'],
			'banked_hours_eom' => round($banked, 2),
			'payout_record' => $payoutAtSeal !== null ? $this->payoutEntityToAuditArray($payoutAtSeal) : null,
		];

		return $block;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payoutEntityToAuditArray(OvertimePayout $entity): array
	{
		return [
			'id' => $entity->getId(),
			'calendar_year' => $entity->getCalendarYear(),
			'calendar_month' => $entity->getCalendarMonth(),
			'hours_paid' => round((float)$entity->getHoursPaid(), 2),
			'effective_balance_before' => round((float)$entity->getEffectiveBalanceBefore(), 2),
			'effective_balance_after' => round((float)$entity->getEffectiveBalanceAfter(), 2),
			'raw_balance_before' => round((float)$entity->getRawBalanceBefore(), 2),
			'bank_max_hours' => round((float)$entity->getBankMaxHours(), 2),
			'processed_by' => $entity->getProcessedBy(),
			'created_at' => $entity->getCreatedAt()?->format(\DateTimeInterface::ATOM),
		];
	}

	private function assertValidMonth(int $month): void
	{
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Month must be between 1 and 12.');
		}
	}
}
