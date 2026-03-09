<?php

declare(strict_types=1);

/**
 * TimeTracking service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

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

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		ComplianceViolationMapper $violationMapper,
		AuditLogMapper $auditLogMapper,
		ProjectCheckIntegrationService $projectCheckService,
		ComplianceService $complianceService,
		IL10N $l10n
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->violationMapper = $violationMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->projectCheckService = $projectCheckService;
		$this->complianceService = $complianceService;
		$this->l10n = $l10n;
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
	public function clockIn(string $userId, ?string $projectCheckProjectId = null, ?string $description = null): TimeEntry
	{
		// Check if user is already clocked in
		$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
		if ($activeEntry !== null) {
			throw new \Exception($this->l10n->t('User is already clocked in'));
		}

		// Check if user is on break
		$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
		if ($breakEntry !== null) {
			throw new \Exception($this->l10n->t('User is currently on break. End break first.'));
		}

		// Check if there's a paused or unfinished entry for today that we can resume
		$pausedEntry = $this->timeEntryMapper->findPausedOrUnfinishedTodayByUser($userId);
		if ($pausedEntry !== null) {
			$now = new \DateTime();
			$pausedEntryStartTime = $pausedEntry->getStartTime();
			$pausedEntryUpdatedAt = $pausedEntry->getUpdatedAt();
			
			// Check if it's the same day as the paused entry
			$isSameDay = $pausedEntryStartTime && 
				$pausedEntryStartTime->format('Y-m-d') === $now->format('Y-m-d');
			
			// Calculate total working hours for today (only from COMPLETED entries)
			// This excludes the paused entry, which we'll add separately
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$tomorrow = clone $today;
			$tomorrow->modify('+1 day');
			$todayHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange($userId, $today, $tomorrow);
			
			// Calculate working hours from the paused entry (if it was worked on)
			$pausedEntryWorkingHours = 0.0;
			if ($pausedEntryStartTime && $pausedEntryUpdatedAt) {
				// Calculate duration from start to when it was paused (clock-out time)
				$pausedDuration = ($pausedEntryUpdatedAt->getTimestamp() - $pausedEntryStartTime->getTimestamp()) / 3600;
				
				// Subtract break time from paused entry
				$pausedBreakHours = $pausedEntry->getBreakDurationHours();
				$pausedEntryWorkingHours = max(0, $pausedDuration - $pausedBreakHours);
			}
			
			// Total working hours if we resume this entry (completed hours + paused entry hours)
			$maxDailyHours = 10.0; // ArbZG §3 maximum
			$totalWorkingHoursIfResumed = $todayHours + $pausedEntryWorkingHours;
			
			// Check if resuming would exceed maximum daily hours (ArbZG §3)
			// This check is always enforced, regardless of same day or different day
			if ($totalWorkingHoursIfResumed > $maxDailyHours) {
				throw new \Exception($this->l10n->t(
					'Cannot resume: Maximum daily working hours (10h) would be exceeded. Current: %.1f hours, would be: %.1f hours (ArbZG §3).',
					[
						$todayHours,
						$totalWorkingHoursIfResumed
					]
				));
			}
			
			// Check rest period (ArbZG §5): Only required between different days
			// On the same day, resuming is allowed without 11h rest period (it's a work interruption, not a new shift)
			if (!$isSameDay && $pausedEntryUpdatedAt) {
				$hoursSincePause = ($now->getTimestamp() - $pausedEntryUpdatedAt->getTimestamp()) / 3600;
				
				if ($hoursSincePause < 11) {
					// Calculate when user can clock in again
					$earliestClockIn = clone $pausedEntryUpdatedAt;
					$earliestClockIn->modify('+11 hours');
					$hoursRemaining = ($earliestClockIn->getTimestamp() - $now->getTimestamp()) / 3600;
					
					throw new \Exception($this->l10n->t(
						'Minimum 11-hour rest period required between shifts (ArbZG §5). Your last shift ended at %s. You can clock in after %s (in %.1f hours).',
						[
							$pausedEntryUpdatedAt->format('H:i'),
							$earliestClockIn->format('H:i'),
							max(0, $hoursRemaining)
						]
					));
				}
			}
			
			// Resume the paused entry
			// IMPORTANT: Track the pause period (time between clock-out and clock-in) in breaks JSON
			// This ensures that the pause time is correctly subtracted from total working time
			$pauseStartTime = $pausedEntryUpdatedAt; // When user clocked out (paused)
			$pauseEndTime = $now; // When user clocked in again (resumed)
			
			// Get existing breaks
			$breaksJson = $pausedEntry->getBreaks();
			$breaks = [];
			if ($breaksJson !== null && $breaksJson !== '') {
				$breaks = json_decode($breaksJson, true) ?? [];
			}
			
			// Add the pause period as a break (clock-out to clock-in)
			$breaks[] = [
				'start' => $pauseStartTime->format('c'),
				'end' => $pauseEndTime->format('c'),
				'duration_minutes' => round(($pauseEndTime->getTimestamp() - $pauseStartTime->getTimestamp()) / 60),
				'automatic' => true,
				'reason' => $this->l10n->t('Automatic pause: Clock-out period (resumed entry)')
			];
			
			$pausedEntry->setBreaks(json_encode($breaks));
			$pausedEntry->setStatus(TimeEntry::STATUS_ACTIVE);
			$pausedEntry->setUpdatedAt($now);
			
			// Update description if provided
			if ($description !== null) {
				$pausedEntry->setDescription($description);
			}
			
			// Update project if provided
			if ($projectCheckProjectId !== null) {
				$pausedEntry->setProjectCheckProjectId($projectCheckProjectId);
			}

			$savedEntry = $this->timeEntryMapper->update($pausedEntry);

			// Log the action
			try {
				$summary = $savedEntry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for clock_in (resume) audit log: ' . $e->getMessage(), ["exception" => $e]);
				$summary = ['id' => $savedEntry->getId(), 'userId' => $userId, 'status' => $savedEntry->getStatus()];
			}
			$this->auditLogMapper->logAction(
				$userId,
				'clock_in_resume',
				'time_entry',
				$savedEntry->getId(),
				null,
				$summary
			);

			return $savedEntry;
		}

		// Validate ProjectCheck project if provided
		if ($projectCheckProjectId && !$this->projectCheckService->projectExists($projectCheckProjectId)) {
			throw new \Exception($this->l10n->t('Selected project does not exist'));
		}

		// Check compliance rules before clocking in
		$this->checkComplianceBeforeClockIn($userId);
		
		// CRITICAL: Check if user has already worked 10 hours today (ArbZG §3)
		// Prevent clocking in if daily maximum is already reached
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		$tomorrow = clone $today;
		$tomorrow->modify('+1 day');
		$todayHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange($userId, $today, $tomorrow);
		
		$maxDailyHours = 10.0; // ArbZG §3 maximum
		if ($todayHours >= $maxDailyHours) {
			throw new \Exception($this->l10n->t(
				'Cannot clock in: Maximum daily working hours (10h) already reached. You have already worked %.1f hours today (ArbZG §3).',
				[$todayHours]
			));
		}

		$timeEntry = new TimeEntry();
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime(new \DateTime());
		$timeEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setProjectCheckProjectId($projectCheckProjectId);
		$timeEntry->setDescription($description);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		$savedEntry = $this->timeEntryMapper->insert($timeEntry);

		// Log the action
		try {
			$summary = $savedEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for clock_in audit log: ' . $e->getMessage(), ["exception" => $e]);
			$summary = ['id' => $savedEntry->getId(), 'userId' => $userId, 'status' => $savedEntry->getStatus()];
		}
		$this->auditLogMapper->logAction(
			$userId,
			'clock_in',
			'time_entry',
			$savedEntry->getId(),
			null,
			$summary
		);

		return $savedEntry;
	}

	/**
	 * Clock out a user (end working)
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function clockOut(string $userId): TimeEntry
	{
		// Check for active entry OR break entry (user can clock out during break)
		$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
		$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
		
		$currentEntry = $activeEntry ?: $breakEntry;
		if ($currentEntry === null) {
			throw new \Exception($this->l10n->t('User is not currently clocked in'));
		}

		$now = new \DateTime();
		
		// If user is on break, end the break first
		if ($currentEntry->getStatus() === TimeEntry::STATUS_BREAK) {
			$currentEntry->setBreakEndTime($now);
		}
		
		// Don't set endTime - allow user to resume work later
		// Only set status to paused so user can continue working later
		$currentEntry->setStatus(TimeEntry::STATUS_PAUSED);
		$currentEntry->setUpdatedAt($now);

		$updatedEntry = $this->timeEntryMapper->update($currentEntry);

		// Note: We don't check compliance after clocking out since the entry is not completed
		// Compliance will be checked when the entry is actually completed (endTime is set)

		// Log the action
		try {
			$oldSummary = $currentEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting old summary for clock_out audit log: ' . $e->getMessage(), ["exception" => $e]);
			$oldSummary = ['id' => $currentEntry->getId(), 'userId' => $userId];
		}
		try {
			$newSummary = $updatedEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting new summary for clock_out audit log: ' . $e->getMessage(), ["exception" => $e]);
			$newSummary = ['id' => $updatedEntry->getId(), 'userId' => $userId];
		}
		$this->auditLogMapper->logAction(
			$userId,
			'clock_out',
			'time_entry',
			$updatedEntry->getId(),
			$oldSummary,
			$newSummary
		);

		return $updatedEntry;
	}

	/**
	 * Start break for a user
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function startBreak(string $userId): TimeEntry
	{
		$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
		if ($activeEntry === null) {
			throw new \Exception($this->l10n->t('User is not currently clocked in'));
		}

		// Allow multiple breaks - check if there's an active break
		if ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() === null) {
			throw new \Exception($this->l10n->t('Break is already started'));
		}

		$now = new \DateTime();
		// Clear previous break times if they exist (for new break)
		if ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() !== null) {
			// Previous break was completed, start new one
			$activeEntry->setBreakStartTime($now);
			$activeEntry->setBreakEndTime(null);
		} else {
			// First break
			$activeEntry->setBreakStartTime($now);
		}
		$activeEntry->setStatus(TimeEntry::STATUS_BREAK);
		$activeEntry->setUpdatedAt($now);

		$updatedEntry = $this->timeEntryMapper->update($activeEntry);

		// Log the action
		try {
			$oldSummary = $activeEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting old summary for start_break audit log: ' . $e->getMessage(), ["exception" => $e]);
			$oldSummary = ['id' => $activeEntry->getId(), 'userId' => $userId];
		}
		try {
			$newSummary = $updatedEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting new summary for start_break audit log: ' . $e->getMessage(), ["exception" => $e]);
			$newSummary = ['id' => $updatedEntry->getId(), 'userId' => $userId];
		}
		$this->auditLogMapper->logAction(
			$userId,
			'start_break',
			'time_entry',
			$updatedEntry->getId(),
			$oldSummary,
			$newSummary
		);

		return $updatedEntry;
	}

	/**
	 * End break for a user
	 *
	 * @param string $userId
	 * @return TimeEntry
	 * @throws \Exception
	 */
	public function endBreak(string $userId): TimeEntry
	{
		$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
		if ($breakEntry === null) {
			throw new \Exception($this->l10n->t('User is not currently on break'));
		}

		$now = new \DateTime();
		$breakEntry->setBreakEndTime($now);
		$breakEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$breakEntry->setUpdatedAt($now);

		$updatedEntry = $this->timeEntryMapper->update($breakEntry);

		// Log the action
		try {
			$oldSummary = $breakEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting old summary for end_break audit log: ' . $e->getMessage(), ["exception" => $e]);
			$oldSummary = ['id' => $breakEntry->getId(), 'userId' => $userId];
		}
		try {
			$newSummary = $updatedEntry->getSummary();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting new summary for end_break audit log: ' . $e->getMessage(), ["exception" => $e]);
			$newSummary = ['id' => $updatedEntry->getId(), 'userId' => $userId];
		}
		$this->auditLogMapper->logAction(
			$userId,
			'end_break',
			'time_entry',
			$updatedEntry->getId(),
			$oldSummary,
			$newSummary
		);

		return $updatedEntry;
	}

	/**
	 * Get current status for a user
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getStatus(string $userId): array
	{
		try {
			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);

			$currentEntry = $activeEntry ?: $breakEntry;

			// If no active entry, check for paused entry
			if ($currentEntry === null) {
				$pausedEntry = $this->timeEntryMapper->findPausedOrUnfinishedTodayByUser($userId);
				if ($pausedEntry !== null && $pausedEntry->getStatus() === TimeEntry::STATUS_PAUSED) {
					return [
						'status' => 'paused',
						'current_entry' => $pausedEntry->getSummary(),
						'working_today_hours' => $this->getTodayHours($userId),
						'current_session_duration' => null
					];
				}
				return [
					'status' => 'clocked_out',
					'current_entry' => null,
					'working_today_hours' => $this->getTodayHours($userId),
					'current_session_duration' => null
				];
			}

			// CRITICAL: Automatically complete entry if daily maximum working hours reached (ArbZG §3)
			// This ensures that active entries are automatically closed when 10 hours are reached
			$this->completeEntryIfDailyMaximumReached($currentEntry);
			
			// Refresh entry from database in case it was just completed
			$currentEntry = $this->timeEntryMapper->find($currentEntry->getId());
			
			// If entry was just completed, return updated status
			if ($currentEntry->getStatus() === TimeEntry::STATUS_COMPLETED) {
				try {
					$summary = $currentEntry->getSummary();
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for completed entry: ' . $e->getMessage(), ["exception" => $e]);
					$summary = ['id' => $currentEntry->getId(), 'userId' => $userId, 'status' => TimeEntry::STATUS_COMPLETED];
				}
				return [
					'status' => 'completed',
					'current_entry' => $summary,
					'working_today_hours' => $this->getTodayHours($userId),
					'current_session_duration' => null
				];
			}
			
			$now = new \DateTime();
			$sessionStart = $currentEntry->getStartTime();
			
			// Calculate session duration from start time to now
			$sessionDuration = $sessionStart ? ($now->getTimestamp() - $sessionStart->getTimestamp()) : 0;
			
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
					'startTime' => $sessionStart ? $sessionStart->format('c') : null
				];
			}

			return [
				'status' => $currentEntry->getStatus(),
				'current_entry' => $entrySummary,
				'working_today_hours' => $this->getTodayHours($userId),
				'current_session_duration' => $sessionDuration
			];
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in getStatus for user ' . $userId . ': ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			// Return a safe default status
			return [
				'status' => 'clocked_out',
				'current_entry' => null,
				'working_today_hours' => 0.0,
				'current_session_duration' => null
			];
		}
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
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$tomorrow = clone $today;
			$tomorrow->modify('+1 day');

			// Get all entries for today (completed and active/paused)
			$allEntries = [];
			
			// Get completed entries
			$dayEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $today, $tomorrow);
			foreach ($dayEntries as $entry) {
				if (in_array($entry->getStatus(), [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_PENDING_APPROVAL]) 
					&& $entry->getEndTime() !== null) {
					$allEntries[] = $entry;
				}
			}
			
			// Get active/paused entry and add it with current end time
			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
			$pausedEntry = $this->timeEntryMapper->findPausedOrUnfinishedTodayByUser($userId);
			$currentEntry = $activeEntry ?: $breakEntry ?: $pausedEntry;
			
			if ($currentEntry && $currentEntry->getStartTime()) {
				$entryStart = $currentEntry->getStartTime();
				$entryStartForCheck = clone $entryStart;
				$entryStartForCheck->setTime(0, 0, 0);
				
				// Only count if entry started today
				if ($entryStartForCheck->format('Y-m-d') === $today->format('Y-m-d')) {
					// Determine end time for calculation
					$calcEndTime = null;
					if ($currentEntry->getEndTime()) {
						$calcEndTime = $currentEntry->getEndTime();
					} elseif ($currentEntry->getStatus() === TimeEntry::STATUS_PAUSED && $currentEntry->getUpdatedAt()) {
						$calcEndTime = $currentEntry->getUpdatedAt();
					} else {
						$calcEndTime = new \DateTime();
					}
					
					// Add entry data directly to calculation array (without cloning the entity)
					$allEntries[] = [
						'start' => $entryStart->getTimestamp(),
						'end' => $calcEndTime->getTimestamp(),
						'breakHours' => $currentEntry->getBreakDurationHours() ?? 0.0
					];
				}
			}

			// Calculate non-overlapping hours (automatically handles overlaps)
			$totalHours = $this->calculateNonOverlappingHours($allEntries);
			
			// Apply 10-hour maximum limit (ArbZG §3)
			return min($totalHours, 10.0);
			
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting today hours for user ' . $userId . ': ' . $e->getMessage(), ["exception" => $e]);
			return 0.0;
		}
	}

	/**
	 * Check compliance rules before clocking in
	 *
	 * @param string $userId
	 * @throws \Exception
	 */
	private function checkComplianceBeforeClockIn(string $userId): void
	{
		$issues = $this->complianceService->checkComplianceBeforeClockIn($userId);

		if (!empty($issues)) {
			$criticalIssues = array_filter($issues, fn($issue) => $issue['severity'] === 'error');
			if (!empty($criticalIssues)) {
				$firstIssue = reset($criticalIssues);
				throw new \Exception($firstIssue['message']);
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
	 * Calculate required break duration based on working hours (German labor law - ArbZG)
	 * 
	 * @param float $hoursWorked Total hours worked today (including current session)
	 * @return int Required break duration in minutes
	 */
	public function calculateRequiredBreakMinutes(float $hoursWorked): int
	{
		// German labor law (ArbZG):
		// - 6+ hours: 30 minutes break required
		// - 9+ hours: 45 minutes break required
		
		if ($hoursWorked >= 9) {
			return 45; // 45 minutes required after 9 hours
		} elseif ($hoursWorked >= 6) {
			return 30; // 30 minutes required after 6 hours
		}
		
		return 0; // No break required if less than 6 hours
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
		// Only process completed entries with start and end time
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
		
		// Get total working hours for the day (including this entry and any other completed entries)
		$userId = $timeEntry->getUserId();
		$entryDate = clone $startTime;
		$entryDate->setTime(0, 0, 0);
		$entryDateEnd = clone $entryDate;
		$entryDateEnd->modify('+1 day');
		
		// Get all completed entries for this day
		$dayEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $entryDate, $entryDateEnd);
		$totalWorkingHoursForDay = 0.0;
		foreach ($dayEntries as $dayEntry) {
			if ($dayEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $dayEntry->getEndTime() !== null) {
				// Exclude this entry (we'll add it separately)
				if ($dayEntry->getId() !== $timeEntry->getId()) {
					$totalWorkingHoursForDay += $dayEntry->getWorkingDurationHours() ?? 0.0;
				}
			}
		}
		
		// Add working hours from this entry (excluding breaks)
		// For entries that were paused and resumed, we need to calculate working time correctly
		// Working time = total duration - break time
		$entryBreakHours = $timeEntry->getBreakDurationHours();
		$entryWorkingHours = max(0, $totalDurationHours - $entryBreakHours);
		$totalWorkingHoursForDay += $entryWorkingHours;

		// Calculate required break based on TOTAL working hours of the day (ArbZG §4)
		$requiredBreakMinutes = $this->calculateRequiredBreakMinutes($totalWorkingHoursForDay);

		// If no break is required, nothing to do
		if ($requiredBreakMinutes <= 0) {
			return false;
		}

		// Calculate break duration in seconds
		$breakDurationSeconds = $requiredBreakMinutes * 60;

		// Place break in the middle of the working period
		$workDurationSeconds = $totalDurationSeconds;
		$breakStartOffset = ($workDurationSeconds - $breakDurationSeconds) / 2;
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
			'reason' => $this->l10n->t('Automatically added: Legal break requirement (ArbZG §4)')
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
	 * @return bool True if entry was automatically completed, false otherwise
	 */
	public function completeEntryIfDailyMaximumReached(TimeEntry $timeEntry): bool
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
		$now = new \DateTime();
		
		// For paused entries (that somehow got here), use updatedAt instead of now
		if ($timeEntry->getStatus() === TimeEntry::STATUS_PAUSED && $timeEntry->getUpdatedAt()) {
			$endTime = $timeEntry->getUpdatedAt();
		} else {
			$endTime = $now;
		}
		
		$entryDate = clone $startTime;
		$entryDate->setTime(0, 0, 0);
		$entryDateEnd = clone $entryDate;
		$entryDateEnd->modify('+1 day');
		
		// Get all completed entries for this day (excluding this entry)
		$dayEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $entryDate, $entryDateEnd);
		$totalWorkingHoursFromPreviousEntries = 0.0;
		foreach ($dayEntries as $dayEntry) {
			if ($dayEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $dayEntry->getEndTime() !== null) {
				// Exclude this entry (we'll calculate it separately)
				if ($dayEntry->getId() !== $timeEntry->getId()) {
					$totalWorkingHoursFromPreviousEntries += $dayEntry->getWorkingDurationHours() ?? 0.0;
				}
			}
		}
		
		// Calculate working hours from this entry (excluding breaks)
		$totalDurationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
		$totalDurationHours = $totalDurationSeconds / 3600;
		$entryBreakHours = $timeEntry->getBreakDurationHours();
		$entryWorkingHours = max(0, $totalDurationHours - $entryBreakHours);
		
		// Calculate total daily working hours
		$totalDailyWorkingHours = $totalWorkingHoursFromPreviousEntries + $entryWorkingHours;
		
		$maxWorkingHours = 10.0; // ArbZG §3 maximum
		
		// If total daily working hours exceeds maximum, complete the entry automatically
		if ($totalDailyWorkingHours >= $maxWorkingHours) {
			$oldValues = $timeEntry->getSummary();
			$oldValues['_reason'] = 'ArbZG §3: Auto-completing due to daily maximum (10h)';

			// Calculate maximum allowed working hours for this entry
			$maxAllowedWorkingHoursForEntry = max(0, $maxWorkingHours - $totalWorkingHoursFromPreviousEntries);
			
			// Calculate new end time
			$newEndTime = null;
			if ($maxAllowedWorkingHoursForEntry <= 0) {
				// If previous entries already exceed the maximum, set this entry to 0 working hours
				$newEndTime = clone $startTime;
			} else {
				// Calculate new total duration (working hours + break hours)
				$maxTotalHours = $maxAllowedWorkingHoursForEntry + $entryBreakHours;
				
				// Calculate new end time
				$newEndTime = clone $startTime;
				$newEndTime->modify('+' . round($maxTotalHours * 3600) . ' seconds');
			}
			
			// CRITICAL: First calculate required break based on TOTAL daily working hours (ArbZG §4)
			// This must happen BEFORE we set the endTime, so we can account for the break in the duration
			$totalDailyWorkingHoursWithThisEntry = $totalWorkingHoursFromPreviousEntries + $maxAllowedWorkingHoursForEntry;
			$requiredBreakMinutes = $this->calculateRequiredBreakMinutes($totalDailyWorkingHoursWithThisEntry);
			$requiredBreakHours = $requiredBreakMinutes / 60.0;
			
			// If break is required and not yet entered, we need to account for it
			$finalBreakHours = $entryBreakHours;
			if ($requiredBreakMinutes > 0 && $entryBreakHours < $requiredBreakHours) {
				// Break will be added - use the required break time for calculation
				$finalBreakHours = $requiredBreakHours;
			}
			
			// Recalculate end time with correct break duration
			if ($maxAllowedWorkingHoursForEntry <= 0) {
				$newEndTime = clone $startTime;
			} else {
				// Total duration = working hours + break hours
				$maxTotalHours = $maxAllowedWorkingHoursForEntry + $finalBreakHours;
				$newEndTime = clone $startTime;
				$newEndTime->modify('+' . round($maxTotalHours * 3600) . ' seconds');
			}
			
			// Set end time so calculateAndSetAutomaticBreak can work with it
			$timeEntry->setEndTime($newEndTime);
			
			// Calculate and set automatic break if needed (needs endTime to be set)
			$this->calculateAndSetAutomaticBreak($timeEntry);
			
			// Mark entry as completed
			$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
			$timeEntry->setUpdatedAt($now);
			
			// Save the entry (with endTime, breaks, and status all set)
			$this->timeEntryMapper->update($timeEntry);
			
			// Get final break hours after automatic break calculation
			$finalBreakHoursAfterCalculation = $timeEntry->getBreakDurationHours();
			
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
			$newValues['_reason'] = 'ArbZG §3: Auto-completed at daily maximum (10h)';
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
		// Only process entries with start and end time
		if (!$timeEntry->getStartTime() || !$timeEntry->getEndTime()) {
			return false;
		}

		// Get total working hours for the day (including previous completed entries)
		$userId = $timeEntry->getUserId();
		$startTime = $timeEntry->getStartTime();
		$endTime = $timeEntry->getEndTime();
		
		$entryDate = clone $startTime;
		$entryDate->setTime(0, 0, 0);
		$entryDateEnd = clone $entryDate;
		$entryDateEnd->modify('+1 day');
		
		// Get all completed entries for this day (excluding this entry)
		$dayEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $entryDate, $entryDateEnd);
		$totalWorkingHoursFromPreviousEntries = 0.0;
		foreach ($dayEntries as $dayEntry) {
			if ($dayEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $dayEntry->getEndTime() !== null) {
				// Exclude this entry (we'll calculate it separately)
				if ($dayEntry->getId() !== $timeEntry->getId()) {
					$totalWorkingHoursFromPreviousEntries += $dayEntry->getWorkingDurationHours() ?? 0.0;
				}
			}
		}
		
		// Calculate working hours from this entry (excluding breaks)
		$totalDurationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();
		$totalDurationHours = $totalDurationSeconds / 3600;
		$entryBreakHours = $timeEntry->getBreakDurationHours();
		$entryWorkingHours = max(0, $totalDurationHours - $entryBreakHours);
		
		// Calculate total daily working hours
		$totalDailyWorkingHours = $totalWorkingHoursFromPreviousEntries + $entryWorkingHours;
		
		$maxWorkingHours = 10.0; // ArbZG §3 maximum
		
		// If total daily working hours exceeds maximum, adjust end time
		if ($totalDailyWorkingHours > $maxWorkingHours) {
			// Calculate maximum allowed working hours for this entry
			$maxAllowedWorkingHoursForEntry = max(0, $maxWorkingHours - $totalWorkingHoursFromPreviousEntries);
			
			// If previous entries already exceed the maximum, don't allow this entry
			// For manual entries, we should reject them rather than set to 0 hours
			if ($maxAllowedWorkingHoursForEntry <= 0) {
				// Don't adjust - let the validation handle it or reject the entry
				// This prevents setting entries to 0 hours when max is already reached
				return false;
			}
			
			// Calculate new total duration (working hours + break hours)
			$maxTotalHours = $maxAllowedWorkingHoursForEntry + $entryBreakHours;
			
			// Calculate new end time
			$adjustedEndTime = clone $startTime;
			$adjustedEndTime->modify('+' . round($maxTotalHours * 3600) . ' seconds');
			
			// Set adjusted end time
			$timeEntry->setEndTime($adjustedEndTime);
			
			// Log the automatic adjustment
			\OCP\Log\logger('arbeitszeitcheck')->info('Time entry end time adjusted to comply with daily maximum working hours', [
				'time_entry_id' => $timeEntry->getId(),
				'user_id' => $userId,
				'previous_entries_hours' => round($totalWorkingHoursFromPreviousEntries, 2),
				'original_entry_hours' => round($entryWorkingHours, 2),
				'original_total_daily_hours' => round($totalDailyWorkingHours, 2),
				'adjusted_entry_hours' => round($maxAllowedWorkingHoursForEntry, 2),
				'adjusted_total_daily_hours' => $maxWorkingHours,
				'original_end_time' => $endTime->format('c'),
				'adjusted_end_time' => $adjustedEndTime->format('c'),
				'break_duration_hours' => round($entryBreakHours, 2)
			]);
			
			return true;
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
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$tomorrow = clone $today;
			$tomorrow->modify('+1 day');

			$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $today, $tomorrow);
			
			$totalBreakMinutes = 0.0;
			foreach ($entries as $entry) {
				$breakDuration = $entry->getBreakDurationHours();
				if ($breakDuration > 0) {
					$totalBreakMinutes += $breakDuration * 60; // Convert hours to minutes
				}
			}

			return round($totalBreakMinutes, 1);
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
			// Calculate hours worked today (including current active session)
			$hoursWorked = $this->getTodayHours($userId);
			
			// Add current session if active
			$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
			$breakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
			$currentEntry = $activeEntry ?: $breakEntry;
			
			if ($currentEntry) {
				$now = new \DateTime();
				$sessionStart = $currentEntry->getStartTime();
				if ($sessionStart) {
					$sessionDuration = ($now->getTimestamp() - $sessionStart->getTimestamp()) / 3600; // hours
					
					// Subtract break time if on break
					if ($currentEntry->getBreakStartTime() !== null && $currentEntry->getBreakEndTime() === null) {
						$breakHours = ($now->getTimestamp() - $currentEntry->getBreakStartTime()->getTimestamp()) / 3600;
						$sessionDuration -= $breakHours;
					} elseif ($currentEntry->getBreakStartTime() !== null && $currentEntry->getBreakEndTime() !== null) {
						$breakHours = ($currentEntry->getBreakEndTime()->getTimestamp() - $currentEntry->getBreakStartTime()->getTimestamp()) / 3600;
						$sessionDuration -= $breakHours;
					}
					
					$hoursWorked += $sessionDuration;
				}
			}
			
			$requiredBreak = $this->calculateRequiredBreakMinutes($hoursWorked);
			$takenBreak = $this->calculateTakenBreakMinutes($userId);
			$remainingBreak = max(0, $requiredBreak - $takenBreak);
			
			$warningLevel = $this->getBreakWarningLevel($hoursWorked, $takenBreak, $requiredBreak);
			
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
	 * Get break warning level based on hours worked and break status
	 *
	 * @param float $hoursWorked
	 * @param float $takenBreak
	 * @param int $requiredBreak
	 * @return string Warning level: 'none', 'info', 'warning', 'critical'
	 */
	private function getBreakWarningLevel(float $hoursWorked, float $takenBreak, int $requiredBreak): string
	{
		if ($requiredBreak === 0) {
			return 'none';
		}

		$remainingBreak = max(0, $requiredBreak - $takenBreak);
		
		// Critical: 9+ hours and still need 30+ minutes
		if ($hoursWorked >= 9 && $remainingBreak >= 30) {
			return 'critical';
		}
		
		// Warning: 6+ hours and still need 15+ minutes, or approaching 9 hours
		if (($hoursWorked >= 6 && $remainingBreak >= 15) || ($hoursWorked >= 8.5 && $requiredBreak >= 45)) {
			return 'warning';
		}
		
		// Info: Break required but not urgent
		if ($remainingBreak > 0) {
			return 'info';
		}
		
		return 'none';
	}

}