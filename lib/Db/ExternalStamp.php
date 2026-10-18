<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getStartTs()
 * @method void setStartTs(int $v)
 * @method int|null getEndTs()
 * @method void setEndTs(?int $v)
 * @method string getSourceApp()
 * @method void setSourceApp(string $v)
 * @method string getSourceRef()
 * @method void setSourceRef(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method int|null getPromotedEntryId()
 * @method void setPromotedEntryId(?int $v)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $v)
 * @method \DateTime|null getDecidedAt()
 * @method void setDecidedAt(?\DateTime $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class ExternalStamp extends Entity
{
	public const KIND_STAMP = 'stamp';
	public const KIND_SEGMENT = 'segment';

	public const STATUS_PENDING = 'pending';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_DISMISSED = 'dismissed';

	protected string $userId = '';
	protected string $kind = self::KIND_STAMP;
	protected int $startTs = 0;
	protected ?int $endTs = null;
	protected string $sourceApp = '';
	protected string $sourceRef = '';
	protected string $status = self::STATUS_PENDING;
	protected ?int $promotedEntryId = null;
	protected ?string $decidedBy = null;
	protected ?\DateTime $decidedAt = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('kind', 'string');
		$this->addType('startTs', 'integer');
		$this->addType('endTs', 'integer');
		$this->addType('sourceApp', 'string');
		$this->addType('sourceRef', 'string');
		$this->addType('status', 'string');
		$this->addType('promotedEntryId', 'integer');
		$this->addType('decidedBy', 'string');
		$this->addType('decidedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}

	public function isPending(): bool
	{
		return $this->status === self::STATUS_PENDING;
	}
}
