<?php

declare(strict_types=1);

/**
 * TeamManager entity: assignment of a manager (user) to an app team.
 * Managers can approve/reject absences and time corrections for team members.
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
class TeamManager extends Entity
{
	protected ?int $teamId = null;
	protected ?string $userId = null;

	public function __construct()
	{
		$this->addType('teamId', 'integer');
		$this->addType('userId', 'string');
	}
}
