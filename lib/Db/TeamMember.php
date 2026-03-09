<?php

declare(strict_types=1);

/**
 * TeamMember entity: assignment of a user to an app team.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTeamId()
 * @method void setTeamId(int $teamId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 */
class TeamMember extends Entity
{
	protected ?int $teamId = null;
	protected ?string $userId = null;

	public function __construct()
	{
		$this->addType('teamId', 'integer');
		$this->addType('userId', 'string');
	}
}
