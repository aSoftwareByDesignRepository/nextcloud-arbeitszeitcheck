<?php

declare(strict_types=1);

/**
 * Orchestrates premium surcharge classification for a user/period.
 *
 * Orthogonal to OvertimeService Saldo / Auszahlung.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\PremiumPolicy;
use OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\IConfig;

class PremiumSurchargeService
{
	public function __construct(
		private readonly IConfig $config,
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly HolidayService $holidayService,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly PremiumSurchargeClassifier $classifier = new PremiumSurchargeClassifier(),
		private readonly ?\OCP\Lock\ILockingProvider $lockingProvider = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED,
			'0'
		) === '1';
	}

	public function getPolicy(): ?PremiumPolicy
	{
		$raw = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_PREMIUM_POLICY_JSON, '');
		if ($raw === '') {
			return null;
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($decoded)) {
			return null;
		}

		return PremiumPolicy::tryFromArray($decoded);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getPolicyArrayOrDefault(): array
	{
		$policy = $this->getPolicy();
		return $policy !== null ? $policy->toArray() : PremiumPolicy::atStarterPreset();
	}

	/**
	 * Summarise premiums for a user in [start, end] (inclusive calendar days).
	 *
	 * @return array<string, mixed>
	 */
	public function summariseForUser(
		string $userId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		?PremiumPolicy $policyOverride = null,
	): array {
		if (!$this->isEnabled()) {
			return [
				'enabled' => false,
				'buckets' => [],
				'total_classified_hours' => 0.0,
				'total_valued_hours' => 0.0,
				'stacking' => null,
				'note' => 'premium_disabled',
			];
		}

		$policy = $policyOverride ?? $this->getPolicy();
		if ($policy === null || $policy->getEnabledCategories() === []) {
			return [
				'enabled' => true,
				'buckets' => [],
				'total_classified_hours' => 0.0,
				'total_valued_hours' => 0.0,
				'stacking' => $policy?->getStacking(),
				'note' => 'premium_policy_empty',
			];
		}

		$rangeStart = \DateTime::createFromInterface(
			\DateTimeImmutable::createFromInterface($start)->setTime(0, 0, 0)
		);
		$rangeEndExclusive = \DateTime::createFromInterface(
			\DateTimeImmutable::createFromInterface($end)->setTime(0, 0, 0)->modify('+1 day')
		);

		$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $rangeStart, $rangeEndExclusive);
		$intervals = [];
		foreach ($entries as $entry) {
			if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
				continue;
			}
			foreach ($this->workIntervalsFromEntry($entry) as $iv) {
				$intervals[] = $iv;
			}
		}

		[$schedule, $dailyFallback] = $this->resolveDailyTarget($userId);

		$result = $this->classifier->classify(
			$intervals,
			$policy,
			fn (\DateTimeImmutable $day): bool => $this->holidayService->getHolidayWeightForUser($userId, \DateTime::createFromImmutable($day)) >= 1.0,
			function (\DateTimeImmutable $day) use ($schedule, $dailyFallback): float {
				if ($schedule !== null) {
					$n = (int)$day->format('N');
					$map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
					return $schedule->netHoursForWeekday($map[$n] ?? 'mon');
				}
				// Legacy: weekends contribute 0 daily OT threshold (Sat/Sun not work days).
				$n = (int)$day->format('N');
				return ($n >= 6) ? 0.0 : $dailyFallback;
			},
		);

		$result['enabled'] = true;
		$result['period_start'] = $rangeStart->format('Y-m-d');
		$result['period_end'] = \DateTimeImmutable::createFromInterface($end)->format('Y-m-d');
		$result['currency_mode'] = 'hours_only';
		$result['orthogonal_to_saldo'] = true;

		return $result;
	}

	/**
	 * Frozen premium block for month-closure canonical payload (NN-06).
	 * Loads policy + version once under an exclusive policy lock so a concurrent
	 * admin save cannot stamp a mismatched policy into the sealed snapshot.
	 *
	 * @return array<string, mixed>|null null when premiums disabled at seal time
	 */
	public function buildClosureAuditBlock(string $userId, int $year, int $month): ?array
	{
		if (!$this->isEnabled()) {
			return null;
		}
		if ($month < 1 || $month > 12) {
			throw new \InvalidArgumentException('Month must be between 1 and 12.');
		}

		$locking = $this->lockingProvider ?? \OCP\Server::get(\OCP\Lock\ILockingProvider::class);
		$lockKey = DbLockKeys::premiumPolicy();
		$locking->acquireLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE, 'Premium seal snapshot');
		try {
			$policy = $this->getPolicy();
			$version = (int)$this->config->getAppValue(
				'arbeitszeitcheck',
				Constants::CONFIG_PREMIUM_POLICY_VERSION,
				'0'
			);
			$start = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
			$end = (clone $start)->modify('last day of this month');
			$summary = $this->summariseForUser($userId, $start, $end, $policy);

			return [
				'enabled' => true,
				'policy_version' => $version,
				'policy' => $policy !== null ? $policy->toArray() : $this->getPolicyArrayOrDefault(),
				'summary' => $summary,
				'orthogonal_to_saldo' => true,
				'currency_mode' => 'hours_only',
			];
		} finally {
			try {
				$locking->releaseLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
				// best-effort
			}
		}
	}

	/**
	 * Multi-user period report for managers/admins (live classification).
	 *
	 * @param list<string> $userIds
	 * @return array<string, mixed>
	 */
	public function buildPeriodReport(array $userIds, \DateTimeInterface $start, \DateTimeInterface $end, callable $displayNameForUser): array
	{
		$periodStart = \DateTimeImmutable::createFromInterface($start)->format('Y-m-d');
		$periodEnd = \DateTimeImmutable::createFromInterface($end)->format('Y-m-d');
		$enabled = $this->isEnabled();
		$policy = $this->getPolicy();
		$version = (int)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_PREMIUM_POLICY_VERSION,
			'0'
		);
		$users = [];
		$totalValued = 0.0;
		$totalClassified = 0.0;
		if ($enabled) {
			foreach ($userIds as $uid) {
				$uid = trim((string)$uid);
				if ($uid === '') {
					continue;
				}
				$summary = $this->summariseForUser($uid, $start, $end);
				$totalValued += (float)($summary['total_valued_hours'] ?? 0);
				$totalClassified += (float)($summary['total_classified_hours'] ?? 0);
				$users[] = [
					'user_id' => $uid,
					'display_name' => (string)$displayNameForUser($uid),
					'buckets' => $summary['buckets'] ?? [],
					'total_classified_hours' => (float)($summary['total_classified_hours'] ?? 0),
					'total_valued_hours' => (float)($summary['total_valued_hours'] ?? 0),
					'stacking' => $summary['stacking'] ?? null,
				];
			}
		}

		return [
			'type' => 'premium',
			'enabled' => $enabled,
			'period' => [
				'start' => $periodStart,
				'end' => $periodEnd,
			],
			'policy_version' => $version,
			'stacking' => $policy?->getStacking(),
			'currency_mode' => 'hours_only',
			'orthogonal_to_saldo' => true,
			'total_users' => count($users),
			'total_classified_hours' => round($totalClassified, 4),
			'total_valued_hours' => round($totalValued, 4),
			'users' => $users,
			'note' => $enabled ? null : 'premium_disabled',
		];
	}

	/**
	 * Flat CSV rows (one row per user × bucket). Empty buckets still emit a zero row when enabled.
	 *
	 * @param array<string, mixed> $report from buildPeriodReport
	 * @return list<array<string, string|float|int>>
	 */
	public function flattenReportToCsvRows(array $report): array
	{
		$rows = [];
		$periodStart = (string)(($report['period']['start'] ?? ''));
		$periodEnd = (string)(($report['period']['end'] ?? ''));
		$policyVersion = (int)($report['policy_version'] ?? 0);
		$stacking = (string)($report['stacking'] ?? '');
		foreach ((array)($report['users'] ?? []) as $user) {
			if (!is_array($user)) {
				continue;
			}
			$uid = (string)($user['user_id'] ?? '');
			$name = (string)($user['display_name'] ?? $uid);
			$buckets = (array)($user['buckets'] ?? []);
			if ($buckets === []) {
				$rows[] = [
					'user_id' => $uid,
					'display_name' => $name,
					'period_start' => $periodStart,
					'period_end' => $periodEnd,
					'bucket_id' => '',
					'bucket_label' => '',
					'hours' => 0.0,
					'rate' => 0.0,
					'valued_hours' => 0.0,
					'stacking' => $stacking,
					'policy_version' => $policyVersion,
				];
				continue;
			}
			foreach ($buckets as $bucket) {
				if (!is_array($bucket)) {
					continue;
				}
				$rows[] = [
					'user_id' => $uid,
					'display_name' => $name,
					'period_start' => $periodStart,
					'period_end' => $periodEnd,
					'bucket_id' => (string)($bucket['id'] ?? ''),
					'bucket_label' => (string)($bucket['label'] ?? ''),
					'hours' => (float)($bucket['hours'] ?? 0),
					'rate' => (float)($bucket['rate'] ?? 0),
					'valued_hours' => (float)($bucket['valued_hours'] ?? 0),
					'stacking' => $stacking,
					'policy_version' => $policyVersion,
				];
			}
		}

		return $rows;
	}

	/**
	 * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
	 */
	public function workIntervalsFromEntry(TimeEntry $entry): array
	{
		$start = \DateTimeImmutable::createFromMutable($entry->getStartTime());
		$end = \DateTimeImmutable::createFromMutable($entry->getEndTime());
		if ($end <= $start) {
			return [];
		}

		$breaks = $this->breakIntervals($entry, $start->getTimezone());
		if ($breaks === []) {
			return [[$start, $end]];
		}

		$work = [];
		$cursor = $start;
		foreach ($breaks as [$bStart, $bEnd]) {
			if ($bEnd <= $cursor || $bStart >= $end) {
				continue;
			}
			$bs = $bStart < $cursor ? $cursor : $bStart;
			$be = $bEnd > $end ? $end : $bEnd;
			if ($cursor < $bs) {
				$work[] = [$cursor, $bs];
			}
			$cursor = $be > $cursor ? $be : $cursor;
		}
		if ($cursor < $end) {
			$work[] = [$cursor, $end];
		}

		return $work;
	}

	/**
	 * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
	 */
	private function breakIntervals(TimeEntry $entry, \DateTimeZone $tz): array
	{
		$out = [];
		$raw = $entry->getBreaks();
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				foreach ($decoded as $break) {
					if (!is_array($break) || !isset($break['start'], $break['end'])) {
						continue;
					}
					try {
						$bs = (new \DateTimeImmutable((string)$break['start']))->setTimezone($tz);
						$be = (new \DateTimeImmutable((string)$break['end']))->setTimezone($tz);
					} catch (\Exception) {
						continue;
					}
					if ($be > $bs) {
						$out[] = [$bs, $be];
					}
				}
			}
		}
		$bStart = $entry->getBreakStartTime();
		$bEnd = $entry->getBreakEndTime();
		if ($bStart !== null && $bEnd !== null && $bEnd > $bStart) {
			$out[] = [
				\DateTimeImmutable::createFromMutable($bStart),
				\DateTimeImmutable::createFromMutable($bEnd),
			];
		}
		usort($out, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

		return $out;
	}

	/**
	 * @return array{0: ?WeekdaySchedule, 1: float}
	 */
	private function resolveDailyTarget(string $userId): array
	{
		$daily = 8.0;
		$schedule = null;
		$userModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		if ($userModel) {
			try {
				$model = $this->workingTimeModelMapper->find($userModel->getWorkingTimeModelId());
				$schedule = $model->getWeekdaySchedule();
				$daily = $schedule !== null
					? $schedule->averageDailyNetHours()
					: (float)$model->getDailyHours();
			} catch (\Throwable) {
				// defaults
			}
		}

		return [$schedule, $daily];
	}
}
