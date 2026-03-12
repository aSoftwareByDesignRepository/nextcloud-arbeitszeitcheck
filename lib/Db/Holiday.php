<?php

declare(strict_types=1);

/**
 * Holiday entity for the arbeitszeitcheck app
 *
 * Represents a single holiday definition for a specific German state
 * (Bundesland) and date, including whether it is a full or half day
 * and whether it is statutory, company-wide or custom.
 *
 * The combination of (state, date, scope) is considered the natural key.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getState()
 * @method void setState(string $state)
 * @method \DateTime getDate()
 * @method void setDate(\DateTime $date)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Holiday extends Entity
{
	public const KIND_FULL = 'full';
	public const KIND_HALF = 'half';

	public const SCOPE_STATUTORY = 'statutory';
	public const SCOPE_COMPANY = 'company';
	public const SCOPE_CUSTOM = 'custom';

	public const SOURCE_GENERATED = 'generated';
	public const SOURCE_MANUAL = 'manual';

	/** @var string */
	protected $state;

	/** @var \DateTime */
	protected $date;

	/** @var string */
	protected $name;

	/** @var string */
	protected $kind;

	/** @var string */
	protected $scope;

	/** @var string|null */
	protected $source;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('state', 'string');
		$this->addType('date', 'date');
		$this->addType('name', 'string');
		$this->addType('kind', 'string');
		$this->addType('scope', 'string');
		$this->addType('source', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/**
	 * Validate the holiday data.
	 *
	 * @return array<string,string> field => error message
	 */
	public function validate(): array
	{
		$errors = [];

		if ($this->state === '' || $this->state === null) {
			$errors['state'] = 'State code is required';
		}

		if (!$this->date instanceof \DateTime) {
			$errors['date'] = 'Date is required';
		}

		if ($this->name === '' || $this->name === null) {
			$errors['name'] = 'Name is required';
		}

		if (!in_array($this->kind, [self::KIND_FULL, self::KIND_HALF], true)) {
			$errors['kind'] = 'Invalid holiday kind';
		}

		if (!in_array($this->scope, [self::SCOPE_STATUTORY, self::SCOPE_COMPANY, self::SCOPE_CUSTOM], true)) {
			$errors['scope'] = 'Invalid holiday scope';
		}

		return $errors;
	}

	public function isValid(): bool
	{
		return $this->validate() === [];
	}

	/**
	 * Simple DTO for API responses.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$date = $this->getDate();
		$createdAt = $this->getCreatedAt();
		$updatedAt = $this->getUpdatedAt();

		return [
			'id' => $this->getId(),
			'state' => $this->getState(),
			'date' => $date ? $date->format('Y-m-d') : null,
			'name' => $this->getName(),
			'kind' => $this->getKind(),
			'scope' => $this->getScope(),
			'source' => $this->getSource(),
			'createdAt' => $createdAt ? $createdAt->format('c') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
		];
	}
}

