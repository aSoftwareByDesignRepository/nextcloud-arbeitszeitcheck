<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class SpecialEntitlementPeriod extends Entity
{
	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';

	protected $entitlementTypeId;
	protected $userId;
	protected $periodStart;
	protected $periodEnd;
	protected $computedAmount;
	protected $unit;
	protected $computationSnapshotId;
	protected $status;
	protected $revision;

	public function __construct()
	{
		$this->addType('entitlementTypeId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('periodStart', 'date');
		$this->addType('periodEnd', 'date');
		$this->addType('computedAmount', 'float');
		$this->addType('unit', 'string');
		$this->addType('computationSnapshotId', 'integer');
		$this->addType('status', 'string');
		$this->addType('revision', 'integer');
	}
}
