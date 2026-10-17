<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripDayCalculation extends Entity
{
	protected $tripId;
	protected $localDate;
	protected $ruleSetId;
	protected $ruleConfigHash;
	protected $presenceMinutes;
	protected $actualWorkMinutes;
	protected $activeTravelMinutes;
	protected $passiveTravelMinutes;
	protected $complianceMinutes;
	protected $accountTargetMinutes;
	protected $accountTopUpMinutes;
	protected $exportMinutes;
	protected $resultJson;
	protected $snapshotHash;

	public function __construct()
	{
		$this->addType('tripId', 'integer');
		$this->addType('localDate', 'date');
		$this->addType('ruleSetId', 'integer');
		$this->addType('ruleConfigHash', 'string');
		$this->addType('presenceMinutes', 'integer');
		$this->addType('actualWorkMinutes', 'integer');
		$this->addType('activeTravelMinutes', 'integer');
		$this->addType('passiveTravelMinutes', 'integer');
		$this->addType('complianceMinutes', 'integer');
		$this->addType('accountTargetMinutes', 'integer');
		$this->addType('accountTopUpMinutes', 'integer');
		$this->addType('exportMinutes', 'integer');
		$this->addType('resultJson', 'string');
		$this->addType('snapshotHash', 'string');
	}
}
