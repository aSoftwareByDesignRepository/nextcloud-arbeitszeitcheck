<?php

declare(strict_types=1);

/**
 * Per-user/year financial revision (SPEC §9.6). Every ledger-touching
 * writer bumps this inside the same atomic unit as its business write;
 * year-end preview binds to it so late source changes are detectable.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method int getCalendarYear()
 * @method void setCalendarYear(int $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 */
class BalanceRevision extends Entity
{
	protected $userId;
	protected $calendarYear;
	protected $revision;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('calendarYear', 'integer');
		$this->addType('revision', 'integer');
	}
}
