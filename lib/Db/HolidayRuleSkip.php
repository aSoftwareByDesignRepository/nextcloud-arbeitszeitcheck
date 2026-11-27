<?php

declare(strict_types=1);

/**
 * HolidayRuleSkip entity for the arbeitszeitcheck app
 *
 * A per-year exception on a recurring holiday rule ("in 2027 the company
 * holiday does not apply"). Skips belong to the year, not the rule
 * definition — they survive rule edits and only become inert when the
 * rule itself is deactivated.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRuleId()
 * @method void setRuleId(int $ruleId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class HolidayRuleSkip extends Entity
{
	/** @var int */
	protected $ruleId;

	/** @var int */
	protected $year;

	/** @var string|null */
	protected $createdBy;

	/** @var \DateTime */
	protected $createdAt;

	public function __construct()
	{
		$this->addType('ruleId', 'integer');
		$this->addType('year', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Simple DTO for API responses.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$createdAt = $this->getCreatedAt();

		return [
			'id' => $this->getId(),
			'rule_id' => $this->getRuleId(),
			'year' => $this->getYear(),
			'created_by' => $this->getCreatedBy(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
		];
	}
}
