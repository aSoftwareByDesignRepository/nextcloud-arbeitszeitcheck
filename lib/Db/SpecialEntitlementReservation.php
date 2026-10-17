<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class SpecialEntitlementReservation extends Entity
{
	public const STATUS_ACTIVE = 'active';
	public const STATUS_CONSUMED = 'consumed';
	public const STATUS_RELEASED = 'released';

	protected $periodId;
	protected $absenceId;
	protected $amount;
	protected $status;
	protected $requestKey;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('periodId', 'integer');
		$this->addType('absenceId', 'integer');
		$this->addType('amount', 'float');
		$this->addType('status', 'string');
		$this->addType('requestKey', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
