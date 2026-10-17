<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class SpecialEntitlementPolicy extends Entity
{
	public const OP_INHERIT = 'inherit';
	public const OP_ADD = 'add';
	public const OP_REPLACE = 'replace';
	public const OP_DISABLE = 'disable';

	public const OPERATIONS = [self::OP_INHERIT, self::OP_ADD, self::OP_REPLACE, self::OP_DISABLE];

	public const SCOPE_ORG = 'org';
	public const SCOPE_MODEL = 'model';
	public const SCOPE_GROUP = 'policy_group';
	public const SCOPE_TEAM = 'team';
	public const SCOPE_USER = 'user';
	public const SCOPES = [self::SCOPE_ORG, self::SCOPE_MODEL, self::SCOPE_GROUP, self::SCOPE_TEAM, self::SCOPE_USER];
	public const ORG_SCOPE_ID = 'org';

	protected $entitlementTypeId;
	protected $scopeType;
	protected $scopeId;
	protected $operation;
	protected $amount;
	protected $tariffRuleSetId;
	protected $priority;
	protected $effectiveFrom;
	protected $effectiveTo;
	protected $reason;
	protected $version;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('entitlementTypeId', 'integer');
		$this->addType('scopeType', 'string');
		$this->addType('scopeId', 'string');
		$this->addType('operation', 'string');
		$this->addType('amount', 'float');
		$this->addType('tariffRuleSetId', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('effectiveFrom', 'date');
		$this->addType('effectiveTo', 'date');
		$this->addType('reason', 'string');
		$this->addType('version', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
