<?php

declare(strict_types=1);

/**
 * TimeEntry entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\BreakCountable;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\AppFramework\Db\Entity;

/**
 * TimeEntry entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getStartTime()
 * @method void setStartTime(\DateTime $startTime)
 * @method \DateTime|null getEndTime()
 * @method void setEndTime(\DateTime|null $endTime)
 * @method \DateTime|null getBreakStartTime()
 * @method void setBreakStartTime(\DateTime|null $breakStartTime)
 * @method \DateTime|null getBreakEndTime()
 * @method void setBreakEndTime(\DateTime|null $breakEndTime)
 * @method string|null getBreaks()
 * @method void setBreaks(string|null $breaks)
 * @method string|null getDescription()
 * @method void setDescription(string|null $description)
 * @method string|null getProjectCheckProjectId()
 * @method void setProjectCheckProjectId(string|null $projectCheckProjectId)
 * @method int|null getProjectCheckTimeEntryId()
 * @method void setProjectCheckTimeEntryId(int|null $projectCheckTimeEntryId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getEndedReason()
 * @method void setEndedReason(string|null $endedReason)
 * @method string|null getPolicyApplied()
 * @method void setPolicyApplied(string|null $policyApplied)
 * @method bool getIsManualEntry()
 * @method void setIsManualEntry(bool $isManualEntry)
 * @method string|null getJustification()
 * @method void setJustification(string|null $justification)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method int|null getApprovedBy()
 * @method void setApprovedBy(int|null $approvedBy)
 * @method string|null getApprovedByUserId()
 * @method void setApprovedByUserId(string|null $approvedByUserId)
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(\DateTime|null $approvedAt)
 * @method string|null getLiveUserId()
 * @method string|null getWorkLocation()
 * @method void setWorkLocation(string|null $workLocation)
 */
class TimeEntry extends Entity
{
	/** Max length for project_check_project_id (matches DB column) */
	public const PROJECT_CHECK_PROJECT_ID_MAX_LENGTH = 64;

	public const STATUS_ACTIVE = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_BREAK = 'break';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_REJECTED = 'rejected';
	public const ENDED_REASON_MANUAL_CLOCK_OUT = 'manual_clock_out';
	public const ENDED_REASON_AUTO_BREAK_FALLBACK = 'auto_break_fallback';
	public const ENDED_REASON_AUTO_DAILY_MAX = 'auto_daily_max';
	/** Automatic completion of legacy/orphan {@see STATUS_PAUSED} rows from a previous calendar day */
	public const ENDED_REASON_STALE_PAUSED_REPAIR = 'stale_paused_repair';

	/** @var string */
	protected $userId;

	/** @var \DateTime */
	protected $startTime;

	/** @var \DateTime|null */
	protected $endTime;

	/** @var \DateTime|null */
	protected $breakStartTime;

	/** @var \DateTime|null */
	protected $breakEndTime;

	/** @var string|null */
	protected $breaks;

	/** @var string|null */
	protected $description;

	/** @var string|null */
	protected $projectCheckProjectId;

	/** @var int|null Linked ProjectCheck pc_time_entries.id when billing sync is active */
	protected $projectCheckTimeEntryId;

	/** @var string */
	protected $status;

	/** @var string|null */
	protected $endedReason;

	/** @var string|null */
	protected $policyApplied;

	/** @var bool */
	protected $isManualEntry = false;

	/** @var string|null */
	protected $justification;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	/** @var int|null */
	protected $approvedBy;

	/** @var string|null Manager user ID (Nextcloud UID is string, not int) */
	protected $approvedByUserId;

	/** @var \DateTime|null */
	protected $approvedAt;

	/**
	 * Read-only dual-read of DB-generated `live_user_id` (MySQL STORED generated
	 * column / Postgres partial-unique helper). Set only by mapper hydration —
	 * never write this field; the database owns the value for open sessions.
	 *
	 * @var string|null
	 */
	protected $liveUserId;

