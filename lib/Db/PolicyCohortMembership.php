<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class PolicyCohortMembership extends Entity
{
	protected $cohortId;
	protected $userId;
	protected $effectiveFrom;
	protected $effectiveTo;
	protected $revision;
	protected $createdAt;
	protected $createdBy;
	protected $updatedAt;
	protected $updatedBy;

	public function __construct()
	{
		$this->addType('cohortId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('effectiveFrom', 'string');
		$this->addType('effectiveTo', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('updatedAt', 'datetime');
		$this->addType('updatedBy', 'string');
	}
}
