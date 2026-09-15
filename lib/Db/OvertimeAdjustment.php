<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Audited overtime hour-ledger adjustment (does not modify time entries).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarYear()
 * @method void setCalendarYear(int $calendarYear)
 * @method \DateTime getEffectiveOn()
 * @method void setEffectiveOn(\DateTime $effectiveOn)
 * @method float getHoursDelta()
 * @method void setHoursDelta(float $hoursDelta)
 * @method string getReasonCode()
 * @method void setReasonCode(string $reasonCode)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method float getBalanceBefore()
 * @method void setBalanceBefore(float $balanceBefore)
 * @method float getBalanceAfter()
 * @method void setBalanceAfter(float $balanceAfter)
 * @method string getProcessedBy()
 * @method void setProcessedBy(string $processedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class OvertimeAdjustment extends Entity
{
	public const REASON_MANUAL_PAYOUT = 'manual_payout';
	public const REASON_PERIOD_NULLUNG = 'period_nullung';
	public const REASON_UNDERTIME_WAIVER = 'undertime_waiver';
	public const REASON_CUSTOM = 'custom';

	/** @var list<string> */
	public const REASON_CODES = [
		self::REASON_MANUAL_PAYOUT,
		self::REASON_PERIOD_NULLUNG,
		self::REASON_UNDERTIME_WAIVER,
		self::REASON_CUSTOM,
	];

	protected $userId;
	protected $calendarYear;
	protected $effectiveOn;
	protected $hoursDelta = 0.0;
	protected $reasonCode = self::REASON_CUSTOM;
	protected $note;
	protected $balanceBefore = 0.0;
	protected $balanceAfter = 0.0;
	protected $processedBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('calendarYear', 'integer');
		$this->addType('effectiveOn', 'datetime');
		$this->addType('hoursDelta', 'float');
		$this->addType('reasonCode', 'string');
		$this->addType('note', 'string');
		$this->addType('balanceBefore', 'float');
		$this->addType('balanceAfter', 'float');
		$this->addType('processedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
