<?php

declare(strict_types=1);

/**
 * AuditLog entity for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * AuditLog entity
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method int|null getEntityId()
 * @method void setEntityId(int|null $entityId)
 * @method string|null getOldValues()
 * @method void setOldValues(string|null $oldValues)
 * @method string|null getNewValues()
 * @method void setNewValues(string|null $newValues)
 * @method string|null getIpAddress()
 * @method void setIpAddress(string|null $ipAddress)
 * @method string|null getUserAgent()
 * @method void setUserAgent(string|null $userAgent)
 * @method string|null getPerformedBy()
 * @method void setPerformedBy(string|null $performedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AuditLog extends Entity
{
	/** @var string */
	protected $userId;

	/** @var string */
	protected $action;

	/** @var string */
	protected $entityType;

	/** @var int|null */
	protected $entityId;

	/** @var string|null */
	protected $oldValues;

	/** @var string|null */
	protected $newValues;

	/** @var string|null */
	protected $ipAddress;

	/** @var string|null */
	protected $userAgent;

	/** @var string|null */
	protected $performedBy;

	/** @var \DateTime */
	protected $createdAt;

	/**
	 * AuditLog constructor
	 */
	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('action', 'string');
		$this->addType('entityType', 'string');
		$this->addType('entityId', 'integer');
		$this->addType('oldValues', 'string');
		$this->addType('newValues', 'string');
		$this->addType('ipAddress', 'string');
		$this->addType('userAgent', 'string');
		$this->addType('performedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}

	/**
	 * Validate the audit log data
	 *
	 * @return array Array of validation errors
	 */
	public function validate(): array
	{
		$errors = [];

		// Validate user ID
		if (empty($this->userId)) {
			$errors['userId'] = 'User ID is required';
		}

		// Validate action
		if (empty($this->action)) {
			$errors['action'] = 'Action is required';
		}

		// Validate entity type
		if (empty($this->entityType)) {
			$errors['entityType'] = 'Entity type is required';
		}

		return $errors;
	}

	/**
	 * Check if the audit log data is valid
	 *
	 * @return bool
	 */
	public function isValid(): bool
	{
		return empty($this->validate());
	}

	/**
	 * Get a summary array for API responses
	 *
	 * @return array
	 */
	public function getSummary(): array
	{
		$createdAt = $this->getCreatedAt();
		
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'action' => $this->getAction(),
			'entityType' => $this->getEntityType(),
			'entityId' => $this->getEntityId(),
			'ipAddress' => $this->getIpAddress(),
			'performedBy' => $this->getPerformedBy(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null
		];
	}
}