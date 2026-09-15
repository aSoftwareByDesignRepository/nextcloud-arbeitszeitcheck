<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCP\IUserManager;

class DashboardWidgetDataService {
	private const MAX_MANAGER_MEMBERS = 50;
	/** Max accounts scanned for admin widget status summary (DoS guard). */
	private const MAX_ADMIN_USERS_SCAN = Constants::MAX_LIST_LIMIT;
	private const MAX_ADMIN_WIDGET_USERS = 50;

	public function __construct(
		private readonly TimeTrackingService $timeTrackingService,
		private readonly OvertimeService $overtimeService,
		private readonly OvertimeDisplayService $overtimeDisplayService,
		private readonly OvertimeBankService $overtimeBankService,
		private readonly AbsenceService $absenceService,
		private readonly AbsenceMapper $absenceMapper,
		private readonly TeamResolverService $teamResolverService,
		private readonly PermissionService $permissionService,
		private readonly IUserManager $userManager,
		private readonly TimeZoneService $timeZoneService,
		private readonly TimeCaptureMethodService $timeCaptureMethodService,
		private readonly ProjectCheckIntegrationService $projectCheckIntegration,
		private readonly VacationHoursDebitService $vacationHoursDebitService,
		private readonly TimeEntryMapper $timeEntryMapper,
	) {
	}

	/**
	 * Lean status payload for the dashboard desklet.
	 *
	 * The desklet only renders the current punch-clock state, today's hours, the
	 * running session and the time-capture flags. It must NOT trigger the heavy
	 * overtime/vacation/traffic-light computations that {@see getEmployeeWidgetData()}
	 * performs for the native widget and the mobile bootstrap (those generated
	 * ~100 queries per poll, every 30s, per user). Keep this method query-cheap.
	 *
	 * @return array<string, mixed>
	 */
	public function getEmployeeStatusSummary(string $userId): array {
		$status = $this->timeTrackingService->getStatus($userId);

		$sessionStartFormatted = $this->formatInstantForWidget(
			(string)($status['current_entry']['startTime'] ?? ''),
			$userId
		);

		return [
			'userId'                 => $userId,
			'status'                 => (string)($status['status'] ?? 'clocked_out'),
			'workingTodayHours'      => (float)($status['working_today_hours'] ?? 0.0),
			'atDailyMaximum'         => (bool)($status['at_daily_maximum'] ?? false),
			'currentSessionDuration' => (int)($status['current_session_duration'] ?? 0),
			// Drift-safe timer anchor (same fields as GET /api/clock/status).
			'serverNow'              => (string)($status['server_now'] ?? ''),
			'serverTimezone'         => (string)($status['server_timezone'] ?? ''),
			'sessionStartFormatted'  => $sessionStartFormatted,
			'timeCapture'            => $this->timeCaptureMethodService->getSettings($userId),
		];
	}

