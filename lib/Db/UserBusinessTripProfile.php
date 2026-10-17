<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Personal trip profile: stores a duration only — never a home/work
 * address or route (privacy by design, spec §9.2).
 */
class UserBusinessTripProfile extends Entity
{
	protected $userId;
	protected $normalCommuteMinutes;
	protected $effectiveFrom;
	protected $effectiveTo;
	protected $revision;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('normalCommuteMinutes', 'integer');
		$this->addType('effectiveFrom', 'date');
		$this->addType('effectiveTo', 'date');
		$this->addType('revision', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
