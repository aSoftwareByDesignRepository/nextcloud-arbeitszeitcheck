<?php

declare(strict_types=1);

/**
 * One target employee row of a year-end reset run (SPEC §9.5). Monetary
 * values are centi-hours (1/100 h) — never binary floats.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int|null getLastExecutionRunId()
 * @method void setLastExecutionRunId(int|null $v)
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method int getClosingYear()
 * @method void setClosingYear(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method string|null getSourceRevision()
 * @method void setSourceRevision(string|null $v)
 * @method int|null getBeforeCentiHours()
 * @method void setBeforeCentiHours(int|null $v)
 * @method int|null getDeltaCentiHours()
 * @method void setDeltaCentiHours(int|null $v)
 * @method int|null getAdjustmentId()
 * @method void setAdjustmentId(int|null $v)
 * @method string|null getErrorCode()
 * @method void setErrorCode(string|null $v)
 * @method string|null getLeaseToken()
 * @method void setLeaseToken(string|null $v)
 * @method \DateTime|null getLeaseUntil()
 * @method void setLeaseUntil(\DateTime|null $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 * @method \DateTime|null getCompletedAt()
 * @method void setCompletedAt(\DateTime|null $v)
 * @method string getReviewState()
 * @method void setReviewState(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class YearResetItem extends Entity
{
	public const STATE_PENDING = 'pending';
	public const STATE_IN_PROGRESS = 'in_progress';
	public const STATE_DONE = 'done';
	public const STATE_SKIPPED = 'skipped';
	public const STATE_FAILED = 'failed';

	public const REVIEW_CLEAR = 'clear';
	public const REVIEW_NEEDS_REVIEW = 'needs_review';

	protected $lastExecutionRunId;
	protected $userId;
	protected $closingYear;
	protected $state;
	protected $sourceRevision;
	protected $beforeCentiHours;
	protected $deltaCentiHours;
	protected $adjustmentId;
	protected $errorCode;
	protected $leaseToken;
	protected $leaseUntil;
	protected $revision;
	protected $completedAt;
	protected $reviewState;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('lastExecutionRunId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('closingYear', 'integer');
		$this->addType('state', 'string');
		$this->addType('sourceRevision', 'string');
		$this->addType('beforeCentiHours', 'integer');
		$this->addType('deltaCentiHours', 'integer');
		$this->addType('adjustmentId', 'integer');
		$this->addType('errorCode', 'string');
		$this->addType('leaseToken', 'string');
		$this->addType('leaseUntil', 'datetime');
		$this->addType('revision', 'integer');
		$this->addType('completedAt', 'datetime');
		$this->addType('reviewState', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
