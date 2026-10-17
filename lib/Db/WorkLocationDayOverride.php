<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class WorkLocationDayOverride extends Entity
{
	protected $userId;
	protected $localDate;
	protected $locationCode;
	protected $revision;
	protected $source;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('localDate', 'date');
		$this->addType('locationCode', 'string');
		$this->addType('revision', 'integer');
		$this->addType('source', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