	/**
	 * Where the work happened: one of {@see \OCA\ArbeitszeitCheck\Support\WorkLocation::ALL}.
	 * NULL = "not recorded" (legacy rows); new writes always resolve a value.
	 *
	 * @var string|null
	 */
	protected $workLocation;

	/**
	 * Not persisted. Profile floor for countable breaks (DE/CH 15, AT 10).
	 * When null, {@see resolveCountableMinBreakMinutes()} uses the profile
	 * for this entry's user when the container is available, else 15.
	 */
	private ?int $countableMinBreakMinutes = null;

	/**
	 * Not persisted. Human-readable warning set by write paths when the entry
	 * saved but the ProjectCheck billing sync failed — surfaced to the caller
	 * in the API response instead of being silently dropped.
	 */
	private ?string $billingSyncWarning = null;

	public function setBillingSyncWarning(?string $warning): void
	{
		$this->billingSyncWarning = ($warning === null || $warning === '') ? null : $warning;
	}

	public function getBillingSyncWarning(): ?string
	{
		return $this->billingSyncWarning;
	}

	/**
	 * TimeEntry constructor
	 */
	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('startTime', 'datetime');
		$this->addType('endTime', 'datetime');
		$this->addType('breakStartTime', 'datetime');
		$this->addType('breakEndTime', 'datetime');
		$this->addType('breaks', 'string');
		$this->addType('description', 'string');
		$this->addType('projectCheckProjectId', 'string');
		$this->addType('projectCheckTimeEntryId', 'integer');
		$this->addType('status', 'string');
		$this->addType('endedReason', 'string');
		$this->addType('policyApplied', 'string');
		$this->addType('isManualEntry', 'boolean');
		$this->addType('justification', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('approvedBy', 'integer');
		$this->addType('approvedByUserId', 'string');
		$this->addType('approvedAt', 'datetime');
		$this->addType('liveUserId', 'string');
		$this->addType('workLocation', 'string');
	}

	/**
	 * Hydration-only setter: `live_user_id` is owned by the database
	 * (generated column / partial unique index). Never mark it dirty for INSERT/UPDATE.
	 */
	public function setLiveUserId(?string $liveUserId): void
	{
		$this->liveUserId = $liveUserId;
	}

	public function getLiveUserId(): ?string
	{
		return $this->liveUserId;
	}

	/**
	 * Pin the countable break floor for this in-memory instance (AT: 10, DE/CH: 15).
	 */
	public function setCountableMinBreakMinutes(?int $minutes): void
	{
		$this->countableMinBreakMinutes = ($minutes === null || $minutes <= 0)
			? null
			: $minutes;
	}

	public function getCountableMinBreakMinutes(): ?int
	{
		return $this->countableMinBreakMinutes;
	}

	/**
	 * Effective minimum break length in minutes for validate/duration filters.
	 */
	public function resolveCountableMinBreakMinutes(): int
	{
		if ($this->countableMinBreakMinutes !== null && $this->countableMinBreakMinutes > 0) {
			return $this->countableMinBreakMinutes;
		}

		$userId = $this->userId ?? null;
		if (is_string($userId) && $userId !== '') {
			try {
				$factory = \OCP\Server::get(LaborLawProfileFactory::class);
				$minutes = $factory->getProfile($userId)->minBreakMinutes;
				if ($minutes > 0) {
					$this->countableMinBreakMinutes = $minutes;
					return $minutes;
				}
			} catch (\Throwable) {
				// Unit tests / early bootstrap — fall through to DE default.
			}
		}

		return BreakCountable::DEFAULT_MIN_MINUTES;
	}

	/**
	 * Get net working duration in hours (total duration minus breaks).
	 *
	 * @return float|null
	 */
	public function getDurationHours(): ?float
	{
		if (!$this->endTime || !$this->startTime) {
			return null;
		}

		$start = $this->startTime;
		$end = $this->endTime;

		// Calculate total break duration using getBreakDurationHours()
		// This method correctly handles overlapping breaks by merging them
		$totalBreakDuration = $this->getBreakDurationHours();

		$totalDuration = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
		return max(0, $totalDuration - $totalBreakDuration);
	}

