<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripRuleAssignment extends Entity
{
	public const SCOPE_ORG = 'org';
	public const SCOPE_MODEL = 'model';
	public const SCOPE_TEAM = 'team';
	public const SCOPE_GROUP = 'policy_cohort';
	public const SCOPE_USER = 'user';

	public const SCOPES = [
		self::SCOPE_ORG,
		self::SCOPE_MODEL,
		self::SCOPE_TEAM,
		self::SCOPE_GROUP,
		self::SCOPE_USER,
	];

	public const ORG_SCOPE_ID = 'org';

	protected $ruleSetId;
	protected $scopeType;
	protected $scopeId;
	protected $priority;
	protected $effectiveFrom;
	protected $effectiveTo;
	protected $revision;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('ruleSetId', 'integer');
		$this->addType('scopeType', 'string');
		$this->addType('scopeId', 'string');
		$this->addType('priority', 'integer');
		$this->addType('effectiveFrom', 'date');
		$this->addType('effectiveTo', 'date');
		$this->addType('revision', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
