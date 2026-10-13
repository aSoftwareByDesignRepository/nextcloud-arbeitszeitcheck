<?php

declare(strict_types=1);

/**
 * Absence entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;
use OCA\ArbeitszeitCheck\Service\HolidayService;

/**
 * Absence entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getType()
 * @method void setType(string $type)
 * @method \DateTime getStartDate()
 * @method void setStartDate(\DateTime $startDate)
 * @method \DateTime getEndDate()
 * @method void setEndDate(\DateTime $endDate)
 * @method float|null getDays()
 * @method void setDays(float|null $days)
 * @method float|null getDurationHours()
 * @method void setDurationHours(float|null $durationHours)
 * @method string|null getReason()
 * @method void setReason(string|null $reason)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getApproverComment()
 * @method void setApproverComment(string|null $approverComment)
 * @method int|null getApprovedBy()
 * @method void setApprovedBy(int|null $approvedBy)
 * @method string|null getApprovedByUserId()
 * @method void setApprovedByUserId(string|null $approvedByUserId)
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(\DateTime|null $approvedAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method string|null getSubstituteUserId()
 * @method void setSubstituteUserId(string|null $substituteUserId)
 */
class Absence extends Entity
{
	public const TYPE_VACATION = 'vacation';
	public const TYPE_SICK_LEAVE = 'sick_leave';
	public const TYPE_PERSONAL_LEAVE = 'personal_leave';
	public const TYPE_PARENTAL_LEAVE = 'parental_leave';
	public const TYPE_SPECIAL_LEAVE = 'special_leave';
	public const TYPE_UNPAID_LEAVE = 'unpaid_leave';
	public const TYPE_HOME_OFFICE = 'home_office';
	public const TYPE_BUSINESS_TRIP = 'business_trip';
	/** Carer's absence for a sick child — excused like sick leave. */
	public const TYPE_CHILD_SICK = 'child_sick';
	/** Freizeitausgleich / Gleittag — day off paid from the overtime balance (not creditable, not vacation). */
	public const TYPE_OVERTIME_COMP = 'overtime_comp';

	public const STATUS_PENDING = 'pending';
	public const STATUS_SUBSTITUTE_PENDING = 'substitute_pending';
	public const STATUS_SUBSTITUTE_DECLINED = 'substitute_declined';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_CANCELLED = 'cancelled';

	/** @var string */
	protected $userId;

	/** @var string */
	protected $type;

	/** @var \DateTime */
	protected $startDate;

	/** @var \DateTime */
	protected $endDate;

	/** @var float|null */
	protected $days;

	/** @var float|null */
	protected $durationHours;

	/** @var string|null */
	protected $reason;

	/** @var string */
	protected $status;

	/** @var string|null */
	protected $approverComment;

	/** @var int|null */
	protected $approvedBy;

	/** @var string|null */
	protected $approvedByUserId;

	/** @var \DateTime|null */
	protected $approvedAt;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	/** @var string|null */
	protected $substituteUserId;

	/**
	 * Absence constructor
	 */
	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('type', 'string');
		$this->addType('startDate', 'date');
		$this->addType('endDate', 'date');
		$this->addType('days', 'float');
		$this->addType('durationHours', 'float');
		$this->addType('reason', 'string');
		$this->addType('status', 'string');
		$this->addType('approverComment', 'string');
		$this->addType('approvedBy', 'integer');
		$this->addType('approvedByUserId', 'string');
		$this->addType('approvedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('substituteUserId', 'string');
	}

	/**
	 * Calculate the number of working days for this absence.
	 *
	 * NOTE:
	 * - For newly created/updated absences the precise value (inkl. Firmenfeiertage
	 *   und halber Feiertage) wird im Service gesetzt und als Feld `days`
	 *   gespeichert.
	 * - Diese Methode dient vor allem als Fallback, falls `days` (noch) nicht
	 *   gesetzt ist (Altbestände).
	 *
	 * @return float
	 */
	public function calculateWorkingDays(): float
	{
		if ($this->days !== null) {
			return (float)$this->days;
		}
		$userId = $this->userId ?? '';
		$start = $this->startDate;
		$end = $this->endDate;
		if ($userId === '' || $start === null || $end === null) {
			return 0.0;
		}
		try {
			$service = \OCP\Server::get(HolidayService::class);
			return $service->computeWorkingDaysForUser($userId, $start, $end);
		} catch (\Throwable) {
			return 0.0;
		}
	}

	/**
	 * Check if this absence overlaps with another absence
	 *
	 * @param Absence $other
	 * @return bool
	 */
	public function overlapsWith(Absence $other): bool
	{
		return $this->startDate <= $other->endDate && $this->endDate >= $other->startDate;
	}

