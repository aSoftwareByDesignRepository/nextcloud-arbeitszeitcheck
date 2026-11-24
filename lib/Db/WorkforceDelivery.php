<?php

declare(strict_types=1);

/**
 * Durable post-commit notification intent (SPEC §9.5, FM-09). A worker
 * delivers at-least-once with retry/backoff; transient failures can never
 * roll back the committed business write.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getAuditEventId()
 * @method void setAuditEventId(int $v)
 * @method string getRecipientUid()
 * @method void setRecipientUid(string $v)
 * @method string getChannel()
 * @method void setChannel(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method int getAttempts()
 * @method void setAttempts(int $v)
 * @method \DateTime|null getNextAttemptAt()
 * @method void setNextAttemptAt(\DateTime|null $v)
 * @method string|null getLeaseToken()
 * @method void setLeaseToken(string|null $v)
 * @method \DateTime|null getLeaseUntil()
 * @method void setLeaseUntil(\DateTime|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class WorkforceDelivery extends Entity
{
	public const STATE_PENDING = 'pending';
	public const STATE_CLAIMED = 'claimed';
	public const STATE_DELIVERED = 'delivered';
	public const STATE_DEAD = 'dead';

	public const CHANNEL_APP = 'app';
	public const CHANNEL_MAIL = 'mail';

	public const MAX_ATTEMPTS = 8;

	protected $auditEventId;
	protected $recipientUid;
	protected $channel;
	protected $state;
	protected $attempts;
	protected $nextAttemptAt;
	protected $leaseToken;
	protected $leaseUntil;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('auditEventId', 'integer');
		$this->addType('recipientUid', 'string');
		$this->addType('channel', 'string');
		$this->addType('state', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('nextAttemptAt', 'datetime');
		$this->addType('leaseToken', 'string');
		$this->addType('leaseUntil', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
