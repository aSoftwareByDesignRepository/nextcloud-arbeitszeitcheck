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
 * @method string|null getReason()
 * @method void setReason(string|null $reason)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getApproverComment()
 * @method void setApproverComment(string|null $approverComment)
 * @method int|null getApprovedBy()
 * @method void setApprovedBy(int|null $approvedBy)
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

	/** @var string|null */
	protected $reason;

	/** @var string */
	protected $status;

	/** @var string|null */
	protected $approverComment;

	/** @var int|null */
	protected $approvedBy;

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
		$this->addType('reason', 'string');
		$this->addType('status', 'string');
		$this->addType('approverComment', 'string');
		$this->addType('approvedBy', 'integer');
		$this->addType('approvedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('substituteUserId', 'string');
	}

	/**
	 * Calculate the number of working days for this absence
	 * Excludes weekends and German public holidays
	 *
	 * @return float
	 */
	public function calculateWorkingDays(): float
	{
		$start = clone $this->startDate;
		$end = clone $this->endDate;
		$workingDays = 0;

		$startYear = (int)$start->format('Y');
		$endYear = (int)$end->format('Y');
		$germanHolidays = $this->getGermanPublicHolidays($startYear);
		if ($endYear !== $startYear) {
			$germanHolidays = array_merge($germanHolidays, $this->getGermanPublicHolidays($endYear));
		}

		while ($start <= $end) {
			// Skip weekends (1=Mon .. 5=Fri, 6=Sat, 7=Sun)
			if ($start->format('N') < 6) {
				$dateString = $start->format('Y-m-d');
				if (!isset($germanHolidays[$dateString])) {
					$workingDays++;
				}
			}
			$start->modify('+1 day');
		}

		return (float)$workingDays;
	}

	/**
	 * Get German public holidays for a given year.
	 * Uses PHP easter_days() for movable feasts (Easter-based).
	 *
	 * @param int $year
	 * @return array<string, string> date string (Y-m-d) => name
	 */
	private function getGermanPublicHolidays(int $year): array
	{
		$holidays = [];

		// New Year's Day
		$holidays[$year . '-01-01'] = 'New Year\'s Day';

		// Easter-based: PHP easter_days($year) = days after March 21 (use \ for global)
		$easterDays = function_exists('easter_days') ? \easter_days($year) : $this->easterDaysGauss($year);
		$march21 = new \DateTime($year . '-03-21');
		$easter = clone $march21;
		$easter->modify('+' . $easterDays . ' days');

		$easter->modify('-2 days');
		$holidays[$easter->format('Y-m-d')] = 'Good Friday';
		$easter->modify('+3 days'); // Easter Monday
		$holidays[$easter->format('Y-m-d')] = 'Easter Monday';
		$easter->modify('+38 days'); // Ascension (39 days after Easter Sunday)
		$holidays[$easter->format('Y-m-d')] = 'Ascension Day';
		$easter->modify('+11 days'); // Whit Monday
		$holidays[$easter->format('Y-m-d')] = 'Whit Monday';
		$easter->modify('+10 days'); // Corpus Christi (Thursday after Trinity)
		$holidays[$easter->format('Y-m-d')] = 'Corpus Christi';

		// Labour Day
		$holidays[$year . '-05-01'] = 'Labour Day';

		// German Unity Day
		$holidays[$year . '-10-03'] = 'German Unity Day';

		// Reformation Day
		$holidays[$year . '-10-31'] = 'Reformation Day';

		// All Saints' Day
		$holidays[$year . '-11-01'] = 'All Saints\' Day';

		// Christmas
		$holidays[$year . '-12-25'] = 'Christmas Day';
		$holidays[$year . '-12-26'] = 'Second Christmas Day';

		return $holidays;
	}

	/**
	 * Gauss algorithm: days after March 21 for Easter (fallback when easter_days extension not loaded)
	 *
	 * @param int $year
	 * @return int
	 */
	private function easterDaysGauss(int $year): int
	{
		$a = $year % 19;
		$b = (int)($year / 100);
		$c = $year % 100;
		$d = (int)($b / 4);
		$e = $b % 4;
		$f = (int)(($b + 8) / 25);
		$g = (int)(($b - $f + 1) / 3);
		$h = (19 * $a + $b - $d - $g + 15) % 30;
		$i = (int)($c / 4);
		$k = $c % 4;
		$l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
		$m = (int)(($a + 11 * $h + 22 * $l) / 451);
		$month = (int)(($h + $l - 7 * $m + 114) / 31);
		$day = (($h + $l - 7 * $m + 114) % 31) + 1;

		$march21 = new \DateTime($year . '-03-21');
		$easterDate = new \DateTime("$year-$month-$day");
		$diff = $march21->diff($easterDate);

		return (int)$diff->days;
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

		// Validate type
		$validTypes = [
			self::TYPE_VACATION,
			self::TYPE_SICK_LEAVE,
			self::TYPE_PERSONAL_LEAVE,
			self::TYPE_PARENTAL_LEAVE,
			self::TYPE_SPECIAL_LEAVE,
			self::TYPE_UNPAID_LEAVE,
			self::TYPE_HOME_OFFICE,
			self::TYPE_BUSINESS_TRIP
		];
		if (!in_array($this->type, $validTypes)) {
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
	 * Get a summary array for API responses
	 *
	 * @return array
	 */
	public function getSummary(): array
	{
		$startDate = $this->getStartDate();
		$endDate = $this->getEndDate();
		$createdAt = $this->getCreatedAt();
		$updatedAt = $this->getUpdatedAt();
		
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'type' => $this->getType(),
			'startDate' => $startDate ? $startDate->format('Y-m-d') : null,
			'endDate' => $endDate ? $endDate->format('Y-m-d') : null,
			'days' => $this->getDays(),
			'workingDays' => $this->calculateWorkingDays(),
			'reason' => $this->getReason(),
			'status' => $this->getStatus(),
			'approverComment' => $this->getApproverComment(),
			'approvedBy' => $this->getApprovedBy(),
			'approvedAt' => $this->getApprovedAt()?->format('c'),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
			'substituteUserId' => $this->getSubstituteUserId()
		];
	}
}