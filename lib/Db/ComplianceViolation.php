<?php

declare(strict_types=1);

/**
 * ComplianceViolation entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * ComplianceViolation entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getViolationType()
 * @method void setViolationType(string $violationType)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method \DateTime getDate()
 * @method void setDate(\DateTime $date)
 * @method int|null getTimeEntryId()
 * @method void setTimeEntryId(int|null $timeEntryId)
 * @method string getSeverity()
 * @method void setSeverity(string $severity)
 * @method bool getResolved()
 * @method void setResolved(bool $resolved)
 * @method \DateTime|null getResolvedAt()
 * @method void setResolvedAt(\DateTime|null $resolvedAt)
 * @method int|null getResolvedBy()
 * @method void setResolvedBy(int|null $resolvedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class ComplianceViolation extends Entity
{
	public const SEVERITY_INFO = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR = 'error';

	public const TYPE_INSUFFICIENT_REST_PERIOD = 'insufficient_rest_period';
	public const TYPE_DAILY_HOURS_LIMIT_EXCEEDED = 'daily_hours_limit_exceeded';
	public const TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED = 'weekly_hours_limit_exceeded';
	public const TYPE_MISSING_BREAK = 'missing_break';
	public const TYPE_EXCESSIVE_WORKING_HOURS = 'excessive_working_hours';
	public const TYPE_NIGHT_WORK = 'night_work';
	public const TYPE_SUNDAY_WORK = 'sunday_work';
	public const TYPE_HOLIDAY_WORK = 'holiday_work';

	/** @var string */
	protected $userId;

	/** @var string */
	protected $violationType;

	/** @var string */
	protected $description;

	/** @var \DateTime */
	protected $date;

	/** @var int|null */
	protected $timeEntryId;

	/** @var string */
	protected $severity;

	/** @var bool */
	protected $resolved = false;

	/** @var \DateTime|null */
	protected $resolvedAt;

	/** @var int|null */
	protected $resolvedBy;

	/** @var \DateTime */
	protected $createdAt;

	/**
	 * ComplianceViolation constructor
	 */
	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('violationType', 'string');
		$this->addType('description', 'string');
		$this->addType('date', 'date');
		$this->addType('timeEntryId', 'integer');
		$this->addType('severity', 'string');
		$this->addType('resolved', 'boolean');
		$this->addType('resolvedAt', 'datetime');
		$this->addType('resolvedBy', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Validate the compliance violation data
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

		// Validate violation type
		$validTypes = [
			self::TYPE_INSUFFICIENT_REST_PERIOD,
			self::TYPE_DAILY_HOURS_LIMIT_EXCEEDED,
			self::TYPE_WEEKLY_HOURS_LIMIT_EXCEEDED,
			self::TYPE_MISSING_BREAK,
			self::TYPE_EXCESSIVE_WORKING_HOURS,
			self::TYPE_NIGHT_WORK,
			self::TYPE_SUNDAY_WORK,
			self::TYPE_HOLIDAY_WORK
		];
		if (!in_array($this->violationType, $validTypes)) {
			$errors['violationType'] = 'Invalid violation type';
		}

		// Validate description
		if (empty($this->description)) {
			$errors['description'] = 'Description is required';
		}

		// Validate date
		if (!$this->date) {
			$errors['date'] = 'Date is required';
		}

		// Validate severity
		$validSeverities = [
			self::SEVERITY_INFO,
			self::SEVERITY_WARNING,
			self::SEVERITY_ERROR
		];
		if (!in_array($this->severity, $validSeverities)) {
			$errors['severity'] = 'Invalid severity level';
		}

		return $errors;
	}

	/**
	 * Check if the compliance violation data is valid
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
		$date = $this->getDate();
		$createdAt = $this->getCreatedAt();
		
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'violationType' => $this->getViolationType(),
			'description' => $this->getDescription(),
			'date' => $date ? $date->format('Y-m-d') : null,
			'timeEntryId' => $this->getTimeEntryId(),
			'severity' => $this->getSeverity(),
			'resolved' => $this->getResolved(),
			'resolvedAt' => $this->getResolvedAt()?->format('c'),
			'resolvedBy' => $this->getResolvedBy(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null
		];
	}

	/**
	 * Mark violation as resolved
	 *
	 * @param int $resolvedBy User ID who resolved it
	 */
	public function markAsResolved(int $resolvedBy): void
	{
		$this->setResolved(true);
		$this->setResolvedAt(new \DateTime());
		$this->setResolvedBy($resolvedBy);
	}

	/**
	 * Check if violation is resolved
	 *
	 * @return bool
	 */
	public function isResolved(): bool
	{
		return $this->resolved;
	}

	/**
	 * Get severity level as integer for sorting (higher = more severe)
	 *
	 * @return int
	 */
	public function getSeverityLevel(): int
	{
		return match ($this->severity) {
			self::SEVERITY_ERROR => 3,
			self::SEVERITY_WARNING => 2,
			self::SEVERITY_INFO => 1,
			default => 0
		};
	}
}