<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

class WorkLocationDefinition extends Entity
{
	protected $code;
	protected $baseLabel;
	protected $labelI18n;
	protected $reportingCategory;
	protected $color;
	protected $icon;
	protected $active;
	protected $builtIn;
	protected $version;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('code', 'string');
		$this->addType('baseLabel', 'string');
		$this->addType('labelI18n', 'string');
		$this->addType('reportingCategory', 'string');
		$this->addType('color', 'string');
		$this->addType('icon', 'string');
		$this->addType('active', 'boolean');
		$this->addType('builtIn', 'boolean');
		$this->addType('version', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('updatedBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