	/**
	 * Check if absence is in the past
	 *
	 * @return bool
	 */
	public function isInPast(): bool
	{
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		return $this->endDate < $today;
	}

	/**
	 * Check if absence is currently active
	 *
	 * @return bool
	 */
	public function isActive(): bool
	{
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		return $this->startDate <= $today && $this->endDate >= $today && $this->status === self::STATUS_APPROVED;
	}

	/**
	 * Validate the absence data
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

		// Validate type (single enumeration point: AbsenceTypeCatalog)
		if (!\OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::isValid((string)$this->type)) {
			$errors['type'] = 'Invalid absence type';
		}

		// Validate dates
		if (!$this->startDate) {
			$errors['startDate'] = 'Start date is required';
		}

		if (!$this->endDate) {
			$errors['endDate'] = 'End date is required';
		}

		if ($this->startDate && $this->endDate && $this->startDate > $this->endDate) {
			$errors['endDate'] = 'End date must be after start date';
		}

		// Validate status
		$validStatuses = [
			self::STATUS_PENDING,
			self::STATUS_SUBSTITUTE_PENDING,
			self::STATUS_SUBSTITUTE_DECLINED,
			self::STATUS_APPROVED,
			self::STATUS_REJECTED,
			self::STATUS_CANCELLED
		];
		if (!in_array($this->status, $validStatuses)) {
			$errors['status'] = 'Invalid status';
		}

		// Validate reason length
		if ($this->reason && strlen($this->reason) > 1000) {
			$errors['reason'] = 'Reason cannot exceed 1000 characters';
		}

		return $errors;
	}

	/**
	 * Check if the absence data is valid
	 *
	 * @return bool
	 */
	public function isValid(): bool
	{
		return empty($this->validate());
	}

	/**
	 * Get a summary array for API responses.
	 *
	 * The `isPast` and `isCurrent` flags are computed against the *server*
	 * timezone (PHP default), so the frontend never has to re-derive "today"
	 * in JS where clients on iPadOS / Chrome have been observed to drift up
	 * to one calendar day due to DST and TZ offset differences. Consumers
	 * SHOULD prefer these flags over recomputing on the client side.
	 *
	 * @return array
	 */
	public function getSummary(): array
	{
		$startDate = $this->getStartDate();
		$endDate = $this->getEndDate();
		$createdAt = $this->getCreatedAt();
		$updatedAt = $this->getUpdatedAt();

		$today = new \DateTime('today');
		$isPast = $endDate !== null && $endDate < $today;
		$isCurrent = $startDate !== null && $endDate !== null
			&& $startDate <= $today && $endDate >= $today;
		$isFuture = $startDate !== null && $startDate > $today;

		/* `isManagerRecorded` is a structural hint for clients: an approval
		 * persisted by a human approver (not "system") combined with the
		 * presence of an approver indicates the absence was recorded by HR /
		 * a manager rather than auto-approved by the workflow. This is the
		 * same heuristic the audit-log entry `absence_manager_recorded`
		 * encodes server-side. */
		$approvedByUid = $this->getApprovedByUserId();
		$isManagerRecorded = $this->getStatus() === self::STATUS_APPROVED
			&& $approvedByUid !== null
			&& $approvedByUid !== ''
			&& $approvedByUid !== 'system'
			&& $approvedByUid !== $this->getUserId();

		// Resolved type presentation for API/mobile consumers. `typeLabel` is
		// server-localized (incl. custom types whose labels live in the DB, not
		// in client bundles); color/icon drive calendar chips for custom types.
		// Unknown legacy codes degrade to the raw code + neutral visuals.
		$type = (string)$this->getType();

		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'type' => $this->getType(),
			'typeLabel' => \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::resolvedLabel($type),
			'typeColor' => \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::color($type),
			'typeIcon' => \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog::icon($type),
			'startDate' => $startDate ? $startDate->format('Y-m-d') : null,
			'endDate' => $endDate ? $endDate->format('Y-m-d') : null,
			'days' => $this->getDays(),
			'durationHours' => $this->getDurationHours(),
			'workingDays' => $this->calculateWorkingDays(),
			'reason' => $this->getReason(),
			'status' => $this->getStatus(),
			'approverComment' => $this->getApproverComment(),
			'approvedBy' => $this->getApprovedBy(),
			'approvedByUserId' => $this->getApprovedByUserId(),
			'approvedAt' => $this->getApprovedAt()?->format('c'),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
			'substituteUserId' => $this->getSubstituteUserId(),
			'isPast' => $isPast,
			'isCurrent' => $isCurrent,
			'isFuture' => $isFuture,
			'isManagerRecorded' => $isManagerRecorded,
		];
	}
}