	/**
	 * Get wall-clock duration in hours (without subtracting breaks).
	 *
	 * @return float|null
	 */
	public function getTotalDurationHours(): ?float
	{
		if (!$this->endTime || !$this->startTime) {
			return null;
		}

		return max(0, ($this->endTime->getTimestamp() - $this->startTime->getTimestamp()) / 3600);
	}

	/**
	 * Get the break duration in hours (including all breaks)
	 *
	 * When $countOnlyValidBreaks is true, only portions at least
	 * {@see resolveCountableMinBreakMinutes()} long count (DE/CH: 15, AT: 10).
	 * Overlapping periods are merged so overlap is not double-counted.
	 *
	 * @param bool $countOnlyValidBreaks If true, apply the profile countable floor. Default: true.
	 * @param int|null $minBreakMinutes Explicit floor; null uses the resolved profile floor.
	 * @return float
	 */
	public function getBreakDurationHours(bool $countOnlyValidBreaks = true, ?int $minBreakMinutes = null): float
	{
		$breakPeriods = [];

		$minBreakDurationSeconds = $countOnlyValidBreaks
			? BreakCountable::minSeconds($minBreakMinutes ?? $this->resolveCountableMinBreakMinutes())
			: 0;

		// Collect all break periods from stored breaks (JSON)
		if ($this->breaks !== null && $this->breaks !== '') {
			$breaks = json_decode($this->breaks, true) ?? [];
			foreach ($breaks as $break) {
				if (isset($break['start']) && isset($break['end'])) {
					try {
						$start = new \DateTime($break['start']);
						$end = new \DateTime($break['end']);
					} catch (\Exception $e) {
						continue; // Skip invalid break date strings
					}
					$durationSeconds = $end->getTimestamp() - $start->getTimestamp();

					if ($durationSeconds >= $minBreakDurationSeconds) {
						$breakPeriods[] = [
							'start' => $start->getTimestamp(),
							'end' => $end->getTimestamp()
						];
					}
				}
			}
		}

		// Add current active break if exists
		if ($this->breakStartTime !== null) {
			$endTime = $this->breakEndTime ?? new \DateTime();
			$durationSeconds = $endTime->getTimestamp() - $this->breakStartTime->getTimestamp();

			if ($durationSeconds >= $minBreakDurationSeconds) {
				$breakPeriods[] = [
					'start' => $this->breakStartTime->getTimestamp(),
					'end' => $endTime->getTimestamp()
				];
			}
		}

		// If no breaks, return 0
		if (empty($breakPeriods)) {
			return 0.0;
		}

		// Sort breaks by start time
		usort($breakPeriods, function ($a, $b) {
			return $a['start'] <=> $b['start'];
		});

		// Merge overlapping breaks
		$mergedPeriods = [];
		$currentPeriod = $breakPeriods[0];

		for ($i = 1; $i < count($breakPeriods); $i++) {
			$nextPeriod = $breakPeriods[$i];

			// If periods overlap or are adjacent, merge them
			if ($nextPeriod['start'] <= $currentPeriod['end']) {
				// Merge: extend current period to include next period
				$currentPeriod['end'] = max($currentPeriod['end'], $nextPeriod['end']);
			} else {
				// No overlap: save current period and start new one
				$mergedPeriods[] = $currentPeriod;
				$currentPeriod = $nextPeriod;
			}
		}
		$mergedPeriods[] = $currentPeriod;

		// Calculate total duration from merged periods
		$totalBreakHours = 0.0;
		foreach ($mergedPeriods as $period) {
			$totalBreakHours += ($period['end'] - $period['start']) / 3600;
		}

		return $totalBreakHours;
	}

	/**
	 * Get the working duration in hours (excluding breaks)
	 *
	 * @return float|null
	 */
	public function getWorkingDurationHours(): ?float
	{
		return $this->getDurationHours();
	}