	/**
	 * Full employee home payload (mobile dashboard + native desklet).
	 *
	 * @param bool $vacationUnitAwareClient When false and org is in hours mode,
	 *                                      vacation amounts are converted to day-equivalents
	 *                                      and vacationUnit is forced to "days" (NN-08 / Q8).
	 *                                      Default false (fail-closed): callers must opt in.
	 *                                      Desklet and unit-aware companions pass true.
	 * @return array<string, mixed>
	 */
	public function getEmployeeWidgetData(string $userId, bool $vacationUnitAwareClient = false): array {
		$status = $this->timeTrackingService->getStatus($userId);

		// Weekly overtime (also provides cumulative balance and contract target)
		try {
			$weekly = $this->overtimeService->getWeeklyOvertime($userId);
		} catch (\Throwable $e) {
			$weekly = [];
		}

		// Break compliance status for the current session
		try {
			$breakStatus = $this->timeTrackingService->getBreakStatus($userId);
		} catch (\Throwable $e) {
			$breakStatus = [];
		}

		$vacationYear = $this->currentYearInStorage();

		// Vacation entitlement and remaining balances for current year (storage TZ)
		try {
			$vacationStats = $this->absenceService->getVacationStats($userId, $vacationYear);
		} catch (\Throwable $e) {
			$vacationStats = [];
		}

		// Format session/break start times for display in the user's Nextcloud TZ.
		$sessionStartFormatted = $this->formatInstantForWidget(
			(string)($status['current_entry']['startTime'] ?? ''),
			$userId
		);
		$breakStartFormatted = $this->formatInstantForWidget(
			(string)($status['current_entry']['breakStartTime'] ?? ''),
			$userId
		);

		$currentEntryId = null;
		if (isset($status['current_entry']['id'])) {
			$currentEntryId = (int)$status['current_entry']['id'];
			if ($currentEntryId <= 0) {
				$currentEntryId = null;
			}
		}

		$linkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);
		$projectCheckAvailable = $this->projectCheckIntegration->isProjectCheckAvailable();
		$vacationDebitSnap = $this->vacationHoursDebitService->snapshotForUser($userId);

		$hoursGlanceBundle = $this->buildHoursGlancePeriods($userId, $weekly);

