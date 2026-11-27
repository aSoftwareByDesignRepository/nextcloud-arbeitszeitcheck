<?php

declare(strict_types=1);

/**
 * HolidayRule entity for the arbeitszeitcheck app
 *
 * A recurring company/custom holiday rule ("every 24 Dec", "every Good
 * Friday"). Rules are the single source of truth for recurring days;
 * concrete instances are synthesized at read time in
 * HolidayService::getHolidaysForYearInternal() and never persisted.
 *
 * rule_key is a materialized natural key ('d:MM-DD' or 'e:±NN') because
 * NULL columns do not deduplicate inside UNIQUE indexes on MySQL — the
 * anchor-irrelevant month/day/easter_offset columns are stored but never
 * part of the uniqueness contract.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getState()
 * @method void setState(string $state)
 * @method string getAnchor()
 * @method void setAnchor(string $anchor)
 * @method int getMonth()
 * @method void setMonth(int $month)
 * @method int getDay()
 * @method void setDay(int $day)
 * @method int getEasterOffset()
 * @method void setEasterOffset(int $easterOffset)
 * @method string getRuleKey()
 * @method void setRuleKey(string $ruleKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getNameMsgid()
 * @method void setNameMsgid(?string $nameMsgid)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method int getStartYear()
 * @method void setStartYear(int $startYear)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class HolidayRule extends Entity
{
	public const ANCHOR_DATE = 'date';
	public const ANCHOR_EASTER = 'easter';

	/** Curated named movable days (B-R4) — no free-form offsets in the MVP. */
	public const ALLOWED_EASTER_OFFSETS = [-48, -47, -46, -2, 1, 39, 50, 60];

	/** @var string */
	protected $state;

	/** @var string */
	protected $anchor;

	/** @var int */
	protected $month;

	/** @var int */
	protected $day;

	/** @var int */
	protected $easterOffset;

	/** @var string */
	protected $ruleKey;

	/** @var string */
	protected $name;

	/** @var string|null */
	protected $nameMsgid;

	/** @var string */
	protected $kind;

	/** @var string */
	protected $scope;

	/** @var int */
	protected $startYear;

	/** @var bool */
	protected $active;

	/** @var string|null */
	protected $createdBy;

	/** @var \DateTime */
	protected $createdAt;

	/** @var string|null */
	protected $updatedBy;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('state', 'string');
		$this->addType('anchor', 'string');
		$this->addType('month', 'integer');
		$this->addType('day', 'integer');
		$this->addType('easterOffset', 'integer');
		$this->addType('ruleKey', 'string');
		$this->addType('name', 'string');
		$this->addType('nameMsgid', 'string');
		$this->addType('kind', 'string');
		$this->addType('scope', 'string');
		$this->addType('startYear', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedBy', 'string');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Materialized natural key for the anchor (B-R1): 'd:MM-DD' for fixed
	 * days, 'e:+NN'/'e:-NN' for easter offsets. NULL-free so UNIQUE
	 * deduplicates correctly on every supported DB.
	 */
	public static function ruleKeyFor(string $anchor, int $month, int $day, int $easterOffset): string
	{
		if ($anchor === self::ANCHOR_EASTER) {
			return sprintf('e:%+03d', $easterOffset);
		}
		return sprintf('d:%02d-%02d', $month, $day);
	}

	/**
	 * Resolve the rule's concrete date for a year. Returns null when the
	 * anchor produces no day that year (29 Feb in non-leap years, EC-B1)
	 * or the year precedes start_year (EC-B3 — non-retroactive).
	 */
	public function resolveDateForYear(int $year): ?\DateTimeImmutable
	{
		if ($year < (int)$this->getStartYear()) {
			return null;
		}
		if ($this->getAnchor() === self::ANCHOR_EASTER) {
			// Curated offsets land inside the target year for every
			// possible Easter (Mar 22..Apr 25 ± 60d stays within the year).
			return \OCA\ArbeitszeitCheck\Support\EasterCalculator::easterDate($year)
				->modify(sprintf('%+d days', (int)$this->getEasterOffset()));
		}
		if (!checkdate((int)$this->getMonth(), (int)$this->getDay(), $year)) {
			return null;
		}
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, (int)$this->getMonth(), (int)$this->getDay()));
	}

	/**
	 * Validate the rule data.
	 *
	 * @return array<string,string> field => error message
	 */
	public function validate(): array
	{
		$errors = [];

		if ($this->state === '' || $this->state === null) {
			$errors['state'] = 'State code is required';
		}

		if (!in_array($this->anchor, [self::ANCHOR_DATE, self::ANCHOR_EASTER], true)) {
			$errors['anchor'] = 'Invalid rule anchor';
		} elseif ($this->anchor === self::ANCHOR_DATE) {
			if ($this->month < 1 || $this->month > 12 || $this->day < 1 || $this->day > 31) {
				$errors['month'] = 'A fixed-date rule needs a valid month and day';
			} elseif (!checkdate($this->month, $this->day, 2000)) {
				// 2000 is a leap year so 29 Feb is legal (fires on leap years only).
				$errors['month'] = 'Month/day combination is not a calendar day';
			}
		} elseif (!in_array($this->easterOffset, self::ALLOWED_EASTER_OFFSETS, true)) {
			$errors['easterOffset'] = 'Easter offset must be one of the named movable days';
		}

		if ($this->name === '' || $this->name === null) {
			$errors['name'] = 'Name is required';
		}

		if (!in_array($this->kind, [Holiday::KIND_FULL, Holiday::KIND_HALF], true)) {
			$errors['kind'] = 'Invalid holiday kind';
		}

		if (!in_array($this->scope, [Holiday::SCOPE_COMPANY, Holiday::SCOPE_CUSTOM], true)) {
			$errors['scope'] = 'Holiday rules are limited to company or custom scope';
		}

		if ($this->startYear < 1970 || $this->startYear > 2100) {
			$errors['startYear'] = 'Start year out of bounds';
		}

		$expectedKey = self::ruleKeyFor(
			(string)$this->anchor,
			(int)$this->month,
			(int)$this->day,
			(int)$this->easterOffset
		);
		if ($this->ruleKey !== $expectedKey) {
			$errors['ruleKey'] = 'Rule key does not match the anchor';
		}

		return $errors;
	}

	public function isValid(): bool
	{
		return $this->validate() === [];
	}

	/**
	 * Simple DTO for API responses.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$createdAt = $this->getCreatedAt();
		$updatedAt = $this->getUpdatedAt();

		return [
			'id' => $this->getId(),
			'state' => $this->getState(),
			'anchor' => $this->getAnchor(),
			'month' => $this->getMonth(),
			'day' => $this->getDay(),
			'easter_offset' => $this->getEasterOffset(),
			'rule_key' => $this->getRuleKey(),
			'name' => $this->getName(),
			'name_msgid' => $this->getNameMsgid(),
			'kind' => $this->getKind(),
			'scope' => $this->getScope(),
			'start_year' => $this->getStartYear(),
			'active' => (bool)$this->getActive(),
			'created_by' => $this->getCreatedBy(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
		];
	}
}