	/**
	 * Check if this entry is currently active
	 *
	 * @return bool
	 */
	public function isActive(): bool
	{
		return $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * Check if this entry is on break
	 *
	 * @return bool
	 */
	public function isOnBreak(): bool
	{
		return $this->status === self::STATUS_BREAK;
	}

	/**
	 * Check if this entry is completed
	 *
	 * @return bool
	 */
	public function isCompleted(): bool
	{
		return $this->status === self::STATUS_COMPLETED;
	}

	/**
	 * Validate the time entry data
	 *
	 * @param int|null $minBreakMinutes Profile floor; null resolves via {@see resolveCountableMinBreakMinutes()}.
	 * @return array Array of validation errors
	 */
	public function validate(?int $minBreakMinutes = null): array
	{
		$errors = [];

		// Validate user ID
		if (empty($this->userId)) {
			$errors['userId'] = 'User ID is required';
		}

		// Validate start time
		if (!$this->startTime) {
			$errors['startTime'] = 'Start time is required';
		}

		// Validate status
		$currentStatus = $this->getStatus();
		if ($currentStatus === null || $currentStatus === '') {
			// Status is required
			$errors['status'] = 'Status is required';
		} else {
			$validStatuses = [
				self::STATUS_ACTIVE,
				self::STATUS_COMPLETED,
				self::STATUS_BREAK,
				self::STATUS_PAUSED,
				self::STATUS_PENDING_APPROVAL,
				self::STATUS_REJECTED
			];
			if (!in_array($currentStatus, $validStatuses, true)) {
				$errors['status'] = 'Invalid status';
			}
		}

		// Validate end time is after start time
		if ($this->endTime && $this->startTime) {
			$startDate = $this->startTime->format('Y-m-d');
			$endDate = $this->endTime->format('Y-m-d');
			$timeDiff = $this->endTime->getTimestamp() - $this->startTime->getTimestamp();
			
			if ($endDate === $startDate) {
				// Same day: end time must be after start time (or equal, which means 0 hours - allowed)
				if ($timeDiff < 0) {
					$errors['endTime'] = 'End time must be after start time';
				}
			} elseif ($endDate < $startDate) {
				// End date before start date - not allowed
				$errors['endTime'] = 'End date cannot be before start date';
			}
			// If end date is after start date (next day), that's allowed (night shift)
		}

		$minMinutes = BreakCountable::minMinutes($minBreakMinutes ?? $this->resolveCountableMinBreakMinutes());
		$minBreakDurationSeconds = BreakCountable::minSeconds($minMinutes);

		// Validate break times
		if ($this->breakStartTime && $this->breakEndTime) {
			if ($this->breakEndTime <= $this->breakStartTime) {
				$errors['breakEndTime'] = 'Break end time must be after break start time';
			}
			if ($this->startTime && $this->breakStartTime < $this->startTime) {
				$errors['breakStartTime'] = 'Break start time cannot be before work start time';
			}
			if ($this->endTime && $this->breakEndTime > $this->endTime) {
				$errors['breakEndTime'] = 'Break end time cannot be after work end time';
			}

			$breakDurationSeconds = $this->breakEndTime->getTimestamp() - $this->breakStartTime->getTimestamp();
			if ($breakDurationSeconds < $minBreakDurationSeconds) {
				$errors['breakEndTime'] = sprintf(
					'Break must be at least %d minutes long to count toward the legal break requirement',
					$minMinutes
				);
			}
		}

		// Validate breaks in JSON array (multiple breaks)
		if ($this->breaks !== null && $this->breaks !== '') {
			$breaks = json_decode($this->breaks, true) ?? [];

			foreach ($breaks as $index => $break) {
				if (isset($break['start']) && isset($break['end'])) {
					try {
						$breakStart = new \DateTime($break['start']);
						$breakEnd = new \DateTime($break['end']);
						$breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();

						if ($breakDurationSeconds < $minBreakDurationSeconds) {
							$errors['breaks'] = sprintf(
								'Break #%d must be at least %d minutes long to count toward the legal break requirement. Current duration: %d minutes',
								$index + 1,
								$minMinutes,
								(int)round($breakDurationSeconds / 60)
							);
						}
					} catch (\Exception $e) {
						$errors['breaks'] = 'Invalid break time format in breaks array';
					}
				}
			}
		}

		// Validate description length
		if ($this->description && strlen($this->description) > 1000) {
			$errors['description'] = 'Description cannot exceed 1000 characters';
		}

		// Validate justification for manual entries
		if ($this->isManualEntry && empty($this->justification)) {
			$errors['justification'] = 'Justification is required for manual time entries';
		}

		// Validate work location against the canonical allowlist (GH #43)
		if ($this->workLocation !== null && !\OCA\ArbeitszeitCheck\Support\WorkLocation::isValid($this->workLocation)) {
			$errors['workLocation'] = 'Invalid work location';
		}

		// NOTE: Maximum daily working hours validation (ArbZG §3: max 10 hours per day) is NOT done here
		// because this method only sees a single entry and cannot check the total daily working hours
		// across multiple entries. The daily maximum check MUST be done in TimeTrackingService::adjustEndTimeForDailyMaximum()
		// which has access to all entries of the day and is called BEFORE this validate() method.
		// This ensures compliance with ArbZG §3 which requires checking the TOTAL daily working time,
		// not just individual entries.

		return $errors;
	}

	/**
	 * Check if the time entry data is valid
	 *
	 * @return bool
	 */
	public function isValid(): bool
	{
		return empty($this->validate());
	}

	/**
	 * Whether the entry's owner may edit it.
	 *
	 * Centralises the business rule so controllers and templates stay in sync:
	 *  – not already approved
	 *  – start time within the edit window (default 14 days)
	 *  – status is one that allows editing: manual, pending_approval,
	 *    completed-automatic, or paused (orphaned/unfinished automatic entry)
	 *
	 * @param int $editWindowDays Number of past days within which editing is permitted.
	 */
	public function isLockedForEmployeeEdit(): bool
	{
		return $this->approvedByUserId !== null || $this->approvedAt !== null;
	}

	public function canEdit(int $editWindowDays = 14): bool
	{
		if ($this->isLockedForEmployeeEdit()) {
			return false;
		}

		if ($this->status === self::STATUS_PENDING_APPROVAL) {
			return false;
		}

		$entryDate = $this->startTime;
		if (!$entryDate instanceof \DateTime) {
			return false;
		}

		$cutoff = new \DateTime();
		$cutoff->modify('-' . $editWindowDays . ' days');
		$cutoff->setTime(0, 0, 0);
		if ($entryDate < $cutoff) {
			return false;
		}

		return $this->isManualEntry
			|| ($this->status === self::STATUS_COMPLETED && !$this->isManualEntry)
			|| $this->status === self::STATUS_PAUSED;
	}

	/**
	 * Whether the owner may request a supervisor-approved correction.
	 */
	public function canRequestCorrection(int $editWindowDays = 14): bool
	{
		if ($this->status === self::STATUS_PENDING_APPROVAL) {
			return false;
		}

		if (!in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_PAUSED], true)) {
			return false;
		}

