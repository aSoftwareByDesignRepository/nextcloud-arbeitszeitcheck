<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Per-user vacation carryover (Resturlaub) for a calendar year.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method float getCarryoverDays()
 * @method void setCarryoverDays(float $carryoverDays)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class VacationYearBalance extends Entity
{
	protected $userId;
	protected $year;
	protected $carryoverDays = 0.0;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('year', 'integer');
		$this->addType('carryoverDays', 'float');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