		$payload = [
			'userId'                 => $userId,
			'status'                 => (string)($status['status'] ?? 'clocked_out'),
			'currentEntryId'         => $currentEntryId,
			'workingTodayHours'      => (float)($status['working_today_hours'] ?? 0.0),
			'atDailyMaximum'         => (bool)($status['at_daily_maximum'] ?? false),
			'currentSessionDuration' => (int)($status['current_session_duration'] ?? 0),
			// Drift-safe timer anchor (same fields as GET /api/clock/status).
			'serverNow'              => (string)($status['server_now'] ?? ''),
			'serverTimezone'         => (string)($status['server_timezone'] ?? ''),
			'sessionStartFormatted'  => $sessionStartFormatted,
			'breakStartTime'         => (string)($status['current_entry']['breakStartTime'] ?? ''),
			'breakStartFormatted'    => $breakStartFormatted,
			'weekHoursWorked'        => (float)($weekly['total_hours_worked'] ?? 0.0),
			'weekHoursRequired'      => (float)($weekly['required_hours'] ?? 0.0),
			'weeklyContractHours'    => (float)($weekly['weekly_hours'] ?? 40.0),
			// Contract daily hours (same as employee dashboard “Daily target”).
			'impliedDailyHours'      => (float)($weekly['implied_daily_hours'] ?? 0.0),
			'cumulativeBalance'      => (float)($weekly['cumulative_balance'] ?? 0.0),
			'displayBalance'         => $this->overtimeDisplayService->getYearToDateBalanceForTrafficLight($userId),
			// Additive GH #40 — YTD planned-hours credit when Entgeltausfall opt-in is on.
			'absenceCreditHoursYtd'  => (float)($hoursGlanceBundle['absence_credit_hours_ytd'] ?? 0.0),
			// Additive GH #37 — old companions ignore; keys are stable for client l10n.
			'hoursGlance'            => $hoursGlanceBundle['periods'],
			'overtimeBankEnabled'    => $this->overtimeBankService->isEnabled(),
			'trafficLightState'      => $this->overtimeDisplayService->buildTrafficLightViewModel($userId)['state'] ?? 'green',
			'breakRequired'          => (bool)($breakStatus['break_required'] ?? false),
			'remainingBreakMinutes'  => (int)round((float)($breakStatus['remaining_break_minutes'] ?? 0)),
			'breakWarningLevel'      => (string)($breakStatus['warning_level'] ?? 'none'),
			// Country-specific short law citation for break requirements
			// (DE: 'ArbZG §4', AT: 'AZG §11') for widget labels.
			'lawLabelBreaks'         => $this->timeTrackingService->lawProfile($userId)->lawLabel('breaks'),
			// Personal setting: server may insert ArbZG §4 breaks when none were recorded.
			'autoBreakCalculation'   => $this->timeTrackingService->isAutoBreakCalculationEnabled($userId),
			'vacationYear'           => (int)($vacationStats['year'] ?? $vacationYear),
			'vacationRemaining'      => (float)($vacationStats['remaining'] ?? 0.0),
			'vacationEntitlement'    => (float)($vacationStats['entitlement'] ?? 0.0),
			'vacationUsed'           => (float)($vacationStats['used'] ?? 0.0),
			'vacationUnit'           => (string)($vacationStats['vacation_unit'] ?? 'days'),
			'vacationHoursPerDay'    => (float)($vacationStats['vacation_hours_per_day'] ?? \OCA\ArbeitszeitCheck\Constants::DEFAULT_VACATION_HOURS_PER_DAY),
			'vacationCarryover'      => (float)($vacationStats['carryover_days'] ?? 0.0),
			'vacationCarryoverUsable'=> (float)($vacationStats['carryover_usable'] ?? 0.0),
			// Additive — old companions ignore; Q2 anniversary expiry is per-user date.
			'vacationCarryoverExpiresOn' => $vacationStats['carryover_expires_on'] ?? null,
			// Additive Phase B fields — old companions ignore safely (§16).
			'vacationYearMode'       => (string)($vacationStats['vacation_year_mode'] ?? 'calendar'),
			'vacationYearLabel'      => (string)($vacationStats['vacation_year_label'] ?? (string)($vacationStats['year'] ?? $vacationYear)),
			'vacationYearStart'      => $vacationStats['vacation_year_start'] ?? null,
			'vacationYearEnd'        => $vacationStats['vacation_year_end_inclusive'] ?? null,
			'vacationYearError'      => $vacationStats['vacation_year_error'] ?? null,
			// Additive: schedule-aware vacation debit (not org hours_per_day).
			'vacationDebitBasis'     => $vacationDebitSnap['basis'],
			'vacationWeekdayNets'    => $vacationDebitSnap['weekday_nets'],
			'vacationOneDayHours'    => $vacationDebitSnap['one_day_hours'],
			'vacationAverageDailyHours' => $vacationDebitSnap['average_daily'],
			'timeCapture'            => $this->timeCaptureMethodService->getSettings($userId),
			// ProjectCheck linking for mobile/web companions (optional; empty when off).
			'projectCheck'           => [
				'available' => $projectCheckAvailable,
				'linkingEnabled' => $linkingEnabled,
				'projects' => $linkingEnabled
					? $this->projectCheckIntegration->getAvailableProjects($userId)
					: [],
			],
			// Additive Phase D — null when premiums off (old apps ignore).
			'premiumSummary'         => $this->buildPremiumSummaryForWidget($userId),
		];

