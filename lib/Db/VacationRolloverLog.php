<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFromYear()
 * @method void setFromYear(int $fromYear)
 * @method int getToYear()
 * @method void setToYear(int $toYear)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class VacationRolloverLog extends Entity
{
	protected $userId;
	protected $fromYear;
	protected $toYear;
	protected $amount = 0.0;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('fromYear', 'integer');
		$this->addType('toYear', 'integer');
		$this->addType('amount', 'float');
		$this->addType('createdAt', 'datetime');
	}
}
