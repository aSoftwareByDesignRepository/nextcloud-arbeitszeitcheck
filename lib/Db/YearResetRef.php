<?php

declare(strict_types=1);

/**
 * Preview↔execution reference row for a year-end reset run (SPEC §9.5).
 * Binds a previewed user to the durable item that executed the reset.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getRunId()
 * @method void setRunId(int $v)
 * @method int|null getItemId()
 * @method void setItemId(int|null $v)
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string|null getPreviewSourceRevision()
 * @method void setPreviewSourceRevision(string|null $v)
 * @method string|null getPreviewResult()
 * @method void setPreviewResult(string|null $v)
 * @method string getOutcome()
 * @method void setOutcome(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class YearResetRef extends Entity
{
	public const OUTCOME_PENDING = 'pending';
	public const OUTCOME_DONE = 'done';
	public const OUTCOME_SKIPPED = 'skipped';
	public const OUTCOME_FAILED = 'failed';

	protected $runId;
	protected $itemId;
	protected $userId;
	protected $previewSourceRevision;
	protected $previewResult;
	protected $outcome;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('runId', 'integer');
		$this->addType('itemId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('previewSourceRevision', 'string');
		$this->addType('previewResult', 'string');
		$this->addType('outcome', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
