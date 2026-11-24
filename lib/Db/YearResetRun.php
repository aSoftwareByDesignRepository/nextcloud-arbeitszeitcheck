<?php

declare(strict_types=1);

/**
 * Year-end reset run (manual + annual), see SPEC
 * optional-absence-corrections-hourly-leave-year-end §9.5.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getOperationKey()
 * @method void setOperationKey(string $v)
 * @method int getClosingYear()
 * @method void setClosingYear(int $v)
 * @method string getOrigin()
 * @method void setOrigin(string $v)
 * @method int getPolicyRevision()
 * @method void setPolicyRevision(int $v)
 * @method string|null getPolicySnapshot()
 * @method void setPolicySnapshot(string|null $v)
 * @method string|null getSelectionSnapshot()
 * @method void setSelectionSnapshot(string|null $v)
 * @method string getActorUid()
 * @method void setActorUid(string $v)
 * @method string|null getReason()
 * @method void setReason(string|null $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 * @method string|null getPreviewKeyHash()
 * @method void setPreviewKeyHash(string|null $v)
 * @method \DateTime|null getPreviewExpiresAt()
 * @method void setPreviewExpiresAt(\DateTime|null $v)
 * @method string|null getSelectionCursor()
 * @method void setSelectionCursor(string|null $v)
 * @method \DateTime|null getSelectionCompleteAt()
 * @method void setSelectionCompleteAt(\DateTime|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class YearResetRun extends Entity
{
	public const STATE_PREVIEWED = 'previewed';
	public const STATE_SELECTING = 'selecting';
	public const STATE_READY = 'ready';
	public const STATE_EXECUTING = 'executing';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';

	public const ORIGIN_MANUAL = 'manual';
	public const ORIGIN_ANNUAL = 'annual';

	protected $operationKey;
	protected $closingYear;
	protected $origin;
	protected $policyRevision;
	protected $policySnapshot;
	protected $selectionSnapshot;
	protected $actorUid;
	protected $reason;
	protected $state;
	protected $revision;
	protected $previewKeyHash;
	protected $previewExpiresAt;
	protected $selectionCursor;
	protected $selectionCompleteAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('operationKey', 'string');
		$this->addType('closingYear', 'integer');
		$this->addType('origin', 'string');
		$this->addType('policyRevision', 'integer');
		$this->addType('policySnapshot', 'string');
		$this->addType('selectionSnapshot', 'string');
		$this->addType('actorUid', 'string');
		$this->addType('reason', 'string');
		$this->addType('state', 'string');
		$this->addType('revision', 'integer');
		$this->addType('previewKeyHash', 'string');
		$this->addType('previewExpiresAt', 'datetime');
		$this->addType('selectionCursor', 'string');
		$this->addType('selectionCompleteAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
