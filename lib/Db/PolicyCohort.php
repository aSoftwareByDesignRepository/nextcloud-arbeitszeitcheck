<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class PolicyCohort extends Entity
{
	protected $code;
	protected $publicLabel;
	protected $confidentialLabel;
	protected $active;
	protected $revision;
	protected $createdAt;
	protected $createdBy;
	protected $updatedAt;
	protected $updatedBy;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('publicLabel', 'string');
		$this->addType('confidentialLabel', 'string');
		$this->addType('active', 'boolean');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('updatedAt', 'datetime');
		$this->addType('updatedBy', 'string');
	}
}
