<?php

declare(strict_types=1);

/**
 * FIFO vacation allocation: annual entitlement + opening carryover (Resturlaub),
 * with carryover usable only for working days on/before the configured expiry in each calendar year.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCP\IConfig;

class VacationAllocationService
{
	public function __construct(
		private IConfig $config,
		private AbsenceMapper $absenceMapper,
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private UserSettingsMapper $userSettingsMapper,
		private VacationYearBalanceMapper $vacationYearBalanceMapper,
		private HolidayService $holidayCalendarService,
	) {
	}

	/**
	 * Resolved expiry calendar date for year Y (last day on which carryover can be used for that year's balance).
	 */
	public function getCarryoverExpiryDateForYear(int $year): \DateTimeImmutable
	{
		$month = $this->getExpiryMonth();
		$day = $this->getExpiryDay();
		if (!checkdate($month, $day, $year)) {
			$day = (int)(new \DateTimeImmutable("$year-$month-01"))->format('t');
		}
		$d = \DateTimeImmutable::createFromFormat('Y-n-j', "$year-$month-$day");
		if ($d === false) {
			return new \DateTimeImmutable("$year-03-31");
		}
		return $d->setTime(0, 0, 0);
	}

	public function getExpiryMonth(): int
	{
		$v = (int)$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3');
		return max(1, min(12, $v));
	}

	public function getExpiryDay(): int
	{
		$v = (int)$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31');
		return max(1, min(31, $v));
	}

	/**
	 * Whether carryover from year Y's opening balance can still be used for new requests (date-only, server "today").
	 */
	public function isCarryoverUsableForNewRequests(int $year, ?\DateTimeInterface $asOf = null): bool
	{
		$asOf = $asOf ?? new \DateTime('today');
		$expiry = $this->getCarryoverExpiryDateForYear($year);
		$asDate = $asOf instanceof \DateTimeInterface
			? (new \DateTime($asOf->format('Y-m-d')))->setTime(0, 0, 0)
			: (clone $asOf)->setTime(0, 0, 0);
		$exp = new \DateTime($expiry->format('Y-m-d'));
		$exp->setTime(0, 0, 0);
		return $asDate <= $exp;
	}

	/**
	 * Annual vacation entitlement (same rules as AbsenceService::getVacationStats).
	 */
	public function getAnnualEntitlementDays(string $userId): int
	{
		$totalEntitlement = Constants::DEFAULT_VACATION_DAYS_PER_YEAR;
		try {
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			if ($currentModel !== null && $currentModel->getVacationDaysPerYear() !== null) {
				$totalEntitlement = $currentModel->getVacationDaysPerYear();
			} else {
				$totalEntitlement = $this->userSettingsMapper->getIntegerSetting(
					$userId,
					'vacation_days_per_year',
					Constants::DEFAULT_VACATION_DAYS_PER_YEAR
				);
			}
		} catch (\Throwable $e) {
			$totalEntitlement = Constants::DEFAULT_VACATION_DAYS_PER_YEAR;
		}
		return max(0, min(366, (int)$totalEntitlement));
	}

	/**
	 * Split working days in calendar year Y for an absence into (before/on expiry) vs (after expiry).
	 *
	 * @return array{before: float, after: float}
	 */
	public function splitWorkingDaysForYearBeforeAfterExpiry(
		string $userId,
		\DateTime $absStart,
		\DateTime $absEnd,
		int $year,
	): array {
		$expiry = $this->getCarryoverExpiryDateForYear($year);
		return $this->splitWorkingDaysForYearSegment($userId, $absStart, $absEnd, $year, $expiry);
	}

	/**
	 * @return array{before: float, after: float}
	 */
	public function splitWorkingDaysForYearSegment(
		string $userId,
		\DateTime $absStart,
		\DateTime $absEnd,
		int $year,
		\DateTimeImmutable $expiryDate,
	): array {
		$yStart = new \DateTime("$year-01-01");
		$yEnd = new \DateTime("$year-12-31");
		$segStart = clone $absStart;
		$segStart->setTime(0, 0, 0);
		$segEnd = clone $absEnd;
		$segEnd->setTime(0, 0, 0);
		if ($segStart < $yStart) {
			$segStart = clone $yStart;
		}
		if ($segEnd > $yEnd) {
			$segEnd = clone $yEnd;
		}
		if ($segStart > $segEnd) {
			return ['before' => 0.0, 'after' => 0.0];
		}

		$expiry = new \DateTime($expiryDate->format('Y-m-d'));
		$expiry->setTime(0, 0, 0);

		$beforeEnd = $segEnd <= $expiry ? clone $segEnd : clone $expiry;
		$before = 0.0;
		if ($segStart <= $beforeEnd) {
			$before = $this->holidayCalendarService->computeWorkingDaysForUser($userId, $segStart, $beforeEnd);
		}

		$afterStart = (clone $expiry)->modify('+1 day');
		if ($segStart > $afterStart) {
			$afterStart = clone $segStart;
		}
		$after = 0.0;
		if ($afterStart <= $segEnd) {
			$after = $this->holidayCalendarService->computeWorkingDaysForUser($userId, $afterStart, $segEnd);
		}

		return ['before' => $before, 'after' => $after];
	}

	/**
	 * FIFO allocation for calendar year. Optionally exclude an absence (edit) and/or inject a prospective absence (create/validate).
	 *
	 * @return array{
	 *   year: int,
	 *   entitlement: float,
	 *   carryover_opening: float,
	 *   carryover_remaining_after_approved: float,
	 *   annual_remaining_after_approved: float,
	 *   total_remaining_for_new_requests: float,
	 *   carryover_expires_on: string|null,
	 *   carryover_usable_for_new_requests: float,
	 *   used_total_working_days: float,
	 *   allocation_valid: bool,
	 *   shortfall: float
	 * }
	 */
	public function computeYearAllocation(
		string $userId,
		int $year,
		?int $excludeAbsenceId = null,
		?\DateTime $prospectiveStart = null,
		?\DateTime $prospectiveEnd = null,
		?\DateTimeInterface $asOf = null,
	): array {
		$asOf = $asOf ?? new \DateTime('today');
		$annualEntitlement = (float)$this->getAnnualEntitlementDays($userId);
		$carryoverOpening = $this->vacationYearBalanceMapper->getCarryoverDays($userId, $year);
		$carryoverOpening = max(0.0, min(366.0, $carryoverOpening));

		$expiry = $this->getCarryoverExpiryDateForYear($year);
		$carryoverExpiresOn = $carryoverOpening > 0.0001 ? $expiry->format('Y-m-d') : null;

		$list = $this->absenceMapper->findVacationApprovedOverlappingYear($userId, $year);
		$merged = [];
		foreach ($list as $a) {
			if ($excludeAbsenceId !== null && $a->getId() === $excludeAbsenceId) {
				continue;
			}
			$merged[] = $a;
		}
		if ($prospectiveStart !== null && $prospectiveEnd !== null) {
			$p = new Absence();
			$p->setId(2147483647);
			$p->setStartDate(clone $prospectiveStart);
			$p->setEndDate(clone $prospectiveEnd);
			$merged[] = $p;
		}
		usort($merged, function (Absence $a, Absence $b) {
			$as = $a->getStartDate();
			$bs = $b->getStartDate();
			if ($as == $bs) {
				return $a->getId() <=> $b->getId();
			}
			return $as <=> $bs;
		});

		$carryoverPool = $carryoverOpening;
		$annualPool = $annualEntitlement;
		$usedTotal = 0.0;
		$valid = true;
		$shortfall = 0.0;

		foreach ($merged as $absence) {
			$start = $absence->getStartDate();
			$end = $absence->getEndDate();
			if (!$start || !$end) {
				continue;
			}
			$split = $this->splitWorkingDaysForYearSegment($userId, $start, $end, $year, $expiry);
			$wdBefore = $split['before'];
			$wdAfter = $split['after'];
			$chunk = $wdBefore + $wdAfter;
			$usedTotal += $chunk;

			$need = $wdBefore;
			$fromC = min($carryoverPool, $need);
			$carryoverPool -= $fromC;
			$need -= $fromC;
			$fromA = min($annualPool, $need);
			$annualPool -= $fromA;
			$need -= $fromA;
			if ($need > 0.0001) {
				$valid = false;
				$shortfall += $need;
			}

			$need2 = $wdAfter;
			$fromA2 = min($annualPool, $need2);
			$annualPool -= $fromA2;
			$need2 -= $fromA2;
			if ($need2 > 0.0001) {
				$valid = false;
				$shortfall += $need2;
			}
		}

		$carryoverRem = max(0.0, $carryoverPool);
		$annualRem = max(0.0, $annualPool);

		$carryoverUsable = $carryoverRem;
		if (!$this->isCarryoverUsableForNewRequests($year, $asOf)) {
			$carryoverUsable = 0.0;
		}

		$totalForNew = $annualRem + $carryoverUsable;

		return [
			'year' => $year,
			'entitlement' => $annualEntitlement,
			'carryover_opening' => round($carryoverOpening, 4),
			'carryover_remaining_after_approved' => round($carryoverRem, 4),
			'annual_remaining_after_approved' => round($annualRem, 4),
			'total_remaining_for_new_requests' => round(max(0.0, $totalForNew), 4),
			'carryover_expires_on' => $carryoverExpiresOn,
			'carryover_usable_for_new_requests' => round($carryoverUsable, 4),
			'used_total_working_days' => round($usedTotal, 4),
			'allocation_valid' => $valid,
			'shortfall' => round($shortfall, 4),
		];
	}
}
