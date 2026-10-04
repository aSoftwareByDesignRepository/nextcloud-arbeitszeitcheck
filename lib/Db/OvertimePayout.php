<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarYear()
 * @method void setCalendarYear(int $calendarYear)
 * @method int getCalendarMonth()
 * @method void setCalendarMonth(int $calendarMonth)
 * @method float getHoursPaid()
 * @method void setHoursPaid(float $hoursPaid)
 * @method float getEffectiveBalanceBefore()
 * @method void setEffectiveBalanceBefore(float $effectiveBalanceBefore)
 * @method float getEffectiveBalanceAfter()
 * @method void setEffectiveBalanceAfter(float $effectiveBalanceAfter)
 * @method float getRawBalanceBefore()
 * @method void setRawBalanceBefore(float $rawBalanceBefore)
 * @method float getBankMaxHours()
 * @method void setBankMaxHours(float $bankMaxHours)
 * @method string getProcessedBy()
 * @method void setProcessedBy(string $processedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class OvertimePayout extends Entity
{
	/**
	 * Columns that are NOT NULL in the schema (Version1027) and carry no
	 * DB-side default. They must always be written on INSERT — even when the
	 * value equals the PHP property default.
	 */
	private const REQUIRED_INSERT_FIELDS = [
		'userId',
		'calendarYear',
		'calendarMonth',
		'hoursPaid',
		'effectiveBalanceBefore',
		'effectiveBalanceAfter',
		'rawBalanceBefore',
		'bankMaxHours',
		'processedBy',
		'createdAt',
	];

	protected $userId;
	protected $calendarYear;
	protected $calendarMonth;
	protected $hoursPaid = 0.0;
	protected $effectiveBalanceBefore = 0.0;
	protected $effectiveBalanceAfter = 0.0;
	protected $rawBalanceBefore = 0.0;
	protected $bankMaxHours = 100.0;
	protected $processedBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('calendarYear', 'integer');
		$this->addType('calendarMonth', 'integer');
		$this->addType('hoursPaid', 'float');
		$this->addType('effectiveBalanceBefore', 'float');
		$this->addType('effectiveBalanceAfter', 'float');
		$this->addType('rawBalanceBefore', 'float');
		$this->addType('bankMaxHours', 'float');
		$this->addType('processedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Entity::setter() skips marking a field as updated when the new value
	 * equals the property default. QBMapper::insert() only writes updated
	 * fields, so a payout with e.g. bankMaxHours=100.0 or a zero balance would
	 * silently drop the column from the INSERT and the DB aborts with
	 * "doesn't have a default value" (SQLSTATE 1364). Re-mark the required
	 * columns once they hold a non-null value.
	 */
	protected function setter(string $name, array $args): void
	{
		parent::setter($name, $args);
		if (in_array($name, self::REQUIRED_INSERT_FIELDS, true) && $this->$name !== null) {
			$this->markFieldUpdated($name);
		}
	}
}