		if (!$this->startTime instanceof \DateTime) {
			return false;
		}

		return true;
	}

	/**
	 * Occupancy end used for overlap detection and live-session guards.
	 *
	 * Completed/pending rows with a real end_time occupy until that instant.
	 * Live active/break rows occupy until $now. Paused rows occupy until
	 * updated_at (or $now). Pending corrections of an *open* session
	 * (end_time null) occupy the same way as paused — otherwise a second
	 * clock-in can start while the original session is still under review.
	 */
	public function occupancyEnd(\DateTime $now): ?\DateTime
	{
		$end = $this->getEndTime();
		if ($end !== null) {
			return $end;
		}

		$status = $this->getStatus();
		if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_BREAK], true)) {
			return clone $now;
		}
		if ($status === self::STATUS_PAUSED || $status === self::STATUS_PENDING_APPROVAL) {
			return $this->getUpdatedAt() ?? clone $now;
		}

		return null;
	}

	/**
	 * Whether the entry's owner may delete it (entity-level rules only).
	 *
	 * Manual entries and paused (orphaned) sessions are deletable. Completed
	 * clocked entries follow the same edit window as {@see canEdit()}. Month
	 * closure, pending-correction workflow, and manager approval are enforced
	 * in {@see \OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy}.
	 */
	public function canDelete(int $editWindowDays = 14): bool
	{
		if ($this->isLockedForEmployeeEdit()) {
			return false;
		}

		if (in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_BREAK], true)) {
			return false;
		}

		if ($this->status === self::STATUS_PENDING_APPROVAL) {
			return $this->isManualEntry;
		}

		if ($this->status === self::STATUS_PAUSED) {
			return true;
		}

		if ($this->isManualEntry) {
			return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_REJECTED], true);
		}

		if ($this->status === self::STATUS_COMPLETED) {
			return $this->canEdit($editWindowDays);
		}

		return false;
	}

	/**
	 * Get a summary array for API responses
	 *
	 * @return array
	 */
	public function getSummary(): array
	{
		$startTime = $this->getStartTime();
		$createdAt = $this->getCreatedAt();
		$updatedAt = $this->getUpdatedAt();
		
		// Parse breaks JSON for summary (important for audit trail and compliance)
		$breaksData = null;
		if ($this->breaks !== null && $this->breaks !== '') {
			$breaksData = json_decode($this->breaks, true);
		}
		
		$pendingProposal = $this->resolvePendingProposal();
		$hasPendingProposal = $pendingProposal !== null;

		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'startTime' => $startTime ? $startTime->format('c') : null,
			'endTime' => $this->getEndTime()?->format('c'),
			'breakStartTime' => $this->getBreakStartTime()?->format('c'),
			'breakEndTime' => $this->getBreakEndTime()?->format('c'),
			'breaks' => $breaksData,
			'durationHours' => $this->getDurationHours(),
			'breakDurationHours' => $this->getBreakDurationHours(),
			'workingDurationHours' => $this->getWorkingDurationHours(),
			/** Effective countable break floor for this entry's user (DE/CH: 15, AT: 10). */
			'minBreakMinutes' => $this->resolveCountableMinBreakMinutes(),
			'description' => $this->getDescription(),
			'projectCheckProjectId' => $this->getProjectCheckProjectId(),
			'projectCheckTimeEntryId' => $this->getProjectCheckTimeEntryId(),
			'status' => $this->getStatus(),
			'endedReason' => $this->getEndedReason(),
			'policyApplied' => $this->getPolicyApplied(),
			'isManualEntry' => $this->getIsManualEntry(),
			'justification' => $this->getJustification(),
			'workLocation' => $this->getWorkLocation(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
			'approvedBy' => $this->getApprovedBy(),
			'approvedByUserId' => $this->getApprovedByUserId(),
			'approvedAt' => $this->getApprovedAt()?->format('c'),
			'hasPendingProposal' => $hasPendingProposal,
			'pendingProposal' => $pendingProposal,
			'displayStartTime' => $hasPendingProposal && isset($pendingProposal['startTime'])
				? $pendingProposal['startTime']
				: ($startTime ? $startTime->format('c') : null),
			'displayEndTime' => $hasPendingProposal && isset($pendingProposal['endTime'])
				? $pendingProposal['endTime']
				: ($this->getEndTime()?->format('c')),
			'displayBreaks' => $hasPendingProposal && isset($pendingProposal['breaks'])
				? $pendingProposal['breaks']
				: $breaksData,
			'canEdit' => $this->canEdit(),
			'canDelete' => $this->canDelete(),
			'canRequestCorrection' => $this->canRequestCorrection(),
			'isLockedForEmployeeEdit' => $this->isLockedForEmployeeEdit(),
		];
	}

	/**
	 * @return array<string, mixed>|null Proposed values when status is pending_approval.
	 */
	private function resolvePendingProposal(): ?array
	{
		if ($this->status !== self::STATUS_PENDING_APPROVAL) {
			return null;
		}
		$data = json_decode($this->justification ?? '{}', true);
		if (!is_array($data)) {
			return null;
		}
		$proposed = $data['proposed'] ?? null;
		return is_array($proposed) && $proposed !== [] ? $proposed : null;
	}
}