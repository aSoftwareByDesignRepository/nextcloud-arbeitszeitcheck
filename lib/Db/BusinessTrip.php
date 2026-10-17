<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTrip extends Entity
{
	public const STATUS_DRAFT = 'draft';
	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_CANCELLED = 'cancelled';

	public const STATUSES = [
		self::STATUS_DRAFT,
		self::STATUS_SUBMITTED,
		self::STATUS_APPROVED,
		self::STATUS_REJECTED,
		self::STATUS_CANCELLED,
	];

	protected $userId;
	protected $startsAtUtc;
	protected $endsAtUtc;
	protected $originTimezone;
	protected $destinationTimezone;
	protected $evaluationTimezone;
	protected $purpose;
	protected $status;
	protected $requestKey;
	protected $revision;
	protected $currentSnapshotId;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('startsAtUtc', 'datetime');
		$this->addType('endsAtUtc', 'datetime');
		$this->addType('originTimezone', 'string');
		$this->addType('destinationTimezone', 'string');
		$this->addType('evaluationTimezone', 'string');
		$this->addType('purpose', 'string');
		$this->addType('status', 'string');
		$this->addType('requestKey', 'string');
		$this->addType('revision', 'integer');
		$this->addType('currentSnapshotId', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
