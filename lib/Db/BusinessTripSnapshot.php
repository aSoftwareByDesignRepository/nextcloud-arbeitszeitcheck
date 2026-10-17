<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class BusinessTripSnapshot extends Entity
{
	public const KIND_PREVIEW = 'preview';
	public const KIND_SUBMITTED = 'submitted';
	public const KIND_APPROVED = 'approved';

	public const KINDS = [self::KIND_PREVIEW, self::KIND_SUBMITTED, self::KIND_APPROVED];

	protected $tripId;
	protected $tripRevision;
	protected $payloadHash;
	protected $kind;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('tripId', 'integer');
		$this->addType('tripRevision', 'integer');
		$this->addType('payloadHash', 'string');
		$this->addType('kind', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
