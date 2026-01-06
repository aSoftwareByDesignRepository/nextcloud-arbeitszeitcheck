<?php

declare(strict_types=1);

/**
 * TimeEntry entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

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
 * @method string getStatus()
 * @method void setStatus(string $status)
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
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(\DateTime|null $approvedAt)
 */
class TimeEntry extends Entity
{
	public const STATUS_ACTIVE = 'active';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_BREAK = 'break';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_PENDING_APPROVAL = 'pending_approval';
	public const STATUS_REJECTED = 'rejected';

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

	/** @var string */
	protected $status;

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

	/** @var \DateTime|null */
	protected $approvedAt;

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
		$this->addType('status', 'string');
		$this->addType('isManualEntry', 'boolean');
		$this->addType('justification', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('approvedBy', 'integer');
		$this->addType('approvedAt', 'datetime');
	}

	/**
	 * Get the duration in hours
	 *
	 * @return float|null
	 */
	public function getDurationHours(): ?float
	{
		if (!$this->endTime) {
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
	 * Get the break duration in hours (including all breaks)
	 * 
	 * IMPORTANT: According to ArbZG §4, only breaks of at least 15 minutes count
	 * toward the legal break requirement. Breaks shorter than 15 minutes are
	 * excluded from the calculation.
	 * 
	 * This method correctly handles overlapping breaks by merging them.
	 * Overlapping time periods are counted only once, not multiple times.
	 *
	 * @param bool $countOnlyValidBreaks If true, only breaks >= 15 minutes count (ArbZG §4). Default: true.
	 * @return float
	 */
	public function getBreakDurationHours(bool $countOnlyValidBreaks = true): float
	{
		$breakPeriods = [];
		
		// Minimum break duration in seconds (15 minutes = 900 seconds) - ArbZG §4
		$minBreakDurationSeconds = $countOnlyValidBreaks ? 900 : 0;
		
		// Collect all break periods from stored breaks (JSON)
		if ($this->breaks !== null && $this->breaks !== '') {
			$breaks = json_decode($this->breaks, true) ?? [];
			foreach ($breaks as $break) {
				if (isset($break['start']) && isset($break['end'])) {
					$start = new \DateTime($break['start']);
					$end = new \DateTime($break['end']);
					$durationSeconds = $end->getTimestamp() - $start->getTimestamp();
					
					// Only include breaks that meet the minimum duration requirement (ArbZG §4)
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
			
			// Only include breaks that meet the minimum duration requirement (ArbZG §4)
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
		usort($breakPeriods, function($a, $b) {
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
	 * @return array Array of validation errors
	 */
	public function validate(): array
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
		$validStatuses = [
			self::STATUS_ACTIVE,
			self::STATUS_COMPLETED,
			self::STATUS_BREAK,
			self::STATUS_PENDING_APPROVAL,
			self::STATUS_REJECTED
		];
		if (!in_array($this->status, $validStatuses)) {
			$errors['status'] = 'Invalid status';
		}

		// Validate end time is after start time
		if ($this->endTime && $this->startTime && $this->endTime <= $this->startTime) {
			$errors['endTime'] = 'End time must be after start time';
		}

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
			
			// Validate minimum break duration (ArbZG §4: breaks must be at least 15 minutes)
			$breakDurationSeconds = $this->breakEndTime->getTimestamp() - $this->breakStartTime->getTimestamp();
			$minBreakDurationSeconds = 900; // 15 minutes = 900 seconds
			if ($breakDurationSeconds < $minBreakDurationSeconds) {
				$errors['breakEndTime'] = 'Break must be at least 15 minutes long to count toward legal break requirement (ArbZG §4)';
			}
		}
		
		// Validate breaks in JSON array (multiple breaks)
		if ($this->breaks !== null && $this->breaks !== '') {
			$breaks = json_decode($this->breaks, true) ?? [];
			$minBreakDurationSeconds = 900; // 15 minutes = 900 seconds (ArbZG §4)
			
			foreach ($breaks as $index => $break) {
				if (isset($break['start']) && isset($break['end'])) {
					try {
						$breakStart = new \DateTime($break['start']);
						$breakEnd = new \DateTime($break['end']);
						$breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
						
						// Validate minimum break duration (ArbZG §4)
						if ($breakDurationSeconds < $minBreakDurationSeconds) {
							$errors['breaks'] = sprintf(
								'Break #%d must be at least 15 minutes long to count toward legal break requirement (ArbZG §4). Current duration: %d minutes',
								$index + 1,
								round($breakDurationSeconds / 60)
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

		// Validate maximum working hours (ArbZG §3: max 10 hours per day)
		// Check working duration (excluding breaks) - this is the actual work time
		// AUTOMATIC LIMIT: Automatically adjust end time to exactly 10 hours if exceeded
		if ($this->endTime && $this->startTime) {
			$workingHours = $this->getWorkingDurationHours();
			if ($workingHours !== null && $workingHours > 10) {
				// Automatically adjust end time to exactly 10 hours working time
				$maxWorkingHours = 10.0;
				$breakDurationHours = $this->getBreakDurationHours();
				$maxTotalHours = $maxWorkingHours + $breakDurationHours;
				
				// Calculate new end time
				$startTime = clone $this->startTime;
				$adjustedEndTime = clone $startTime;
				$adjustedEndTime->modify('+' . round($maxTotalHours * 3600) . ' seconds');
				
				// Set adjusted end time
				$this->setEndTime($adjustedEndTime);
				
				// Log the automatic adjustment
				\OCP\Log\logger('arbeitszeitcheck')->info('Time entry automatically limited to 10 hours working time', [
					'user_id' => $this->userId,
					'original_working_hours' => round($workingHours, 2),
					'adjusted_working_hours' => $maxWorkingHours,
					'original_end_time' => $this->endTime ? $this->endTime->format('c') : null,
					'adjusted_end_time' => $adjustedEndTime->format('c'),
					'break_duration_hours' => round($breakDurationHours, 2)
				]);
				
				// Note: We don't add an error here because we automatically fixed it
				// The adjusted time entry will now be valid
			}
		}

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
		
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'startTime' => $startTime ? $startTime->format('c') : null,
			'endTime' => $this->getEndTime()?->format('c'),
			'breakStartTime' => $this->getBreakStartTime()?->format('c'),
			'breakEndTime' => $this->getBreakEndTime()?->format('c'),
			'breaks' => $breaksData, // JSON array of all breaks (for multiple breaks support)
			'durationHours' => $this->getDurationHours(),
			'breakDurationHours' => $this->getBreakDurationHours(),
			'workingDurationHours' => $this->getWorkingDurationHours(),
			'description' => $this->getDescription(),
			'projectCheckProjectId' => $this->getProjectCheckProjectId(),
			'status' => $this->getStatus(),
			'isManualEntry' => $this->getIsManualEntry(),
			'justification' => $this->getJustification(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
			'approvedBy' => $this->getApprovedBy(),
			'approvedAt' => $this->getApprovedAt()?->format('c')
		];
	}
}