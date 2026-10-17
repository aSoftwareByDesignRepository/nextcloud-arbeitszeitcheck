<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class WorkLocationDefaultPolicy extends Entity
{
	public const SCOPE_ORG = 'org';
	public const SCOPE_MODEL = 'model';
	public const SCOPE_TEAM = 'team';
	public const SCOPE_USER = 'user';

	/** Synthetic scope id used for the single org-level scope. */
	public const ORG_SCOPE_ID = 'org';

	/** @var list<string> */
	public const SCOPE_TYPES = [
		self::SCOPE_ORG,
		self::SCOPE_MODEL,
		self::SCOPE_TEAM,
		self::SCOPE_USER,
	];

	protected $scopeType;
	protected $scopeId;
	protected $locationCode;
	protected $priority;
	protected $effectiveFrom;
	protected $effectiveTo;
	protected $revision;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('scopeType', 'string');
		$this->addType('scopeId', 'string');
		$this->addType('locationCode', 'string');
		$this->addType('priority', 'integer');
		$this->addType('effectiveFrom', 'date');
		$this->addType('effectiveTo', 'date');
		$this->addType('revision', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
