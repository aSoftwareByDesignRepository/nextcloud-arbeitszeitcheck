<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class SpecialEntitlementType extends Entity
{
	public const UNIT_DAYS = 'days';
	public const UNIT_HOURS = 'hours';
	public const PERIOD_CALENDAR_YEAR = 'calendar_year';
	public const PERIOD_SERVICE_YEAR = 'service_year';
	public const PERIOD_ONE_TIME = 'one_time';

	public const UNITS = [self::UNIT_DAYS, self::UNIT_HOURS];
	public const PERIODS = [self::PERIOD_CALENDAR_YEAR, self::PERIOD_SERVICE_YEAR, self::PERIOD_ONE_TIME];

	protected $code;
	protected $publicLabel;
	protected $confidentialLabel;
	protected $labelI18n;
	protected $absenceTypeCode;
	protected $unit;
	protected $periodType;
	protected $allowNegative;
	protected $employeeMaySeeConfidentialLabel;
	protected $active;
	protected $version;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('publicLabel', 'string');
		$this->addType('confidentialLabel', 'string');
		$this->addType('labelI18n', 'string');
		$this->addType('absenceTypeCode', 'string');
		$this->addType('unit', 'string');
		$this->addType('periodType', 'string');
		$this->addType('allowNegative', 'boolean');
		$this->addType('employeeMaySeeConfidentialLabel', 'boolean');
		$this->addType('active', 'boolean');
		$this->addType('version', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
