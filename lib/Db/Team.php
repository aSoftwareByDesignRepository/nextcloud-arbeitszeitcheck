<?php

declare(strict_types=1);

/**
 * Team entity for app-owned teams (departments with optional parent-child hierarchy).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method int|null getParentId()
 * @method void setParentId(int|null $parentId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Team extends Entity
{
	protected ?string $name = null;
	protected ?int $parentId = null;
	protected int $sortOrder = 0;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('parentId', 'integer');
		$this->addType('sortOrder', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function getSummary(): array
	{
		$createdAt = $this->getCreatedAt();
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'parentId' => $this->getParentId(),
			'sortOrder' => $this->getSortOrder(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
		];
	}
}
