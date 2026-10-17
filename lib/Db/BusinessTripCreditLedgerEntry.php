<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripCreditLedgerEntry extends Entity
{
	public const KIND_TOP_UP = 'top_up';
	public const KIND_REVERSAL = 'reversal';
	public const KIND_CORRECTION = 'correction';

	public const KINDS = [self::KIND_TOP_UP, self::KIND_REVERSAL, self::KIND_CORRECTION];

	protected $userId;
	protected $tripId;
	protected $localDate;
	protected $eventKey;
	protected $kind;
	protected $minutes;
	protected $reversesId;
	protected $snapshotHash;
	protected $actorUid;
	protected $reason;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('tripId', 'integer');
		$this->addType('localDate', 'date');
		$this->addType('eventKey', 'string');
		$this->addType('kind', 'string');
		$this->addType('minutes', 'integer');
		$this->addType('reversesId', 'integer');
		$this->addType('snapshotHash', 'string');
		$this->addType('actorUid', 'string');
		$this->addType('reason', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
