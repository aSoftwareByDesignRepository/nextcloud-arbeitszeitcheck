<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripSegment extends Entity
{
	public const TYPE_ACTIVE_TRAVEL = 'active_travel';
	public const TYPE_PASSIVE_TRAVEL = 'passive_travel';
	public const TYPE_WORK_DURING_TRAVEL = 'work_during_travel';
	public const TYPE_REST_PERSONAL = 'rest_personal';
	public const TYPE_UNCLASSIFIED = 'unclassified';

	public const TYPES = [
		self::TYPE_ACTIVE_TRAVEL,
		self::TYPE_PASSIVE_TRAVEL,
		self::TYPE_WORK_DURING_TRAVEL,
		self::TYPE_REST_PERSONAL,
		self::TYPE_UNCLASSIFIED,
	];

	public const MODE_CAR_DRIVER = 'car_driver';
	public const MODE_CAR_PASSENGER = 'car_passenger';
	public const MODE_TRAIN = 'train';
	public const MODE_PLANE = 'plane';
	public const MODE_PUBLIC_TRANSPORT = 'public_transport';
	public const MODE_BICYCLE = 'bicycle';
	public const MODE_WALKING = 'walking';
	public const MODE_OTHER = 'other';

	public const MODES = [
		self::MODE_CAR_DRIVER,
		self::MODE_CAR_PASSENGER,
		self::MODE_TRAIN,
		self::MODE_PLANE,
		self::MODE_PUBLIC_TRANSPORT,
		self::MODE_BICYCLE,
		self::MODE_WALKING,
		self::MODE_OTHER,
	];

	public const SOURCE_EMPLOYEE = 'employee';
	public const SOURCE_MANAGER = 'manager';
	public const SOURCE_SYSTEM = 'system_suggestion';

	protected $tripId;
	protected $startsAtUtc;
	protected $endsAtUtc;
	protected $segmentType;
	protected $transportMode;
	protected $source;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('tripId', 'integer');
		$this->addType('startsAtUtc', 'datetime');
		$this->addType('endsAtUtc', 'datetime');
		$this->addType('segmentType', 'string');
		$this->addType('transportMode', 'string');
		$this->addType('source', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
