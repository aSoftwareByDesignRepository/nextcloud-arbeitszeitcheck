<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class SpecialEntitlementLedgerEntry extends Entity
{
	public const KIND_DEBIT = 'debit';
	public const KIND_CREDIT = 'credit';
	public const KIND_CORRECTION = 'correction';

	protected $periodId;
	protected $eventKey;
	protected $kind;
	protected $amount;
	protected $absenceId;
	protected $reversesLedgerId;
	protected $actorUid;
	protected $reason;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('periodId', 'integer');
		$this->addType('eventKey', 'string');
		$this->addType('kind', 'string');
		$this->addType('amount', 'float');
		$this->addType('absenceId', 'integer');
		$this->addType('reversesLedgerId', 'integer');
		$this->addType('actorUid', 'string');
		$this->addType('reason', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
