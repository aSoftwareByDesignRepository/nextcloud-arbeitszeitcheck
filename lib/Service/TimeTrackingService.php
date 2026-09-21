<?php

declare(strict_types=1);

/**
 * TimeTracking service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Support\BreakCountable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;

/**
 * TimeTracking service for time tracking business logic
 */
class TimeTrackingService
{
	private TimeEntryMapper $timeEntryMapper;
	private ComplianceViolationMapper $violationMapper;
	private AuditLogMapper $auditLogMapper;
	private ProjectCheckIntegrationService $projectCheckService;
	private ComplianceService $complianceService;
	private IL10N $l10n;
	private IConfig $config;
	private UserSettingsMapper $userSettingsMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private MonthClosureGuard $monthClosureGuard;
	private IDBConnection $db;
	private ILockingProvider $lockingProvider;
	private TimeZoneService $timeZoneService;
	private DailyWorkingHoursCalculator $dailyWorkingHoursCalculator;
	private ?ProjectCheckLaborTimeSyncService $projectCheckLaborSync;
	private TimeCaptureMethodService $timeCaptureMethodService;
	private \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory $lawProfileFactory;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		ComplianceViolationMapper $violationMapper,
		AuditLogMapper $auditLogMapper,
		ProjectCheckIntegrationService $projectCheckService,
		ComplianceService $complianceService,
		IL10N $l10n,
		IConfig $config,
		UserSettingsMapper $userSettingsMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		MonthClosureGuard $monthClosureGuard,
		IDBConnection $db,
		ILockingProvider $lockingProvider,
		TimeZoneService $timeZoneService,
		DailyWorkingHoursCalculator $dailyWorkingHoursCalculator,
		?ProjectCheckLaborTimeSyncService $projectCheckLaborSync = null,
		?TimeCaptureMethodService $timeCaptureMethodService = null,
		?\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory $lawProfileFactory = null,
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->violationMapper = $violationMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->projectCheckService = $projectCheckService;
		$this->complianceService = $complianceService;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->monthClosureGuard = $monthClosureGuard;
		$this->db = $db;
		$this->lockingProvider = $lockingProvider;
		$this->timeZoneService = $timeZoneService;
		$this->dailyWorkingHoursCalculator = $dailyWorkingHoursCalculator;
		$this->projectCheckLaborSync = $projectCheckLaborSync;
		$this->timeCaptureMethodService = $timeCaptureMethodService ?? new TimeCaptureMethodService(
			$userSettingsMapper,
			$auditLogMapper,
			$config,
			$l10n,
		);
		$this->lawProfileFactory = $lawProfileFactory
			?? new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($config, $userSettingsMapper);
	}

	/**
	 * Working-time law profile for the instance, or for a specific user when
	 * an optional per-user labour-law country override is set (E-9).
	 */
	public function lawProfile(?string $userId = null): \OCA\ArbeitszeitCheck\Support\LaborLawProfile
	{
		return $this->lawProfileFactory->getProfile($userId);
	}

	/**
	 * @return array{date: string, hours: float}|null First calendar day that would exceed ArbZG §3.
	 */
	public function findCalendarDayExceedingMaximum(TimeEntry $entry, ?int $excludeEntryId = null): ?array
	{
		if ($entry->getStartTime() === null || $entry->getEndTime() === null) {
			return null;
		}

		return $this->dailyWorkingHoursCalculator->findCalendarDayExceedingMaximum(
			$entry->getUserId(),
			$entry,
			$this->getMaxDailyHours(),
			$excludeEntryId ?? $entry->getId(),
		);
	}

	private function getMaxDailyHours(): float
	{
		$default = (string)$this->lawProfile()->dailyMaxHoursDefault;

		return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', $default)));
	}

	private function getMinRestPeriod(): float
	{
		$default = (string)$this->lawProfile()->minRestHoursDefault;

		return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'min_rest_period', $default)));
	}

	private function getAppConfiguredTimeZone(): \DateTimeZone
	{
		return $this->timeZoneService->storageTimeZone();
	}

	/**
	 * “Now” for persisting or comparing {@see TimeEntry} naive SQL datetimes (always organisation storage TZ).
	 */
	private function nowForAtEntries(): \DateTime
	{
		return $this->timeZoneService->nowInStorage();
	}

	private function resolveEffectiveAt(?\DateTimeInterface $effectiveAt): \DateTime
	{
		if ($effectiveAt === null) {
			return $this->nowForAtEntries();
		}
		if ($effectiveAt instanceof \DateTime) {
			return clone $effectiveAt;
		}
		return \DateTime::createFromInterface($effectiveAt);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function offlineCaptureAuditMeta(?\DateTimeInterface $effectiveAt, string $userId): array
	{
		if ($effectiveAt === null) {
			return [];
		}
		return [
			'capture_source' => Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC,
			'client_occurred_at' => $this->timeZoneService->formatForDisplay(
				$effectiveAt instanceof \DateTime ? $effectiveAt : \DateTime::createFromInterface($effectiveAt),
				'c',
				$userId,
			),
		];
	}

	/**
	 * Inclusive/exclusive bounds of the current calendar day in {@see getAppConfiguredTimeZone()},
	 * formatted as naive `Y-m-d H:i:s` values (same convention as stored `at_entries` timestamps).
	 *
	 * @return array{\DateTime, \DateTime}
	 */
	private function getAppLocalTodayWindow(): array
	{
		return $this->timeZoneService->todayWindowInStorage();
	}

	/**
	 * Heal legacy/orphan {@see TimeEntry::STATUS_PAUSED} automatic rows from previous calendar days.
	 *
	 * The current code path never produces `paused` rows; this defense-in-depth sweep
	 * exists for entries that pre-date the {@see \OCA\ArbeitszeitCheck\Migration\Version1020Date20260421000000}
	 * fix, or for any future regression that might slip one through (e.g. an aborted
	 * clock-out on an unstable connection). It is intentionally invoked from read
	 * paths (e.g. {@see getStatus()}, {@see clockIn()}) so users never get stuck
	 * with a frozen "paused" status that requires admin intervention to resolve.
	 *
	 * Safe to call from read paths: failures are swallowed and logged. A user-visible
	 * banner is stored under `auto_clockout_notice` so the affected user knows the
	 * system finalised something on their behalf.
	 */
	private function repairStalePausedAutomaticEntries(string $userId): void
	{
		try {
			[$todayStart] = $this->getAppLocalTodayWindow();
			$stale = $this->timeEntryMapper->findStalePausedAutomaticEntries($userId, $todayStart);
			if ($stale === []) {
				return;
			}

			$repaired = 0;
			foreach ($stale as $entry) {
				try {
					$this->monthClosureGuard->assertTimeEntryMutable($entry);
				} catch (MonthFinalizedException $e) {
					continue;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Stale paused repair skipped (guard): ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entry->getId()]
					);
					continue;
				}

				$start = $entry->getStartTime();
				if ($start === null) {
					continue;
				}

				$oldSummary = $this->safeGetSummary($entry, $userId);
				$endCandidate = $entry->getUpdatedAt();
				if ($endCandidate === null) {
					$endCandidate = clone $start;
				} else {
					$endCandidate = clone $endCandidate;
				}
				if ($endCandidate < $start) {
					$endCandidate = clone $start;
				}

				$entry->setEndTime($endCandidate);
				$entry->setStatus(TimeEntry::STATUS_COMPLETED);
				$entry->setEndedReason(TimeEntry::ENDED_REASON_STALE_PAUSED_REPAIR);
				$entry->setPolicyApplied('repair');
				$entry->setUpdatedAt($this->nowForAtEntries());

				try {
					$this->calculateAndSetAutomaticBreak($entry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Automatic break during stale paused repair failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entry->getId()]
					);
				}

				try {
					$this->adjustEndTimeForDailyMaximum($entry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Daily maximum adjustment during stale paused repair failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entry->getId()]
					);
				}

				$updated = $this->timeEntryMapper->update($entry);
				$repaired++;

				try {
					$this->auditLogMapper->logAction(
						$userId,
						'stale_paused_repaired',
						'time_entry',
						$updated->getId(),
						$oldSummary,
						$this->safeGetSummary($updated, $userId)
					);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Audit log for stale paused repair failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $updated->getId()]
					);
				}

				try {
					$this->checkComplianceAfterClockOut($updated);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Compliance check after stale paused repair failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $updated->getId()]
					);
				}
			}

			if ($repaired > 0) {
				$noticeMessage = $this->l10n->n(
					'An unfinished session from a previous day was closed automatically. Please verify your time entries list.',
					'%n unfinished sessions from previous days were closed automatically. Please verify your time entries list.',
					$repaired
				);
				$this->config->setUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice', json_encode([
					'message' => $noticeMessage,
					'reason' => TimeEntry::ENDED_REASON_STALE_PAUSED_REPAIR,
					'count' => $repaired,
					'at' => $this->nowForAtEntries()->format('c'),
				]));
			}
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'repairStalePausedAutomaticEntries skipped: ' . $e->getMessage(),
				['exception' => $e, 'user_id' => $userId]
			);
		}
	}

	/**
	 * Whether the configured daily maximum is reached/exceeded for the given user.
	 *
	 * This uses getTodayHours(), which already applies overlap-safe calculation and
	 * includes active sessions.
	 */
	public function isAtOrAboveDailyMaximum(string $userId): bool
	{
		return $this->getTodayHours($userId) >= $this->getMaxDailyHours();
	}

	/**
	 * @return string|null Normalized project id (null when empty / not allowed)
	 */
	private function normalizeAndAssertProjectCheckForClockIn(string $userId, ?string $projectCheckProjectId): ?string
	{
		if ($projectCheckProjectId === null) {
			return null;
		}
		$projectCheckProjectId = trim($projectCheckProjectId);
		if ($projectCheckProjectId === '') {
			return null;
		}
		if (mb_strlen($projectCheckProjectId) > TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH) {
			throw new BusinessRuleException(
				$this->l10n->t('Project ID must not exceed %d characters', [TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH]),
				BusinessRuleCode::PROJECT_ID_TOO_LONG,
			);
		}
		if (!$this->projectCheckService->userMayAttachProjectCheckProjectToOwnTime($userId, $projectCheckProjectId)) {
			throw new BusinessRuleException(
				$this->l10n->t('You cannot clock in on the selected project. If the project uses per-person rates, you must be on the project team. Otherwise ask a ProjectCheck administrator for access.'),
				BusinessRuleCode::PROJECT_NOT_ALLOWED,
			);
		}
		return $projectCheckProjectId;
	}

	/**
	 * Clock in a user (start working)
	 *
	 * @param string $userId
	 * @param string|null $projectCheckProjectId
	 * @param string|null $description
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function clockIn(
		string $userId,
		?string $projectCheckProjectId = null,
		?string $description = null,
		?\DateTimeInterface $effectiveAt = null,
	): TimeEntry {
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$this->repairStalePausedAutomaticEntries($userId);
			$this->db->beginTransaction();
			try {
				$eventAt = $this->resolveEffectiveAt($effectiveAt);
				$this->monthClosureGuard->assertUserDayMutable($userId, $eventAt);
				$projectCheckProjectId = $this->normalizeAndAssertProjectCheckForClockIn($userId, $projectCheckProjectId);
				$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
				if ($activeEntry !== null) {
					throw new BusinessRuleException(
						$this->l10n->t('User is already clocked in'),
						BusinessRuleCode::ALREADY_CLOCKED_IN,
					);
				}

				$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
				if ($breakEntry !== null) {
					throw new BusinessRuleException(
						$this->l10n->t('User is currently on break. End break first.'),
						BusinessRuleCode::ON_BREAK_END_FIRST,
					);
				}

				$pendingOpen = $this->timeEntryMapper->findPendingOpenSessionByUser($userId);
				if ($pendingOpen !== null) {
					throw new BusinessRuleException(
						$this->l10n->t('A correction request is still pending for an unfinished session. Cancel it or wait for approval before clocking in.'),
						BusinessRuleCode::OPEN_SESSION_CORRECTION_PENDING,
					);
				}

				[$today, $tomorrow] = $this->getAppLocalTodayWindow();
				$pausedTodayEntry = $this->timeEntryMapper->findPausedOrUnfinishedTodayByUser($userId, $today, $tomorrow);
				if ($pausedTodayEntry !== null && $pausedTodayEntry->getStatus() === TimeEntry::STATUS_PAUSED) {
					$this->timeCaptureMethodService->assertClockStampingAllowed($userId);
					$existingProject = $pausedTodayEntry->getProjectCheckProjectId();
					$existingNorm = $existingProject !== null ? trim((string)$existingProject) : '';
					$requestedNorm = $projectCheckProjectId !== null ? trim((string)$projectCheckProjectId) : '';
					// Switching ProjectCheck projects mid-day must create a new session.
					// Never overwrite the paused row's project — that would re-bill earlier
					// hours onto the newly selected customer project.
					if ($requestedNorm !== '' && $requestedNorm !== $existingNorm) {
						$this->completePausedEntryBeforeProjectSwitch($userId, $pausedTodayEntry);
						// Fall through to create a fresh clock-in with the new project.
					} else {
						$resumed = $this->resumePausedEntry($userId, $pausedTodayEntry, $projectCheckProjectId, $description);
						$this->db->commit();
						return $resumed;
					}
				}

				$this->timeCaptureMethodService->assertClockStampingAllowed($userId);

				$this->checkComplianceBeforeClockIn($userId, $eventAt);
				$todayHours = $this->getTodayHours($userId);
				$maxDailyHours = $this->getMaxDailyHours();
				if ($todayHours >= $maxDailyHours) {
					throw new BusinessRuleException(
						$this->l10n->t(
							'Cannot clock in: Maximum daily working hours (%1$dh) already reached. You have already worked %2$.1f hours today (%3$s).',
							[(int)$maxDailyHours, $todayHours, $this->lawProfile($userId)->lawLabel('daily')]
						),
						BusinessRuleCode::DAILY_HOURS_LIMIT,
					);
				}

				$now = $eventAt;
				$timeEntry = new TimeEntry();
				$timeEntry->setUserId($userId);
				$timeEntry->setStartTime($now);
				$timeEntry->setStatus(TimeEntry::STATUS_ACTIVE);
				$timeEntry->setEndedReason(null);
				$timeEntry->setPolicyApplied(null);
				$timeEntry->setIsManualEntry(false);
				$timeEntry->setProjectCheckProjectId($projectCheckProjectId);
				$timeEntry->setDescription($description);
				$timeEntry->setCreatedAt($now);
				$timeEntry->setUpdatedAt($now);

				$savedEntry = $this->timeEntryMapper->insert($timeEntry);
				$this->auditLogMapper->logAction(
					$userId,
					'clock_in',
					'time_entry',
					$savedEntry->getId(),
					null,
					array_merge($this->safeGetSummary($savedEntry, $userId), $this->offlineCaptureAuditMeta($effectiveAt, $userId))
				);
				$this->clearAutoClockoutNotice($userId);
				$this->db->commit();
				return $savedEntry;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Clock out a user (end working)
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function clockOut(
		string $userId,
		string $endedReason = TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT,
		string $policyApplied = 'standard',
		?\DateTimeInterface $effectiveAt = null,
	): TimeEntry {
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			// Phase 1: persist the clock-out atomically. Compliance checks are
			// deliberately kept OUT of this transaction so a defect or transient
			// error in a downstream check can never roll back the clock-out itself
			// (clock-out is the user's right; compliance is a best-effort side effect).
			$this->db->beginTransaction();
			try {
				$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
				$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
				$currentEntry = $activeEntry ?: $breakEntry;
				if ($currentEntry === null) {
					throw new BusinessRuleException(
						$this->l10n->t('User is not currently clocked in'),
						BusinessRuleCode::NOT_CLOCKED_IN,
					);
				}

				$this->monthClosureGuard->assertTimeEntryMutable($currentEntry);
				$oldSummary = $this->safeGetSummary($currentEntry, $userId);
				$now = $this->resolveEffectiveAt($effectiveAt);
				if ($currentEntry->getStatus() === TimeEntry::STATUS_BREAK && $currentEntry->getBreakStartTime() !== null) {
					$this->archiveBreakToJson($currentEntry, $currentEntry->getBreakStartTime(), $now);
					$currentEntry->setBreakStartTime(null);
					$currentEntry->setBreakEndTime(null);
				}

				$currentEntry->setEndTime($now);
				$currentEntry->setStatus(TimeEntry::STATUS_COMPLETED);
				$currentEntry->setEndedReason($endedReason);
				$currentEntry->setPolicyApplied($policyApplied);
				$currentEntry->setUpdatedAt($now);
				try {
					$this->calculateAndSetAutomaticBreak($currentEntry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error(
						'calculateAndSetAutomaticBreak failed after clock-out; entry was still completed: ' . $e->getMessage(),
						[
							'exception' => $e,
							'user_id' => $userId,
							'entry_id' => $currentEntry->getId(),
						]
					);
				}

				$updatedEntry = $this->timeEntryMapper->update($currentEntry);
				$this->auditLogMapper->logAction(
					$userId,
					'clock_out',
					'time_entry',
					$updatedEntry->getId(),
					$oldSummary,
					$this->safeGetSummary($updatedEntry, $userId)
				);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			// Phase 2: best-effort compliance evaluation. Each individual rule is
			// already isolated inside ComplianceService::checkComplianceAfterClockOut,
			// but we still wrap the call defensively so that absolutely no failure
			// here can prevent the clock-out from being reported as successful.
			try {
				$this->checkComplianceAfterClockOut($updatedEntry);
			} catch (\Throwable $complianceError) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Compliance check after manual clock-out failed: ' . $complianceError->getMessage(),
					[
						'exception' => $complianceError,
						'user_id'   => $userId,
						'entry_id'  => $updatedEntry->getId(),
					]
				);
			}

			if ($this->projectCheckLaborSync !== null) {
				try {
					$this->projectCheckLaborSync->syncFromTimeEntry($updatedEntry, $userId);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'ProjectCheck billing sync after clock-out failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}

			return $updatedEntry;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Complete a same-day paused entry at its pause instant so a new clock-in
	 * can start on a different ProjectCheck project without rewriting history.
	 *
	 * End time is the pause moment ({@see TimeEntry::getUpdatedAt()}), not "now",
	 * so idle time after pause is not billed as work.
	 */
	private function completePausedEntryBeforeProjectSwitch(string $userId, TimeEntry $pausedEntry): void
	{
		$this->monthClosureGuard->assertTimeEntryMutable($pausedEntry);

		$now = $this->nowForAtEntries();
		$pausedAt = $pausedEntry->getUpdatedAt() ?? $now;
		$oldSummary = $this->safeGetSummary($pausedEntry, $userId);

		$pausedEntry->setEndTime($pausedAt);
		$pausedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$pausedEntry->setEndedReason(TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT);
		$pausedEntry->setPolicyApplied('project_switch');
		$pausedEntry->setUpdatedAt($now);

		try {
			$this->calculateAndSetAutomaticBreak($pausedEntry);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'calculateAndSetAutomaticBreak failed while completing paused entry for project switch: ' . $e->getMessage(),
				[
					'exception' => $e,
					'user_id' => $userId,
					'entry_id' => $pausedEntry->getId(),
				]
			);
		}

		$updated = $this->timeEntryMapper->update($pausedEntry);
		$this->auditLogMapper->logAction(
			$userId,
			'clock_out',
			'time_entry',
			$updated->getId(),
			$oldSummary,
			$this->safeGetSummary($updated, $userId)
		);

		if ($this->projectCheckLaborSync !== null) {
			try {
				$this->projectCheckLaborSync->syncFromTimeEntry($updated, $userId);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'ProjectCheck billing sync after paused project-switch complete failed: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}
	}

	/**
	 * Resume a same-day paused entry instead of creating a new one.
	 *
	 * The gap between the moment the entry was paused (updated_at) and now is
	 * archived as a break interval so that working-time calculations stay correct.
	 * Project and description are updated when the caller supplies new values
	 * that do not change the ProjectCheck project id (project switches are handled
	 * by {@see completePausedEntryBeforeProjectSwitch()} before a fresh clock-in).
	 *
	 * @throws \Exception When the month is already closed or the daily maximum is reached.
	 */
	private function resumePausedEntry(
		string $userId,
		TimeEntry $pausedEntry,
		?string $projectCheckProjectId = null,
		?string $description = null
	): TimeEntry {
		$this->monthClosureGuard->assertTimeEntryMutable($pausedEntry);

		if ($projectCheckProjectId !== null) {
			$projectCheckProjectId = $this->normalizeAndAssertProjectCheckForClockIn($userId, $projectCheckProjectId);
		}

		// Respect the daily maximum – the paused entry's own hours already count.
		[$today, $tomorrow] = $this->getAppLocalTodayWindow();
		$todayHours   = $this->getTodayHours($userId);
		$maxDailyHours = $this->getMaxDailyHours();
		if ($todayHours >= $maxDailyHours) {
			throw new BusinessRuleException(
				$this->l10n->t(
					'Cannot clock in: Maximum daily working hours (%1$dh) already reached. You have already worked %2$.1f hours today (%3$s).',
					[(int)$maxDailyHours, $todayHours, $this->lawProfile($userId)->lawLabel('daily')]
				),
				BusinessRuleCode::DAILY_HOURS_LIMIT,
			);
		}

		$now      = $this->nowForAtEntries();
		$pausedAt = $pausedEntry->getUpdatedAt() ?? $now;

		// Archive the gap since the entry was paused as a break so it is excluded
		// from net working time. Only do so when the gap is positive.
		if ($pausedAt < $now) {
			$this->archiveBreakToJson($pausedEntry, $pausedAt, $now);
		}

		// Same project (or no project change requested): keep billing attribution.
		if ($projectCheckProjectId !== null) {
			$pausedEntry->setProjectCheckProjectId($projectCheckProjectId);
		}
		if ($description !== null) {
			$pausedEntry->setDescription($description);
		}

		$pausedEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$pausedEntry->setUpdatedAt($now);

		$resumed = $this->timeEntryMapper->update($pausedEntry);

		$this->auditLogMapper->logAction(
			$userId,
			'clock_in',
			'time_entry',
			$resumed->getId(),
			null,
			$this->safeGetSummary($resumed, $userId)
		);

		$this->clearAutoClockoutNotice($userId);

		return $resumed;
	}

	/**
	 * Start break for a user
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function startBreak(string $userId, ?\DateTimeInterface $effectiveAt = null): TimeEntry
	{
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$this->db->beginTransaction();
			try {
				$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
				if ($activeEntry === null) {
					throw new BusinessRuleException(
						$this->l10n->t('User is not currently clocked in'),
						BusinessRuleCode::NOT_CLOCKED_IN,
					);
				}
				$this->monthClosureGuard->assertTimeEntryMutable($activeEntry);
				if ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() === null) {
					throw new BusinessRuleException(
						$this->l10n->t('Break is already started'),
						BusinessRuleCode::BREAK_ALREADY_STARTED,
					);
				}

				$oldSummary = $this->safeGetSummary($activeEntry, $userId);
				$now = $this->resolveEffectiveAt($effectiveAt);
				if ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() !== null) {
					$this->archiveBreakToJson($activeEntry, $activeEntry->getBreakStartTime(), $activeEntry->getBreakEndTime());
					$activeEntry->setBreakStartTime($now);
					$activeEntry->setBreakEndTime(null);
				} else {
					$activeEntry->setBreakStartTime($now);
				}
				$activeEntry->setStatus(TimeEntry::STATUS_BREAK);
				$activeEntry->setUpdatedAt($now);
				$updatedEntry = $this->timeEntryMapper->update($activeEntry);
				$this->auditLogMapper->logAction(
					$userId,
					'start_break',
					'time_entry',
					$updatedEntry->getId(),
					$oldSummary,
					$this->safeGetSummary($updatedEntry, $userId)
				);
				$this->db->commit();
				return $updatedEntry;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * End break for a user
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function endBreak(string $userId, ?\DateTimeInterface $effectiveAt = null): TimeEntry
	{
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$this->db->beginTransaction();
			try {
				$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
				if ($breakEntry === null) {
					throw new BusinessRuleException(
						$this->l10n->t('User is not currently on break'),
						BusinessRuleCode::NOT_ON_BREAK,
					);
				}

				$this->monthClosureGuard->assertTimeEntryMutable($breakEntry);
				$oldSummary = $this->safeGetSummary($breakEntry, $userId);
				$now = $this->resolveEffectiveAt($effectiveAt);
				$breakEntry->setBreakEndTime($now);
				$breakEntry->setStatus(TimeEntry::STATUS_ACTIVE);
				$breakEntry->setUpdatedAt($now);
				$updatedEntry = $this->timeEntryMapper->update($breakEntry);
				$this->auditLogMapper->logAction(
					$userId,
					'end_break',
					'time_entry',
					$updatedEntry->getId(),
					$oldSummary,
					$this->safeGetSummary($updatedEntry, $userId)
				);
				$this->db->commit();
				return $updatedEntry;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Get current status for a user.
	 *
	 * The response includes a `server_now` ISO-8601 instant the frontend uses
	 * to drive the live session timer (see `ArbeitszeitCheckTime.syncFromServer`
	 * in `js/common/time.js`): instead of comparing local `Date.now()` against
	 * the entry's `startTime` (which produces an immediate ±offset error when
	 * the client clock or timezone differs from the server's), the frontend
	 * pins itself to `server_now` and uses a monotonic clock from there on.
	 * This is the canonical fix for the "I clocked in and the timer immediately
	 * shows +02:00:00" class of bugs.
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getStatus(string $userId): array
	{
		try {
			// Side-effect repairs must hold the same exclusive lock as clock mutations
			// (never write from an unlocked read path). Keep them outside any outer DB
			// transaction so enforceDailyMaximum can open its own short TX without nesting.
			$this->runStatusSideEffectsUnderLock($userId);

			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);

			$currentEntry = $activeEntry ?: $breakEntry;
			$nowImmutable = $this->timeZoneService->nowImmutableInStorage();

			// If no active/break entry, check for a paused entry from today before
			// declaring the user fully clocked out.
			if ($currentEntry === null) {
				[$today, $tomorrow] = $this->getAppLocalTodayWindow();
				$pausedEntry = $this->timeEntryMapper->findPausedOrUnfinishedTodayByUser($userId, $today, $tomorrow);
				if ($pausedEntry !== null && $pausedEntry->getStatus() === TimeEntry::STATUS_PAUSED) {
					$sessionStart = $pausedEntry->getStartTime();
					$pausedAt     = $pausedEntry->getUpdatedAt() ?? $this->nowForAtEntries();
					$sessionDuration = $sessionStart
						? max(0, $pausedAt->getTimestamp() - $sessionStart->getTimestamp() - (int)($pausedEntry->getBreakDurationHours() * 3600))
						: 0;
					try {
						$pausedSummary = $pausedEntry->getSummary();
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error(
							'Error getting summary for paused entry in getStatus: ' . $e->getMessage(),
							['exception' => $e]
						);
						$pausedSummary = ['id' => $pausedEntry->getId(), 'userId' => $userId, 'status' => TimeEntry::STATUS_PAUSED];
					}
					return $this->appendAutoClockoutNotice($this->withServerClock(
						$this->appendArbzgCalendarDayStatusFields([
							'status' => TimeEntry::STATUS_PAUSED,
							'current_entry' => $pausedSummary,
							'working_today_hours' => $this->getTodayHours($userId),
							'current_session_duration' => $sessionDuration,
						], $userId, $pausedEntry),
						$nowImmutable,
					), $userId);
				}

				return $this->appendAutoClockoutNotice($this->withServerClock(
					$this->appendArbzgCalendarDayStatusFields([
						'status' => 'clocked_out',
						'current_entry' => null,
						'working_today_hours' => $this->getTodayHours($userId),
						'current_session_duration' => null,
					], $userId, null),
					$nowImmutable,
				), $userId);
			}

			$sessionStart = $currentEntry->getStartTime();

			// Calculate session duration from start time to now using UTC epoch
			// seconds (`getTimestamp()`), so the value is independent of the
			// TZ either DateTime was constructed in. The two DateTime objects
			// can carry different zones — only the instants matter.
			$sessionDuration = $sessionStart ? ($nowImmutable->getTimestamp() - $sessionStart->getTimestamp()) : 0;

			// Subtract all break time from session duration
			// This includes regular breaks AND pause periods (clock-out to clock-in)
			// IMPORTANT: Use getBreakDurationHours() which correctly handles overlapping breaks
			// by merging them, so overlapping time periods are counted only once
			$totalBreakDurationHours = $currentEntry->getBreakDurationHours();
			$totalBreakDuration = $totalBreakDurationHours * 3600; // Convert hours to seconds

			$sessionDuration -= $totalBreakDuration;

			// Ensure duration is not negative
			$sessionDuration = max(0, $sessionDuration);

			// Safely get summary, handling any potential errors
			$entrySummary = null;
			try {
				$entrySummary = $currentEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for current entry ' . $currentEntry->getId() . ' in getStatus: ' . $e->getMessage(), ["exception" => $e]);
				// Return a minimal summary if getSummary fails
				$entrySummary = [
					'id' => $currentEntry->getId(),
					'userId' => $currentEntry->getUserId(),
					'status' => $currentEntry->getStatus(),
					'startTime' => $sessionStart ? $this->timeZoneService->toIso($sessionStart) : null
				];
			}

			return $this->appendAutoClockoutNotice($this->withServerClock(
				$this->appendArbzgCalendarDayStatusFields([
					'status' => $currentEntry->getStatus(),
					'current_entry' => $entrySummary,
					'working_today_hours' => $this->getTodayHours($userId),
					'current_session_duration' => $sessionDuration,
				], $userId, $currentEntry),
				$nowImmutable,
			), $userId);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in getStatus for user ' . $userId . ': ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			// Return a safe default status (still include the server clock so
			// the client can keep its drift estimate alive even on error).
			return $this->appendAutoClockoutNotice($this->withServerClock(
				$this->appendArbzgCalendarDayStatusFields([
					'status' => 'clocked_out',
					'current_entry' => null,
					'working_today_hours' => 0.0,
					'current_session_duration' => null,
				], $userId, null),
				$this->timeZoneService->nowImmutableInStorage(),
			), $userId);
		}
	}

	/**
	 * Repair stale paused rows and auto-complete at daily max under the user lock.
	 * Invoked from {@see getStatus()} before read-only status assembly.
	 */
	private function runStatusSideEffectsUnderLock(string $userId): void
	{
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$this->repairStalePausedAutomaticEntries($userId);
			$this->maybeAutoCompleteAtDailyMaximumAlreadyLocked($userId);
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * ArbZG §3 calendar-day fields for status API consumers.
	 *
	 * Always returned (including when clocked out) so the UI can disable clock-in
	 * when {@see isAtOrAboveDailyMaximum()} without requiring an active session.
	 *
	 * @param array<string,mixed> $status
	 * @return array<string,mixed>
	 */
	private function appendArbzgCalendarDayStatusFields(array $status, string $userId, ?TimeEntry $liveEntry): array
	{
		if (!array_key_exists('working_today_hours', $status)) {
			$status['working_today_hours'] = $this->getTodayHours($userId);
		}
		$status['at_daily_maximum'] = $this->isAtOrAboveDailyMaximum($userId);
		if ($liveEntry !== null) {
			[$todayStart, $todayEnd] = $this->getAppLocalTodayWindow();
			$status['session_hours_on_calendar_today'] = round(
				$this->dailyWorkingHoursCalculator->getEntryWorkingHoursOnCalendarDay(
					$liveEntry,
					$todayStart,
					$todayEnd,
					$this->nowForAtEntries(),
				),
				4,
			);
		} else {
			$status['session_hours_on_calendar_today'] = 0.0;
		}

		return $status;
	}

	/**
	 * Annotate a status array with the canonical "server clock" fields used by
	 * the frontend to drive the drift-safe session timer.
	 *
	 *  - `server_now`: ISO-8601 instant of the server's "now" (with offset),
	 *    so JS can pin its monotonic timer to the same moment the server used
	 *    to compute `current_session_duration`.
	 *  - `server_timezone`: the configured organisation timezone name (e.g.
	 *    `Europe/Berlin`). The frontend renders some labels using this; never
	 *    used for business rules client-side.
	 *
	 * @param array<string,mixed> $status
	 * @return array<string,mixed>
	 */
	private function withServerClock(array $status, \DateTimeImmutable $serverNow): array
	{
		$status['server_now'] = $this->timeZoneService->toIso($serverNow);
		$status['server_timezone'] = $this->timeZoneService->storageTimeZoneName();
		return $status;
	}

	/**
	 * Calculate non-overlapping working hours from a list of time entries
	 * This merges overlapping time periods and calculates the actual worked hours
	 *
	 * @param TimeEntry[]|array[] $entries Array of TimeEntry objects or arrays with 'start', 'end', 'breakHours'
	 * @return float Total working hours without double-counting overlaps
	 */
	private function calculateNonOverlappingHours(array $entries): float
	{
		// Normalize entries to arrays with start, end, breakHours
		$validEntries = [];
		foreach ($entries as $entry) {
			if (is_array($entry)) {
				// Already in array format
				if (isset($entry['start']) && isset($entry['end'])) {
					$validEntries[] = [
						'start' => $entry['start'],
						'end' => $entry['end'],
						'breakHours' => $entry['breakHours'] ?? 0.0
					];
				}
			} elseif ($entry instanceof TimeEntry && $entry->getStartTime() && $entry->getEndTime()) {
				// TimeEntry object - convert to array
				$validEntries[] = [
					'start' => $entry->getStartTime()->getTimestamp(),
					'end' => $entry->getEndTime()->getTimestamp(),
					'breakHours' => $entry->getBreakDurationHours() ?? 0.0
				];
			}
		}

		if (empty($validEntries)) {
			return 0.0;
		}

		// Sort by start time
		usort($validEntries, function($a, $b) {
			return $a['start'] <=> $b['start'];
		});

		// Merge overlapping periods
		$mergedPeriods = [];
		$currentPeriod = $validEntries[0];

		for ($i = 1; $i < count($validEntries); $i++) {
			$nextPeriod = $validEntries[$i];

			// If periods overlap or are adjacent, merge them
			if ($nextPeriod['start'] <= $currentPeriod['end']) {
				// Merge: extend end time if needed, add break hours
				$currentPeriod['end'] = max($currentPeriod['end'], $nextPeriod['end']);
				$currentPeriod['breakHours'] += $nextPeriod['breakHours'];
			} else {
				// No overlap: save current period and start a new one
				$mergedPeriods[] = $currentPeriod;
				$currentPeriod = $nextPeriod;
			}
		}
		$mergedPeriods[] = $currentPeriod;

		// Calculate total working hours from merged periods (subtract breaks)
		$totalHours = 0.0;
		foreach ($mergedPeriods as $period) {
			$durationHours = ($period['end'] - $period['start']) / 3600;
			$workingHours = max(0, $durationHours - $period['breakHours']);
			$totalHours += $workingHours;
		}

		return $totalHours;
	}

	/**
	 * Get hours worked today by a user
	 * Includes both completed entries and active/paused entries
	 * Correctly handles overlapping entries by merging them
	 *
	 * @param string $userId
	 * @return float
	 */
	public function getTodayHours(string $userId): float
	{
		try {
			$now = $this->nowForAtEntries();
			// Do not clamp to the daily maximum — callers (UI, compliance, clock-in
			// guards) need the real calendar-day total. Clamping hid over-max hours
			// and made error copy claim the user had worked exactly the limit.
			return $this->dailyWorkingHoursCalculator->getWorkingHoursForToday($userId, $now);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting today hours for user ' . $userId . ': ' . $e->getMessage(), ["exception" => $e]);
			return 0.0;
		}
	}

	/**
	 * Working hours summed per calendar day (storage TZ) across a half-open interval.
	 *
	 * @param \DateTime $rangeStart Inclusive lower bound
	 * @param \DateTime $rangeEndExclusive Exclusive upper bound
	 */
	public function getWorkingHoursForPeriod(
		string $userId,
		\DateTime $rangeStart,
		\DateTime $rangeEndExclusive,
	): float {
		return $this->dailyWorkingHoursCalculator->sumWorkingHoursForCalendarDaysInRange(
			$userId,
			$rangeStart,
			$rangeEndExclusive,
		);
	}

	/**
	 * Check compliance rules before clocking in
	 *
	 * @param string $userId
	 * @throws \Exception
	 */
	private function checkComplianceBeforeClockIn(string $userId, ?\DateTimeInterface $at = null): void
	{
		$issues = $this->complianceService->checkComplianceBeforeClockIn($userId, $at);

		if (!empty($issues)) {
			$criticalIssues = array_filter($issues, fn($issue) => $issue['severity'] === 'error');
			if (!empty($criticalIssues)) {
				$firstIssue = reset($criticalIssues);
				$reasonCode = match ($firstIssue['type'] ?? '') {
					\OCA\ArbeitszeitCheck\Db\ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD => BusinessRuleCode::REST_PERIOD_REQUIRED,
					\OCA\ArbeitszeitCheck\Db\ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED => BusinessRuleCode::DAILY_HOURS_LIMIT,
					default => null,
				};
				$details = [];
				if (isset($firstIssue['details']) && is_array($firstIssue['details'])) {
					$details = $firstIssue['details'];
				}
				throw new BusinessRuleException((string)$firstIssue['message'], $reasonCode, $details);
			}
		}
	}

	/**
	 * Check compliance rules after clocking out
	 *
	 * @param TimeEntry $timeEntry
	 */
	private function checkComplianceAfterClockOut(TimeEntry $timeEntry): void
	{
		$this->complianceService->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Calculate required break duration based on working hours, using the
	 * effective country profile for the user (E-9): DE ArbZG §4, AT AZG §11,
	 * CH ArG Art. 15.
	 *
	 * @param float $hoursWorked Total hours worked today (including current session)
	 * @param string|null $userId User whose labour-law country applies (null = instance)
	 * @return int Required break duration in minutes
	 */
	public function calculateRequiredBreakMinutes(float $hoursWorked, ?string $userId = null): int
	{
		return $this->lawProfile($userId)->requiredBreakMinutes($hoursWorked);
	}

	/**
	 * Whether ArbZG §4 automatic break insertion is enabled for this user.
	 * Default is on (matches personal settings / historical behaviour).
	 */
	public function isAutoBreakCalculationEnabled(string $userId): bool
	{
		return $this->userSettingsMapper->getStringSetting($userId, 'auto_break_calculation', '1') === '1';
	}

	/**
	 * Calculate and set automatic break if no break was entered and break is legally required
	 * 
	 * Automatically calculates the legally required break time (ArbZG §4) and adds it to the time entry
	 * if no break was manually entered. The break is placed in the middle of the working period.
	 * 
	 * @param TimeEntry $timeEntry The time entry to process
	 * @return bool True if automatic break was added, false otherwise
	 */
	public function calculateAndSetAutomaticBreak(TimeEntry $timeEntry): bool
	{
		$userId = $timeEntry->getUserId();
		if (!$this->isAutoBreakCalculationEnabled($userId)) {
			return false;
		}

		if (!$timeEntry->getStartTime() || !$timeEntry->getEndTime()) {
			return false;
		}

		// Check if break was already manually entered
		$hasManualBreak = false;
		
		// Check for breakStartTime/breakEndTime (single break)
		if ($timeEntry->getBreakStartTime() !== null && $timeEntry->getBreakEndTime() !== null) {
			$hasManualBreak = true;
		}
		
		// Check for breaks in JSON (multiple breaks)
		$breaksJson = $timeEntry->getBreaks();
		if ($breaksJson !== null && $breaksJson !== '') {
			$breaks = json_decode($breaksJson, true) ?? [];
			if (!empty($breaks)) {
				$hasManualBreak = true;
			}
		}

		// If break was already entered, don't add automatic break
		if ($hasManualBreak) {
			return false;
		}

		// Calculate total duration (including any breaks that might be in the future)
		$startTime = $timeEntry->getStartTime();
		$endTime = $timeEntry->getEndTime();
		$totalDurationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
		$totalDurationHours = $totalDurationSeconds / 3600;

		// IMPORTANT: For entries that span multiple work periods (e.g., paused and resumed),
		// we need to calculate the required break based on TOTAL WORKING TIME OF THE DAY,
		// not just the duration of this single entry.
		// This is because ArbZG §4 requires breaks based on total working hours per day.
		
		$userId = $timeEntry->getUserId();
		$totalWorkingHoursForDay = $this->dailyWorkingHoursCalculator->getPeakWorkingHoursForEntrySpan(
			$userId,
			$timeEntry,
			$endTime,
			$timeEntry->getId(),
		);

		// Peak calendar-day working hours → required break for this user's law profile
		$requiredBreakMinutes = $this->calculateRequiredBreakMinutes($totalWorkingHoursForDay, $userId);

		// If no break is required, nothing to do
		if ($requiredBreakMinutes <= 0) {
			return false;
		}

		// Calculate break duration in seconds
		$breakDurationSeconds = $requiredBreakMinutes * 60;

		// Place break in the middle of the working period
		$workDurationSeconds = $totalDurationSeconds;
		$breakStartOffset = (int)floor(($workDurationSeconds - $breakDurationSeconds) / 2);
		if ($breakStartOffset < 0) {
			$breakStartOffset = 0;
		}
		$breakStartTime = clone $startTime;
		$breakStartTime->modify('+' . round($breakStartOffset) . ' seconds');
		$breakEndTime = clone $breakStartTime;
		$breakEndTime->modify('+' . $breakDurationSeconds . ' seconds');

		// Store automatic break in breaks JSON array (for multiple breaks support)
		$breaks = [];
		$breaks[] = [
			'start' => $breakStartTime->format('c'),
			'end' => $breakEndTime->format('c'),
			'duration_minutes' => $requiredBreakMinutes,
			'automatic' => true, // Mark as automatically generated
			'reason' => $this->l10n->t('Automatically added: Legal break requirement (%s)', [$this->lawProfile($userId)->lawLabel('breaks')])
		];

		$timeEntry->setBreaks(json_encode($breaks));

		// Log the automatic break addition
		\OCP\Log\logger('arbeitszeitcheck')->info('Automatic break added to time entry', [
			'time_entry_id' => $timeEntry->getId(),
			'user_id' => $timeEntry->getUserId(),
			'total_duration_hours' => round($totalDurationHours, 2),
			'required_break_minutes' => $requiredBreakMinutes,
			'break_start' => $breakStartTime->format('c'),
			'break_end' => $breakEndTime->format('c')
		]);

		return true;
	}

	/**
	 * Automatically complete an active entry if maximum daily working hours are reached (ArbZG §3: max 10 hours per day)
	 * 
	 * Checks if the total daily working hours (including previous completed entries + current active entry)
	 * would exceed 10 hours. If so, automatically sets endTime and marks entry as COMPLETED.
	 * 
	 * @param TimeEntry $timeEntry The active entry to check and potentially complete
	 * @param \DateTimeInterface|null $referenceTime Evaluation instant (defaults to now in storage TZ).
	 *        Used by tests and background jobs so calendar-day clipping is deterministic.
	 * @return bool True if entry was automatically completed, false otherwise
	 */
	public function completeEntryIfDailyMaximumReached(TimeEntry $timeEntry, ?\DateTimeInterface $referenceTime = null): bool
	{
		// Only process active/break entries without endTime
		if ($timeEntry->getEndTime() !== null || !$timeEntry->getStartTime()) {
			return false;
		}
		
		// Only process active or break entries
		if ($timeEntry->getStatus() !== TimeEntry::STATUS_ACTIVE && $timeEntry->getStatus() !== TimeEntry::STATUS_BREAK) {
			return false;
		}

		$userId = $timeEntry->getUserId();
		$startTime = $timeEntry->getStartTime();
		$now = $referenceTime !== null
			? \DateTime::createFromInterface($referenceTime)
			: $this->nowForAtEntries();

		// ArbZG §3: evaluate the calendar day that contains "now", clipping overnight
		// sessions at midnight (never the clock-in date bucket alone).
		[$todayStart, $todayEnd] = $this->timeZoneService->dayWindowInStorage($now);
		$calculator = $this->dailyWorkingHoursCalculator;

		$totalDailyWorkingHours = $calculator->getWorkingHoursForCalendarDay(
			$userId,
			$todayStart,
			$todayEnd,
			$timeEntry,
			$now,
		);
		$totalWorkingHoursFromPreviousEntries = $calculator->getWorkingHoursForCalendarDay(
			$userId,
			$todayStart,
			$todayEnd,
			null,
			$now,
			$timeEntry->getId(),
		);

		$entryStartOnDayTs = max($startTime->getTimestamp(), $todayStart->getTimestamp());
		$entryWorkingHours = max(0, $totalDailyWorkingHours - $totalWorkingHoursFromPreviousEntries);
		$entryBreakHours = $timeEntry->getBreakDurationHours();
		$maxWorkingHours = $this->getMaxDailyHours();

		if ($totalDailyWorkingHours >= $maxWorkingHours) {
			$oldValues = $timeEntry->getSummary();
			$oldValues['_reason'] = $this->lawProfile($userId)->lawLabel('daily') . ': Auto-completing due to daily maximum (' . (int)$maxWorkingHours . 'h)';

			// Maximum working hours this session may still add on today's calendar day.
			$maxAllowedWorkingHoursForEntry = max(0, $maxWorkingHours - $totalWorkingHoursFromPreviousEntries);
			
			// End instant on today's calendar day (not from original clock-in if that was yesterday).
			$totalDailyWorkingHoursWithThisEntry = $totalWorkingHoursFromPreviousEntries + $maxAllowedWorkingHoursForEntry;
			$requiredBreakMinutes = $this->calculateRequiredBreakMinutes($totalDailyWorkingHoursWithThisEntry, $userId);

			if ($maxAllowedWorkingHoursForEntry <= 0) {
				$newEndTime = (new \DateTime())->setTimestamp($entryStartOnDayTs);
			} else {
				$newEndTime = (new \DateTime())->setTimestamp($entryStartOnDayTs);
				$newEndTime->modify('+' . round($maxAllowedWorkingHoursForEntry * 3600) . ' seconds');
			}

			// Archive any open break interval before completing, so working-time math stays correct.
			if ($timeEntry->getStatus() === TimeEntry::STATUS_BREAK
				&& $timeEntry->getBreakStartTime() !== null
				&& $timeEntry->getBreakEndTime() === null
			) {
				$breakEnd = ($timeEntry->getBreakStartTime() < $newEndTime) ? $newEndTime : $timeEntry->getBreakStartTime();
				$this->archiveBreakToJson($timeEntry, $timeEntry->getBreakStartTime(), $breakEnd);
				$timeEntry->setBreakStartTime(null);
				$timeEntry->setBreakEndTime(null);
			}

			$timeEntry->setEndTime($newEndTime);
			$this->calculateAndSetAutomaticBreak($timeEntry);

			// Extend wall-clock span so an automatic ArbZG §4 break inside the interval
			// is not counted as working time (getDurationHours subtracts breaks).
			$breakHoursAfterAuto = $timeEntry->getBreakDurationHours();
			if ($breakHoursAfterAuto > 0 && $timeEntry->getEndTime() !== null) {
				$extendedEnd = clone $timeEntry->getEndTime();
				$extendedEnd->modify('+' . round($breakHoursAfterAuto * 3600) . ' seconds');
				$timeEntry->setEndTime($extendedEnd);
			}

			// Mark entry as completed and capture reason for the audit trail (ArbZG §3).
			$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
			$timeEntry->setEndedReason(TimeEntry::ENDED_REASON_AUTO_DAILY_MAX);
			$timeEntry->setPolicyApplied('arbzg_daily_maximum');
			$timeEntry->setUpdatedAt($now);
			
			// Save the entry (with endTime, breaks, and status all set)
			$this->timeEntryMapper->update($timeEntry);
			
			// Get final break hours after automatic break calculation
			$finalBreakHoursAfterCalculation = $breakHoursAfterAuto;
			
			// Log the automatic completion
			\OCP\Log\logger('arbeitszeitcheck')->info('Time entry automatically completed - daily maximum working hours reached', [
				'time_entry_id' => $timeEntry->getId(),
				'user_id' => $userId,
				'previous_entries_hours' => round($totalWorkingHoursFromPreviousEntries, 2),
				'entry_working_hours' => round($entryWorkingHours, 2),
				'total_daily_hours' => round($totalDailyWorkingHours, 2),
				'max_allowed_entry_hours' => round($maxAllowedWorkingHoursForEntry, 2),
				'final_end_time' => $timeEntry->getEndTime()->format('c'),
				'final_break_hours' => round($finalBreakHoursAfterCalculation, 2),
				'required_break_minutes' => $requiredBreakMinutes
			]);

			$newValues = $timeEntry->getSummary();
			$newValues['_reason'] = $this->lawProfile($userId)->lawLabel('daily') . ': Auto-completed at daily maximum (' . (int)$maxWorkingHours . 'h)';
			$this->auditLogMapper->logAction(
				$userId,
				'time_entry_auto_completed_daily_max',
				'time_entry',
				$timeEntry->getId(),
				$oldValues,
				$newValues,
				'system'
			);
			
			return true;
		}
		
		return false;
	}

	/**
	 * Adjust end time to comply with maximum daily working hours (ArbZG §3: max 10 hours per day)
	 * 
	 * Automatically adjusts the end time of a time entry if the total daily working hours
	 * (including previous completed entries) would exceed 10 hours.
	 * 
	 * @param TimeEntry $timeEntry The time entry to process
	 * @return bool True if end time was adjusted, false otherwise
	 */
	public function adjustEndTimeForDailyMaximum(TimeEntry $timeEntry): bool
	{
		if (!$timeEntry->getStartTime() || !$timeEntry->getEndTime()) {
			return false;
		}

		$userId = $timeEntry->getUserId();
		$startTime = $timeEntry->getStartTime();
		$endTime = $timeEntry->getEndTime();
		$maxWorkingHours = $this->getMaxDailyHours();
		$calculator = $this->dailyWorkingHoursCalculator;

		// Walk each calendar day the entry touches; cap end time on the first violating day.
		$dayCursor = $this->timeZoneService->dayWindowInStorage($startTime)[0];
		$lastDay = $this->timeZoneService->dayWindowInStorage($endTime)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			$otherHours = $calculator->getWorkingHoursForCalendarDay(
				$userId,
				$dayStart,
				$dayEnd,
				null,
				$endTime,
				$timeEntry->getId(),
			);
			$entryHoursOnDay = $calculator->getEntryWorkingHoursOnCalendarDay($timeEntry, $dayStart, $dayEnd, $endTime);
			$totalOnDay = $otherHours + $entryHoursOnDay;

			if ($totalOnDay > $maxWorkingHours) {
				$maxAllowedForEntryOnDay = max(0, $maxWorkingHours - $otherHours);
				if ($maxAllowedForEntryOnDay <= 0) {
					return false;
				}

				$entryBreakHours = $timeEntry->getBreakDurationHours();
				$entryStartOnDayTs = max($startTime->getTimestamp(), $dayStart->getTimestamp());
				$adjustedEndTime = (new \DateTime())->setTimestamp($entryStartOnDayTs);
				$adjustedEndTime->modify('+' . round(($maxAllowedForEntryOnDay + $entryBreakHours) * 3600) . ' seconds');

				if ($adjustedEndTime > $endTime) {
					return false;
				}

				$timeEntry->setEndTime($adjustedEndTime);

				\OCP\Log\logger('arbeitszeitcheck')->info('Time entry end time adjusted to comply with daily maximum working hours', [
					'time_entry_id' => $timeEntry->getId(),
					'user_id' => $userId,
					'calendar_day' => $dayStart->format('Y-m-d'),
					'previous_entries_hours' => round($otherHours, 2),
					'original_entry_hours_on_day' => round($entryHoursOnDay, 2),
					'original_total_daily_hours' => round($totalOnDay, 2),
					'adjusted_entry_hours_on_day' => round($maxAllowedForEntryOnDay, 2),
					'original_end_time' => $endTime->format('c'),
					'adjusted_end_time' => $adjustedEndTime->format('c'),
					'break_duration_hours' => round($entryBreakHours, 2),
				]);

				return true;
			}

			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		return false;
	}

	/**
	 * Calculate taken break minutes for a user today
	 *
	 * @param string $userId
	 * @return float Break duration in minutes
	 */
	public function calculateTakenBreakMinutes(string $userId): float
	{
		try {
			[$today, $tomorrow] = $this->getAppLocalTodayWindow();

			return $this->dailyWorkingHoursCalculator->getBreakMinutesForCalendarDay(
				$userId,
				$today,
				$tomorrow,
				$this->nowForAtEntries(),
			);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error calculating taken break minutes for user ' . $userId . ': ' . $e->getMessage(), ["exception" => $e]);
			return 0.0;
		}
	}

	/**
	 * Get break status for user (current session)
	 * 
	 * @param string $userId
	 * @return array Break status with warnings and suggestions
	 */
	public function getBreakStatus(string $userId): array
	{
		try {
			// getTodayHours already includes active session duration if a session is running.
			$hoursWorked = $this->getTodayHours($userId);

			$requiredBreak = $this->calculateRequiredBreakMinutes($hoursWorked, $userId);
			$takenBreak = $this->calculateTakenBreakMinutes($userId);
			$remainingBreak = max(0, $requiredBreak - $takenBreak);
			
			$warningLevel = $this->getBreakWarningLevel($hoursWorked, $takenBreak, $requiredBreak, $userId);
			
			return [
				'hours_worked' => round($hoursWorked, 2),
				'required_break_minutes' => $requiredBreak,
				'taken_break_minutes' => round($takenBreak, 1),
				'remaining_break_minutes' => round($remainingBreak, 1),
				'break_required' => $remainingBreak > 0,
				'warning_level' => $warningLevel
			];
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting break status for user ' . $userId . ': ' . $e->getMessage(), ["exception" => $e]);
			return [
				'hours_worked' => 0.0,
				'required_break_minutes' => 0,
				'taken_break_minutes' => 0.0,
				'remaining_break_minutes' => 0.0,
				'break_required' => false,
				'warning_level' => 'none'
			];
		}
	}

	/**
	 * Get break warning level based on hours worked and break status.
	 *
	 * Thresholds come from the country profile so AT (single 6h→30 tier) does
	 * not inherit the German 9h/45 critical path.
	 *
	 * @return string Warning level: 'none', 'info', 'warning', 'critical'
	 */
	private function getBreakWarningLevel(
		float $hoursWorked,
		float $takenBreak,
		int $requiredBreak,
		?string $userId = null,
	): string
	{
		if ($requiredBreak === 0) {
			return 'none';
		}

		$remainingBreak = max(0, $requiredBreak - $takenBreak);
		if ($remainingBreak <= 0) {
			return 'none';
		}

		$tiers = $this->lawProfile($userId)->breakTiersAscending();
		if ($tiers === []) {
			return 'info';
		}

		$lowest = $tiers[0];
		$highest = $tiers[count($tiers) - 1];
		$minBreakFloor = max(1, $this->lawProfile($userId)->minBreakMinutes);
		$minChunk = max($minBreakFloor, (int)floor($highest['breakMinutes'] / 2));

		// Critical: past the highest exclusive tier and still missing a meaningful chunk.
		if ($hoursWorked > $highest['afterHours'] && $remainingBreak >= $minChunk) {
			return 'critical';
		}

		// Warning: past the lowest exclusive tier with a meaningful deficit, or within
		// 30 minutes of the next (higher) tier when that tier's break is already required.
		if ($hoursWorked > $lowest['afterHours'] && $remainingBreak >= $minBreakFloor) {
			return 'warning';
		}
		if (count($tiers) > 1
			&& $hoursWorked >= ($highest['afterHours'] - 0.5)
			&& $requiredBreak >= $highest['breakMinutes']) {
			return 'warning';
		}

		// Info: break required but not urgent
		if ($remainingBreak > 0) {
			return 'info';
		}
		
		return 'none';
	}

	private function safeGetSummary(TimeEntry $entry, string $userId): array
	{
		try {
			return $entry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error getting entry summary: ' . $e->getMessage(),
				['exception' => $e, 'entry_id' => $entry->getId(), 'user_id' => $userId]
			);
			return ['id' => $entry->getId(), 'userId' => $userId, 'status' => $entry->getStatus()];
		}
	}

	public function enforceBreakAutoFallbackForUser(string $userId, ?TimeEntry $currentEntry = null): ?TimeEntry
	{
		$entry = $currentEntry ?? $this->timeEntryMapper->findOnBreakByUser($userId);
		if ($entry === null || $entry->getStatus() !== TimeEntry::STATUS_BREAK) {
			return $currentEntry;
		}
		$breakStart = $entry->getBreakStartTime();
		if ($breakStart === null) {
			return $entry;
		}

		$enabled = $this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_enabled', '1') === '1';
		if (!$enabled) {
			return $entry;
		}

		$policy = $this->resolveBreakFallbackPolicyForUser($userId);
		$thresholdMinutes = (int)$policy['threshold_minutes'];
		if ((string)$policy['mode'] === 'flex') {
			$currentHour = (int)$this->nowForAtEntries()->format('G');
			$windowStart = (int)$policy['window_start_hour'];
			$windowEnd = (int)$policy['window_end_hour'];
			if ($windowStart >= 0 && $windowEnd <= 24 && $windowStart < $windowEnd && $currentHour >= $windowStart && $currentHour < $windowEnd) {
				return $entry;
			}
		}

		$now = $this->nowForAtEntries();
		$elapsedMinutes = (int)floor(($now->getTimestamp() - $breakStart->getTimestamp()) / 60);
		if ($elapsedMinutes < $thresholdMinutes) {
			return $entry;
		}

		try {
			$this->clockOut($userId, TimeEntry::ENDED_REASON_AUTO_BREAK_FALLBACK, (string)$policy['mode']);
			$noticeMessage = $this->l10n->t(
				'Break was still active after %1$d minutes. Automatic clock-out was applied at %2$s (%3$s policy).',
				[
					$thresholdMinutes,
					$this->timeZoneService->formatForDisplay($now, 'd.m.Y H:i', $userId),
					(string)$policy['mode'],
				]
			);
			$this->config->setUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice', json_encode([
				'message' => $noticeMessage,
				'reason' => TimeEntry::ENDED_REASON_AUTO_BREAK_FALLBACK,
				'policy' => (string)$policy['mode'],
				'at' => $now->format('c'),
			]));
			\OCP\Log\logger('arbeitszeitcheck')->info('Break auto-fallback clock-out executed', [
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
				'elapsed_minutes' => $elapsedMinutes,
				'threshold_minutes' => $thresholdMinutes,
				'policy' => (string)$policy['mode'],
			]);
			return null;
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Break auto-fallback clock-out failed: ' . $e->getMessage(), [
				'exception' => $e,
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
			]);
			return $entry;
		}
	}

	public function enforceDailyMaximumForUser(string $userId): ?TimeEntry
	{
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			return $this->enforceDailyMaximumForUserAlreadyLocked($userId);
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Same as {@see enforceDailyMaximumForUser()} but the caller must already hold
	 * {@see acquireUserMutationLock()} for `$userId` (avoids nested exclusive locks).
	 */
	private function enforceDailyMaximumForUserAlreadyLocked(string $userId): ?TimeEntry
	{
		$this->db->beginTransaction();
		try {
			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
			$currentEntry = $activeEntry ?: $breakEntry;
			if ($currentEntry === null) {
				$this->db->commit();
				return null;
			}

			$this->monthClosureGuard->assertTimeEntryMutable($currentEntry);
			$completed = $this->completeEntryIfDailyMaximumReached($currentEntry);
			if (!$completed) {
				$this->db->commit();
				return null;
			}

			$updated = $this->timeEntryMapper->find($currentEntry->getId());
			$now = $this->nowForAtEntries();
			$noticeMessage = $this->l10n->t(
				'Session was automatically completed at %1$s because the maximum daily working hours were reached (%2$s).',
				[
					$this->timeZoneService->formatForDisplay($now, 'd.m.Y H:i', $userId),
					$this->lawProfile($userId)->lawLabel('daily'),
				]
			);
			$this->config->setUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice', json_encode([
				'message' => $noticeMessage,
				'reason' => 'daily_maximum_reached',
				'at' => $now->format('c'),
			]));
			$this->db->commit();

			try {
				$this->checkComplianceAfterClockOut($updated);
			} catch (\Throwable $complianceError) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'Compliance check after auto daily-maximum clock-out failed: ' . $complianceError->getMessage(),
					['exception' => $complianceError, 'user_id' => $userId, 'entry_id' => $updated->getId()]
				);
			}

			return $updated;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Best-effort auto-completion when the daily maximum has already been crossed.
	 *
	 * Invoked while holding the user mutation lock from {@see runStatusSideEffectsUnderLock()}.
	 * All errors are swallowed and logged – the read-path must remain reliable even if
	 * enforcement fails (e.g. when the containing month has been finalised).
	 */
	private function maybeAutoCompleteAtDailyMaximumAlreadyLocked(string $userId): void
	{
		try {
			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
			if ($activeEntry === null && $breakEntry === null) {
				return;
			}

			if ($this->getTodayHours($userId) < $this->getMaxDailyHours()) {
				return;
			}

			$this->enforceDailyMaximumForUserAlreadyLocked($userId);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Self-healing daily-maximum enforcement skipped: ' . $e->getMessage(),
				['exception' => $e, 'user_id' => $userId]
			);
		}
	}

	/**
	 * Clear any stored auto-clockout banner for the user.
	 *
	 * Used when a fresh working session starts (clock-in / resume) so that a
	 * banner from a previous day cannot accidentally re-appear in the new
	 * session, which would otherwise be confusing for the user.
	 */
	private function clearAutoClockoutNotice(string $userId): void
	{
		try {
			$this->config->deleteUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Could not clear auto_clockout_notice: ' . $e->getMessage(),
				['exception' => $e, 'user_id' => $userId]
			);
		}
	}

	/**
	 * @return array{mode:string,threshold_minutes:int,window_start_hour:int,window_end_hour:int}
	 */
	private function resolveBreakFallbackPolicyForUser(string $userId): array
	{
		$defaultMode = 'flex';
		$assignment = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		if ($assignment !== null) {
			try {
				$model = $this->workingTimeModelMapper->find($assignment->getWorkingTimeModelId());
				$mode = $model->getType() === \OCA\ArbeitszeitCheck\Db\WorkingTimeModel::TYPE_SHIFT_WORK ? 'strict_shift' : 'flex';
				$rules = $model->getBreakRulesArray() ?? [];
				$threshold = isset($rules['auto_fallback_minutes']) ? (int)$rules['auto_fallback_minutes'] : null;
				if (isset($rules['break_policy']) && in_array((string)$rules['break_policy'], ['flex', 'strict_shift'], true)) {
					$mode = (string)$rules['break_policy'];
				}
				return [
					'mode' => $mode,
					'threshold_minutes' => max(15, min(720, $threshold ?? (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_minutes', $mode === 'strict_shift' ? '120' : '240'))),
					'window_start_hour' => max(0, min(23, (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_flex_window_start', '11'))),
					'window_end_hour' => max(1, min(24, (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_flex_window_end', '16'))),
				];
			} catch (\Throwable $e) {
				// fall through to defaults
			}
		}

		return [
			'mode' => $defaultMode,
			'threshold_minutes' => max(15, min(720, (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_minutes', '240'))),
			'window_start_hour' => max(0, min(23, (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_flex_window_start', '11'))),
			'window_end_hour' => max(1, min(24, (int)$this->config->getAppValue('arbeitszeitcheck', 'break_auto_fallback_flex_window_end', '16'))),
		];
	}

	private function archiveBreakToJson(TimeEntry $entry, \DateTime $start, \DateTime $end): void
	{
		$existing = $entry->getBreaks();
		$breaks = ($existing !== null && $existing !== '') ? (json_decode($existing, true) ?? []) : [];
		$breaks[] = [
			'start' => $start->format('c'),
			'end' => $end->format('c'),
			'duration_minutes' => (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60),
		];
		$entry->setBreaks(json_encode($breaks));
	}

	/**
	 * @param array<string,mixed> $status
	 * @return array<string,mixed>
	 */
	private function appendAutoClockoutNotice(array $status, string $userId): array
	{
		$raw = (string)$this->config->getUserValue($userId, 'arbeitszeitcheck', 'auto_clockout_notice', '');
		if ($raw === '') {
			return $status;
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			if (isset($decoded['at']) && is_string($decoded['at'])) {
				try {
					$noticeAt = new \DateTimeImmutable($decoded['at']);
					$ageSeconds = (new \DateTimeImmutable())->getTimestamp() - $noticeAt->getTimestamp();
					if ($ageSeconds > 86400) {
						return $status;
					}
				} catch (\Throwable $e) {
					// Keep legacy notices visible if timestamp cannot be parsed.
				}
			}
			$status['auto_clockout_notice'] = $decoded;
		}
		return $status;
	}

	private function acquireUserMutationLock(string $userId): string
	{
		$key = DbLockKeys::timeTrackingUser($userId);
		$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'Time tracking workflow lock for user ' . $userId);
		return $key;
	}

	private function releaseUserMutationLock(string $key): void
	{
		try {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to release time tracking workflow lock: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Run $fn while holding the per-user time-tracking exclusive lock.
	 * Used by manual entry create/update so overlap checks cannot race clock mutations.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function withUserMutationLock(string $userId, callable $fn)
	{
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			return $fn();
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Finalise a {@see TimeEntry::STATUS_PAUSED} entry in a single, race-safe step.
	 *
	 * This is the canonical, programmatic recovery path that {@see \OCA\ArbeitszeitCheck\Controller\TimeEntryController::complete}
	 * delegates to. Centralising the logic here means:
	 *   - the same code is exercised whether triggered by a UI click, an API
	 *     client, an `occ` command, or a future cron-based healer;
	 *   - concurrency is enforced via {@see acquireUserMutationLock()} just like
	 *     `clockIn()`/`clockOut()`, so two simultaneous completions for the same
	 *     user can never race;
	 *   - audit logging, ArbZG §4 (automatic break) and ArbZG §3 (daily maximum)
	 *     adjustments are guaranteed to run for every recovery, not just the UI
	 *     path.
	 *
	 * End-time selection (in order of preference):
	 *   1. an explicit `$explicitEndTime` from the caller (already validated /
	 *      normalised by the caller);
	 *   2. the entry's `updated_at` (the moment the broken clock-out froze it —
	 *      the closest truthful approximation of when work stopped);
	 *   3. the entry's `start_time` as a zero-duration fallback so the row at
	 *      least leaves the broken `paused` state and stops blocking the UI.
	 *
	 * If the chosen end is before the start (e.g. clock skew, daylight-saving
	 * boundary, or an explicit override of "08:00" for a 22:00 start), we first
	 * try the next calendar day, then snap to the start time as the ultimate
	 * safety net so {@see TimeEntry::validate()} cannot reject the row.
	 *
	 * @param string $userId               Owner of the entry; ownership is enforced.
	 * @param int $entryId                 ID of the paused entry to finalise.
	 * @param \DateTime|null $explicitEndTime  Optional caller-supplied end. The
	 *                                         caller is responsible for parsing
	 *                                         user input (HH:MM, ISO 8601, ...).
	 *
	 * @throws DoesNotExistException     when no entry with the given ID exists.
	 * @throws BusinessRuleException     when the entry is not owned by `$userId`,
	 *                                   not in `STATUS_PAUSED`, has no start time,
	 *                                   or fails validation after recovery.
	 * @throws MonthFinalizedException   when the entry sits in a finalised month
	 *                                   and must not be touched.
	 * @throws \OCP\Lock\LockedException when a concurrent mutation already holds
	 *                                   the per-user workflow lock.
	 */
	public function completePausedEntry(string $userId, int $entryId, ?\DateTime $explicitEndTime = null): TimeEntry
	{
		if ($entryId <= 0) {
			throw new BusinessRuleException($this->l10n->t('Invalid entry ID'));
		}

		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$this->db->beginTransaction();
			try {
				$entry = $this->timeEntryMapper->find($entryId);

				if ($entry->getUserId() !== $userId) {
					throw new BusinessRuleException($this->l10n->t('Access denied'));
				}

				$this->monthClosureGuard->assertTimeEntryMutable($entry);

				if ($entry->getStatus() !== TimeEntry::STATUS_PAUSED) {
					throw new BusinessRuleException($this->l10n->t(
						'This entry is not in a paused state and cannot be completed automatically. Use the edit form instead.'
					));
				}

				$startTime = $entry->getStartTime();
				if ($startTime === null) {
					throw new BusinessRuleException($this->l10n->t('Time entry has no start time'));
				}

				$oldSummary = $this->safeGetSummary($entry, $userId);

				$endTime = $explicitEndTime !== null ? clone $explicitEndTime : null;
				if ($endTime === null) {
					// Status/end_time mismatch (legacy bug): honour the frozen end_time
					// instead of overwriting it with updated_at.
					$existingEnd = $entry->getEndTime();
					if ($existingEnd !== null) {
						$endTime = clone $existingEnd;
					} else {
						$updatedAt = $entry->getUpdatedAt();
						$endTime = $updatedAt !== null ? clone $updatedAt : clone $startTime;
					}
				}

				if ($endTime < $startTime) {
					$candidate = clone $endTime;
					$candidate->modify('+1 day');
					$endTime = $candidate >= $startTime ? $candidate : clone $startTime;
				}

				$entry->setEndTime($endTime);
				$entry->setStatus(TimeEntry::STATUS_COMPLETED);
				if (!$entry->getEndedReason()) {
					$entry->setEndedReason(TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT);
				}
				$entry->setPolicyApplied($entry->getPolicyApplied() ?? 'paused_recovery');
				$entry->setUpdatedAt($this->nowForAtEntries());

				try {
					$this->calculateAndSetAutomaticBreak($entry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Automatic break during paused completion failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entryId]
					);
				}

				try {
					$this->adjustEndTimeForDailyMaximum($entry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Daily maximum adjustment during paused completion failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entryId]
					);
				}

				$entry->setCountableMinBreakMinutes(
					BreakCountable::minMinutes($this->lawProfileFactory->getProfile($userId)->minBreakMinutes)
				);
				$errors = $entry->validate();
				if (!empty($errors)) {
					throw new BusinessRuleException($this->l10n->t('Validation failed: %s', [implode('; ', array_map('strval', $errors))]));
				}

				$updatedEntry = $this->timeEntryMapper->update($entry);

				try {
					$this->auditLogMapper->logAction(
						$userId,
						'time_entry_paused_completed',
						'time_entry',
						$updatedEntry->getId(),
						$oldSummary,
						$this->safeGetSummary($updatedEntry, $userId)
					);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Audit log for paused completion failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entryId]
					);
				}

				$this->db->commit();

				// Compliance is a best-effort post-commit side effect: a defect
				// in the compliance evaluator must never roll back the recovery.
				try {
					$this->checkComplianceAfterClockOut($updatedEntry);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'Compliance check after paused completion failed: ' . $e->getMessage(),
						['exception' => $e, 'user_id' => $userId, 'entry_id' => $entryId]
					);
				}

				return $updatedEntry;
			} catch (\Throwable $e) {
				try {
					$this->db->rollBack();
				} catch (\Throwable $rollbackError) {
					// Nothing to rollback / connection already closed.
				}
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

}