		return $this->applyVacationUnitAwareClientGate($payload, $vacationUnitAwareClient);
	}

	/**
	 * Q8 / NN-08: never send hour magnitudes to clients that hardcode a "days" label.
	 *
	 * Unaware companions receive day-equivalents with vacationUnit=days so home copy
	 * stays truthful. Booking still fail-closes without duration_hours on the server.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function applyVacationUnitAwareClientGate(array $payload, bool $vacationUnitAwareClient): array
	{
		$unit = (string)($payload['vacationUnit'] ?? Constants::VACATION_UNIT_DAYS);
		if ($vacationUnitAwareClient || $unit !== Constants::VACATION_UNIT_HOURS) {
			$payload['vacationClientUpdateRequired'] = false;
			return $payload;
		}

		$hpd = (float)($payload['vacationHoursPerDay'] ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY);
		if ($hpd < 0.0001 || !is_finite($hpd)) {
			$hpd = (float)Constants::DEFAULT_VACATION_HOURS_PER_DAY;
		}

		foreach (['vacationRemaining', 'vacationEntitlement', 'vacationUsed', 'vacationCarryover', 'vacationCarryoverUsable'] as $key) {
			if (!array_key_exists($key, $payload) || $payload[$key] === null) {
				continue;
			}
			$raw = (float)$payload[$key];
			if (!is_finite($raw)) {
				continue;
			}
			$payload[$key] = round($raw / $hpd, 2, PHP_ROUND_HALF_UP);
		}

		$payload['vacationUnit'] = Constants::VACATION_UNIT_DAYS;
		$payload['vacationClientUpdateRequired'] = true;
		return $payload;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function buildPremiumSummaryForWidget(string $userId): ?array
	{
		try {
			$svc = \OCP\Server::get(PremiumSurchargeService::class);
			if (!$svc->isEnabled()) {
				return null;
			}
			$start = new \DateTime('first day of this month');
			$end = new \DateTime('today');
			return $svc->summariseForUser($userId, $start, $end);
		} catch (\Throwable) {
			return null;
		}
	}

	public function getManagerWidgetData(string $userId, int $limit = 7): array {
		if (!$this->permissionService->canAccessManagerDashboard($userId)) {
			return [
				'authorized' => false,
				'members' => [],
				'summary' => $this->emptySummary(),
			];
		}

		$memberIds = $this->teamResolverService->getTeamMemberIds($userId);
		$summary = $this->emptySummary();
		$resolvedIds = [];
		foreach ($memberIds as $memberId) {
			if ($this->userManager->get($memberId) === null) {
				continue;
			}
			$resolvedIds[] = $memberId;
		}

		$live = $this->timeEntryMapper->findLiveStatusByUserIds($resolvedIds);
		foreach ($resolvedIds as $memberId) {
			$summary['total']++;
			$this->incrementStatus($summary, $live[$memberId] ?? 'clocked_out');
		}

		$rank = ['active' => 0, 'break' => 1, 'paused' => 2, 'clocked_out' => 3];
		usort($resolvedIds, static function (string $a, string $b) use ($live, $rank): int {
			$sa = $live[$a] ?? 'clocked_out';
			$sb = $live[$b] ?? 'clocked_out';
			return ($rank[$sa] ?? 3) <=> ($rank[$sb] ?? 3) ?: strcmp($a, $b);
		});

		$members = [];
		$effectiveLimit = max(1, min(self::MAX_MANAGER_MEMBERS, $limit));
		foreach (array_slice($resolvedIds, 0, $effectiveLimit) as $memberId) {
			$member = $this->userManager->get($memberId);
			if ($member === null) {
				continue;
			}
			$statusKey = $live[$memberId] ?? 'clocked_out';
			$hours = 0.0;
			if ($statusKey !== 'clocked_out') {
				try {
					$status = $this->timeTrackingService->getStatus($memberId);
					$statusKey = (string)($status['status'] ?? $statusKey);
					$hours = (float)($status['working_today_hours'] ?? 0.0);
				} catch (\Throwable) {
					// Keep batch status; hours stay 0.
				}
			}
			$members[] = [
				'userId' => $memberId,
				'displayName' => $member->getDisplayName(),
				'status' => $statusKey,
				'workingTodayHours' => $hours,
			];
		}

		return [
			'authorized' => true,
			'members' => $members,
			'summary' => $summary,
			'absenceSummary' => $this->buildTeamAbsenceSummary($memberIds),
		];
	}

	public function getAdminWidgetData(string $userId, int $limit = 10): array {
		if (!$this->permissionService->isAdmin($userId)) {
			return [
				'authorized' => false,
				'users' => [],
				'summary' => $this->emptySummary(),
				'absenceSummary' => [
					'vacation' => 0,
					'sick' => 0,
					'other_absent' => 0,
					'total_absent' => 0,
				],
				'summaryTruncated' => false,
				'summaryScopeLimit' => self::MAX_ADMIN_USERS_SCAN,
				'directoryTotal' => 0,
			];
		}

		$summary = $this->emptySummary();
		$users = [];
		$effectiveLimit = max(1, min(self::MAX_ADMIN_WIDGET_USERS, $limit));
		$allUsers = $this->userManager->search('', self::MAX_ADMIN_USERS_SCAN, 0);
		$enabled = [];
		foreach ($allUsers as $user) {
			if (!$user->isEnabled()) {
				continue;
			}
			$enabled[] = $user;
		}

		$allUserIds = array_map(static fn ($user) => $user->getUID(), $enabled);
		$live = $this->timeEntryMapper->findLiveStatusByUserIds($allUserIds);
		foreach ($enabled as $user) {
			$summary['total']++;
			$this->incrementStatus($summary, $live[$user->getUID()] ?? 'clocked_out');
		}

		$rank = ['active' => 0, 'break' => 1, 'paused' => 2, 'clocked_out' => 3];
		usort($enabled, static function ($a, $b) use ($live, $rank): int {
			$sa = $live[$a->getUID()] ?? 'clocked_out';
			$sb = $live[$b->getUID()] ?? 'clocked_out';
			$byStatus = ($rank[$sa] ?? 3) <=> ($rank[$sb] ?? 3);
			if ($byStatus !== 0) {
				return $byStatus;
			}
			return strcmp($a->getDisplayName(), $b->getDisplayName());
		});

		foreach (array_slice($enabled, 0, $effectiveLimit) as $user) {
			$uid = $user->getUID();
			$statusKey = $live[$uid] ?? 'clocked_out';
			$hours = 0.0;
			if ($statusKey !== 'clocked_out') {
				try {
					$status = $this->timeTrackingService->getStatus($uid);
					$statusKey = (string)($status['status'] ?? $statusKey);
					$hours = (float)($status['working_today_hours'] ?? 0.0);
				} catch (\Throwable) {
					// Keep batch status; hours stay 0.
				}
			}
			$users[] = [
				'userId' => $uid,
				'displayName' => $user->getDisplayName(),
				'status' => $statusKey,
				'workingTodayHours' => $hours,
			];
		}

		$directoryTotal = $this->userManager->countUsersTotal(0, false);
		if ($directoryTotal === false) {
			$directoryTotal = $summary['total'];
		}
		$hitScanCap = count($allUsers) >= self::MAX_ADMIN_USERS_SCAN;
		// Only flag truncation when the scan window was exhausted (not when disabled accounts inflate directoryTotal).
		$summaryTruncated = $hitScanCap;

		return [
			'authorized' => true,
			'users' => $users,
			'summary' => $summary,
			'absenceSummary' => $this->buildTeamAbsenceSummary($allUserIds),
			'summaryTruncated' => $summaryTruncated,
			'summaryScopeLimit' => self::MAX_ADMIN_USERS_SCAN,
			'directoryTotal' => (int)$directoryTotal,
		];
	}

	private function emptySummary(): array {
		return [
			'total' => 0,
			'active' => 0,
			'break' => 0,
			'paused' => 0,
			'clocked_out' => 0,
			'other' => 0,
		];
	}

	private function formatInstantForWidget(string $raw, string $userId): string {
		if ($raw === '') {
			return '';
		}
		try {
			$instant = $this->parseWidgetInstant($raw);
			return $this->timeZoneService->formatForDisplay($instant, 'H:i', $userId);
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Parse a clock instant from widget status data (ISO-8601 from API or naive SQL).
	 */
	private function parseWidgetInstant(string $raw): \DateTimeInterface {
		$trimmed = trim($raw);
		if ($trimmed === '') {
			throw new \InvalidArgumentException('Empty instant');
		}
		if (preg_match('/[TZ]|(?:[+-]\d{2}:?\d{2})$/', $trimmed) === 1) {
			return $this->timeZoneService->fromIso($trimmed);
		}
		return $this->timeZoneService->hydrateNaiveAny(new \DateTimeImmutable($trimmed));
	}

	private function currentYearInStorage(): int {
		return (int)$this->timeZoneService->nowImmutableInStorage()->format('Y');
	}

	private function incrementStatus(array &$summary, string $status): void {
		if (isset($summary[$status])) {
			$summary[$status]++;
			return;
		}
		$summary['other']++;
	}

	private function buildTeamAbsenceSummary(array $memberIds): array {
		if ($memberIds === []) {
			return [
				'vacation' => 0,
				'sick' => 0,
				'other_absent' => 0,
				'total_absent' => 0,
			];
		}

		[$todayStart] = $this->timeZoneService->todayWindowInStorage();

		try {
			$activeAbsences = $this->absenceMapper->findByUsersAndDateRange(
				$memberIds,
				$todayStart,
				$todayStart,
				Absence::STATUS_APPROVED
			);
		} catch (\Throwable $e) {
			return [
				'vacation' => 0,
				'sick' => 0,
				'other_absent' => 0,
				'total_absent' => 0,
			];
		}

		$vacationUsers = [];
		$sickUsers = [];
		$otherUsers = [];

		foreach ($activeAbsences as $absence) {
			$uid = (string)$absence->getUserId();
			$type = (string)$absence->getType();

			if ($type === Absence::TYPE_VACATION) {
				$vacationUsers[$uid] = true;
				continue;
			}
			if ($type === Absence::TYPE_SICK_LEAVE) {
				$sickUsers[$uid] = true;
				continue;
			}
			$otherUsers[$uid] = true;
		}

		$totalAbsentUsers = $vacationUsers + $sickUsers + $otherUsers;

		return [
			'vacation' => count($vacationUsers),
			'sick' => count($sickUsers),
			'other_absent' => count($otherUsers),
			'total_absent' => count($totalAbsentUsers),
		];
	}

	/**
	 * Additive hours Ist/Soll glance for mobile/web companions (GH #37).
	 * Stable keys: today|week|month|year — clients own labels.
	 *
	 * @param array<string, mixed> $weekly Already-fetched weekly overtime row (avoid double work).
	 * @return array{
	 *   periods: list<array{key: string, worked: float, target: float, delta: float, period: string}>,
	 *   absence_credit_hours_ytd: float
	 * }
	 */
	private function buildHoursGlancePeriods(string $userId, array $weekly): array
	{
		$safe = static function (callable $fn): array {
			try {
				$row = $fn();
				return \is_array($row) ? $row : [];
			} catch (\Throwable $e) {
				return [];
			}
		};

		$day = $safe(fn (): array => $this->overtimeService->getDailyOvertime($userId));
		$month = $safe(fn (): array => $this->overtimeService->calculateMonthlyOvertime($userId));
		$year = $safe(fn (): array => $this->overtimeService->calculateYearlyOvertime($userId));

		$formatPeriod = static function (array $row): string {
			$start = (string)($row['period_start'] ?? '');
			$end = (string)($row['period_end'] ?? '');
			if ($start !== '' && $end !== '' && $start !== $end) {
				return $start . ' – ' . $end;
			}
			return $start !== '' ? $start : $end;
		};

		$pack = static function (string $key, array $row) use ($formatPeriod): array {
			$worked = round((float)($row['total_hours_worked'] ?? 0), 2);
			$target = round((float)($row['required_hours'] ?? 0), 2);
			return [
				'key' => $key,
				'worked' => $worked,
				'target' => $target,
				'delta' => round($worked - $target, 2),
				'period' => $formatPeriod($row),
			];
		};

		return [
			'periods' => [
				$pack('today', $day),
				$pack('week', $weekly),
				$pack('month', $month),
				$pack('year', $year),
			],
			// GH #40 — companions show note when opt-in credit > 0; old clients ignore key.
			'absence_credit_hours_ytd' => round(max(0.0, (float)($year['absence_credit_hours'] ?? 0.0)), 2),
		];
	}
}
