<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripRuleSet extends Entity
{
	public const JURISDICTION_DE = 'DE';
	public const JURISDICTION_AT = 'AT';
	public const JURISDICTION_CH = 'CH';
	public const JURISDICTION_CUSTOM = 'custom';

	public const JURISDICTIONS = [
		self::JURISDICTION_DE,
		self::JURISDICTION_AT,
		self::JURISDICTION_CH,
		self::JURISDICTION_CUSTOM,
	];

	public const STATUS_DRAFT = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_RETIRED = 'retired';

	public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_RETIRED];

	protected $code;
	protected $version;
	protected $name;
	protected $jurisdiction;
	protected $status;
	protected $validFrom;
	protected $validTo;
	protected $configJson;
	protected $configHash;
	protected $sourceTitle;
	protected $sourceReference;
	protected $classifierSchemaVersion;
	protected $reviewedAt;
	protected $reviewedBy;
	protected $createdBy;
	protected $activatedBy;
	protected $activatedAt;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('version', 'integer');
		$this->addType('name', 'string');
		$this->addType('jurisdiction', 'string');
		$this->addType('status', 'string');
		$this->addType('validFrom', 'date');
		$this->addType('validTo', 'date');
		$this->addType('configJson', 'string');
		$this->addType('configHash', 'string');
		$this->addType('sourceTitle', 'string');
		$this->addType('sourceReference', 'string');
		$this->addType('classifierSchemaVersion', 'integer');
		$this->addType('reviewedAt', 'datetime');
		$this->addType('reviewedBy', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('activatedBy', 'string');
		$this->addType('activatedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}
}
