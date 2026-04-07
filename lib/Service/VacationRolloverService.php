<?php

declare(strict_types=1);

/**
 * Idempotent rollover of unused vacation carryover (and optionally unused annual days)
 * from calendar year Y into opening carryover for year Y+1, after Y’s carryover deadline.
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
	 * As-of date for allocation: first calendar day after the carryover deadline (carryover not usable for new requests; remainder reflects FIFO).
	 */
	public function getAllocationAsOfAfterDeadline(int $fromYear): \DateTime
	{
		$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($fromYear);
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
		$asOf = $this->getAllocationAsOfAfterDeadline($fromYear);
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

		$existingTarget = $this->vacationYearBalanceMapper->getCarryoverDays($userId, $toYear);
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

		$this->vacationYearBalanceMapper->upsert($userId, $toYear, $amount);
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
		$years = $onlyFromYear !== null ? [$onlyFromYear] : $this->getEligibleFromYears($today);
		if ($onlyFromYear !== null) {
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($onlyFromYear);
			$expDt = new \DateTime($exp->format('Y-m-d'));
			$expDt->setTime(0, 0, 0);
			$todayNorm = new \DateTime($today->format('Y-m-d'));
			$todayNorm->setTime(0, 0, 0);
			if ($expDt >= $todayNorm && !$force) {
				return $stats;
			}
		}

		$this->userManager->callForAllUsers(function (\OCP\IUser $user) use (&$stats, $years, $dryRun, $force, $ignoreEnabledCheck) {
			$uid = $user->getUID();
			if ($user->isEnabled() !== true) {
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
		$today = new \DateTime('today');
		$years = $onlyFromYear !== null ? [$onlyFromYear] : $this->getEligibleFromYears($today);
		if ($onlyFromYear !== null) {
			$exp = $this->vacationAllocationService->getCarryoverExpiryDateForYear($onlyFromYear);
			$expDt = new \DateTime($exp->format('Y-m-d'));
			$expDt->setTime(0, 0, 0);
			$todayNorm = new \DateTime($today->format('Y-m-d'));
			$todayNorm->setTime(0, 0, 0);
			if ($expDt >= $todayNorm && !$force) {
				return $stats;
			}
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
}
