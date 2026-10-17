<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripTimeEntryLink extends Entity
{
	protected $tripId;
	protected $timeEntryId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('tripId', 'integer');
		$this->addType('timeEntryId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}
