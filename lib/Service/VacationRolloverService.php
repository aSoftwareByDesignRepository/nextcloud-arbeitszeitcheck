<?php

declare(strict_types=1);

/**
 * Idempotent rollover of unused vacation carryover (and optionally unused annual days)
 * from year Y into opening carryover for year Y+1, after Y’s carryover deadline.
 *
 * Calendar mode: deadline is the configured month/day in calendar year Y.
 * Anniversary mode (Q2): deadline is N months after each user’s anniversary window start.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCP\IConfig;
use OCP\IUserManager;

class VacationRolloverService
{
	public function __construct(
		private IConfig $config,
		private VacationAllocationService $vacationAllocationService,
		private VacationYearBalanceMapper $vacationYearBalanceMapper,
		private VacationRolloverLogMapper $vacationRolloverLogMapper,
		private IUserManager $userManager,
		private AuditLogMapper $auditLogMapper,
		private PermissionService $permissionService,
		private ?VacationUnitService $vacationUnitService = null,
		private ?VacationUnitMigrationService $vacationUnitMigrationService = null,
	) {
	}

	public function isAutomaticRolloverEnabled(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_ROLLOVER_ENABLED, '1') === '1';
	}

	public function isIncludeUnusedAnnualEnabled(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL, '0') === '1';
	}

	/**
	 * Calendar years whose carryover deadline is strictly before $today (server date) may be rolled.
	 * In anniversary mode prefer {@see getEligibleFromYearsForUser()} — this returns the same
	 * calendar-key scan for eligibility when hire dates are unavailable at org level.
	 *
	 * @return int[]
	 */
	public function getEligibleFromYears(\DateTimeInterface $today): array
	{
		$todayNorm = new \DateTime($today->format('Y-m-d'));
		$todayNorm->setTime(0, 0, 0);
		$y = (int)$todayNorm->format('Y');
		$out = [];
		for ($fy = $y; $fy >= $y - 20; $fy--) {
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($fy);
			$expDt = new \DateTime($exp->format('Y-m-d'));
			$expDt->setTime(0, 0, 0);
			if ($expDt < $todayNorm) {
				$out[] = $fy;
			}
		}
		return $out;
	}

	/**
	 * Balance years whose carryover deadline for this user is strictly before $today.
	 *
	 * @return int[]
	 */
	public function getEligibleFromYearsForUser(string $userId, \DateTimeInterface $today): array
	{
		if (!$this->vacationAllocationService->isAnniversaryMode()) {
			return $this->getEligibleFromYears($today);
		}

		$todayNorm = new \DateTime($today->format('Y-m-d'));
		$todayNorm->setTime(0, 0, 0);
		$y = (int)$todayNorm->format('Y');
		$out = [];
		for ($fy = $y; $fy >= $y - 20; $fy--) {
			$window = $this->vacationAllocationService->resolveWindowForUserYear($userId, $fy, $todayNorm);
			if ($window->missingEmploymentStart) {
				continue;
			}
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForWindow($window);
			$expDt = new \DateTime($exp->format('Y-m-d'));
			$expDt->setTime(0, 0, 0);
			if ($expDt < $todayNorm) {
				$out[] = $fy;
			}
		}
		return $out;
	}

	/**
	 * As-of date for allocation: first calendar day after the carryover deadline.
	 */
	public function getAllocationAsOfAfterDeadline(int $fromYear, ?string $userId = null): \DateTime
	{
		if ($userId !== null && $this->vacationAllocationService->isAnniversaryMode()) {
			$window = $this->vacationAllocationService->resolveWindowForUserYear($userId, $fromYear);
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForWindow($window);
		} else {
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($fromYear);
		}
		$d = new \DateTime($exp->format('Y-m-d'));
		$d->setTime(0, 0, 0);
		$d->modify('+1 day');
		return $d;
	}

	/**
	 * @return array{carryover_part: float, annual_part: float, total: float}
	 */
	public function computeRolloverAmountParts(string $userId, int $fromYear): array
	{
		$asOf = $this->getAllocationAsOfAfterDeadline($fromYear, $userId);
		$alloc = $this->vacationAllocationService->computeYearAllocation($userId, $fromYear, null, null, null, $asOf, null);
		$cPart = max(0.0, (float)($alloc['carryover_remaining_after_approved'] ?? 0));
		$aPart = 0.0;
		if ($this->isIncludeUnusedAnnualEnabled()) {
			$aPart = max(0.0, (float)($alloc['annual_remaining_after_approved'] ?? 0));
		}
		$total = $this->vacationAllocationService->applyCapToOpeningBalance($cPart + $aPart);
		return ['carryover_part' => $cPart, 'annual_part' => $aPart, 'total' => $total];
	}

	/**
	 * @return array{action: string, from_year?: int, to_year?: int, amount?: float, user_id?: string}
	 */
	public function processUserForFromYear(string $userId, int $fromYear, bool $dryRun, bool $force, bool $ignoreEnabledCheck): array
	{
		if (!$ignoreEnabledCheck && !$this->isAutomaticRolloverEnabled() && !$force) {
			return ['action' => 'skipped_disabled'];
		}

		$toYear = $fromYear + 1;
		if ($force) {
			$this->vacationRolloverLogMapper->deleteByUserAndYears($userId, $fromYear, $toYear);
		} elseif ($this->vacationRolloverLogMapper->existsForUserAndYears($userId, $fromYear, $toYear)) {
			return ['action' => 'skipped_already_logged', 'user_id' => $userId, 'from_year' => $fromYear, 'to_year' => $toYear];
		}

		$existingTarget = $this->vacationUnitService?->isHoursMode() === true
			? $this->vacationYearBalanceMapper->getCarryoverAmount($userId, $toYear, true)
			: $this->vacationYearBalanceMapper->getCarryoverDays($userId, $toYear);
		if ($existingTarget > 0.0001 && !$force) {
			return ['action' => 'skipped_target_balance', 'user_id' => $userId, 'from_year' => $fromYear, 'to_year' => $toYear];
		}

		$parts = $this->computeRolloverAmountParts($userId, $fromYear);
		$amount = $parts['total'];
		if ($amount < 0.0001) {
			return ['action' => 'skipped_zero', 'user_id' => $userId, 'from_year' => $fromYear];
		}

		if ($dryRun) {
			return [
				'action' => 'would_apply',
				'user_id' => $userId,
				'from_year' => $fromYear,
				'to_year' => $toYear,
				'amount' => $amount,
				'carryover_part' => $parts['carryover_part'],
				'annual_part' => $parts['annual_part'],
			];
		}

		$write = function () use ($userId, $fromYear, $toYear, $amount, $parts): array {
			$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
			$this->vacationYearBalanceMapper->upsert(
				$userId,
				$toYear,
				$amount,
				$hoursMode ? $amount : null,
				!$hoursMode
			);
			$this->vacationRolloverLogMapper->insertLog($userId, $fromYear, $toYear, $amount);
			try {
				$this->auditLogMapper->logAction(
					$userId,
					'vacation_rollover',
					'vacation_year_balance',
					null,
					null,
					[
						'from_year' => $fromYear,
						'to_year' => $toYear,
						'amount' => $amount,
						'carryover_part' => $parts['carryover_part'],
						'annual_part' => $parts['annual_part'],
						'vacation_year_mode' => $this->vacationAllocationService->isAnniversaryMode()
							? Constants::VACATION_YEAR_MODE_ANNIVERSARY
							: Constants::VACATION_YEAR_MODE_CALENDAR,
					],
					'system'
				);
			} catch (\Throwable $e) {
				// best-effort
			}

			return [
				'action' => 'applied',
				'user_id' => $userId,
				'from_year' => $fromYear,
				'to_year' => $toYear,
				'amount' => $amount,
			];
		};

		if ($this->vacationUnitMigrationService !== null) {
			return $this->vacationUnitMigrationService->withIdleShared($write);
		}

		return $write();
	}

	/**
	 * @return array{applied: int, skipped: int, errors: int}
	 */
	public function runForAllUsers(?int $onlyFromYear, bool $dryRun, bool $force, bool $ignoreEnabledCheck): array
	{
		$stats = ['applied' => 0, 'skipped' => 0, 'errors' => 0];
		if (!$ignoreEnabledCheck && !$this->isAutomaticRolloverEnabled() && !$force) {
			return $stats;
		}
		$today = new \DateTime('today');

		$this->userManager->callForAllUsers(function (\OCP\IUser $user) use (&$stats, $onlyFromYear, $dryRun, $force, $ignoreEnabledCheck, $today) {
			$uid = $user->getUID();
			if ($user->isEnabled() !== true) {
				return;
			}
			if (!$this->permissionService->isUserAllowedByAccessGroups($uid)) {
				return;
			}
			$years = $onlyFromYear !== null
				? [$onlyFromYear]
				: $this->getEligibleFromYearsForUser($uid, $today);
			if ($onlyFromYear !== null && !$force && !$this->isFromYearPastDeadlineForUser($uid, $onlyFromYear, $today)) {
				return;
			}
			foreach ($years as $fromYear) {
				try {
					$r = $this->processUserForFromYear($uid, $fromYear, $dryRun, $force, $ignoreEnabledCheck);
					$act = $r['action'] ?? '';
					if ($act === 'applied' || $act === 'would_apply') {
						$stats['applied']++;
					} elseif ($act !== 'skipped_disabled') {
						$stats['skipped']++;
					}
				} catch (\Throwable $e) {
					$stats['errors']++;
				}
			}
		});

		return $stats;
	}

	/**
	 * @return array{applied: int, skipped: int, errors: int}
	 */
	public function runForSingleUser(string $userId, ?int $onlyFromYear, bool $dryRun, bool $force, bool $ignoreEnabledCheck): array
	{
		$stats = ['applied' => 0, 'skipped' => 0, 'errors' => 0];
		if (!$ignoreEnabledCheck && !$this->isAutomaticRolloverEnabled() && !$force) {
			return $stats;
		}
		$user = $this->userManager->get($userId);
		if ($user === null || $user->isEnabled() !== true) {
			return $stats;
		}
		if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
			return $stats;
		}
		$today = new \DateTime('today');
		$years = $onlyFromYear !== null
			? [$onlyFromYear]
			: $this->getEligibleFromYearsForUser($userId, $today);
		if ($onlyFromYear !== null && !$force && !$this->isFromYearPastDeadlineForUser($userId, $onlyFromYear, $today)) {
			return $stats;
		}
		foreach ($years as $fromYear) {
			try {
				$r = $this->processUserForFromYear($userId, $fromYear, $dryRun, $force, $ignoreEnabledCheck);
				$act = $r['action'] ?? '';
				if ($act === 'applied' || $act === 'would_apply') {
					$stats['applied']++;
				} elseif ($act !== 'skipped_disabled') {
					$stats['skipped']++;
				}
			} catch (\Throwable $e) {
				$stats['errors']++;
			}
		}
		return $stats;
	}

	private function isFromYearPastDeadlineForUser(string $userId, int $fromYear, \DateTimeInterface $today): bool
	{
		$todayNorm = new \DateTime($today->format('Y-m-d'));
		$todayNorm->setTime(0, 0, 0);
		if ($this->vacationAllocationService->isAnniversaryMode()) {
			$window = $this->vacationAllocationService->resolveWindowForUserYear($userId, $fromYear, $todayNorm);
			if ($window->missingEmploymentStart) {
				return false;
			}
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForWindow($window);
		} else {
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($fromYear);
		}
		$expDt = new \DateTime($exp->format('Y-m-d'));
		$expDt->setTime(0, 0, 0);
		return $expDt < $todayNorm;
	}
}
