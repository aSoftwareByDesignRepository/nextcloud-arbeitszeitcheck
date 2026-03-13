<?php

declare(strict_types=1);

/**
 * Resolves team membership for manager approval checks.
 * When app teams are enabled (use_app_teams config): uses at_teams, at_team_members, at_team_managers.
 * Otherwise: uses Nextcloud groups (same-group members). Admins are always allowed via PermissionService.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

class TeamResolverService
{
	private const APP_ID = 'arbeitszeitcheck';
	private const USE_APP_TEAMS_KEY = 'use_app_teams';

	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IConfig $config,
		private readonly TeamMapper $teamMapper,
		private readonly TeamMemberMapper $teamMemberMapper,
		private readonly TeamManagerMapper $teamManagerMapper,
	) {
	}

	/**
	 * Whether manager resolution uses app-owned teams (DB) instead of Nextcloud groups.
	 */
	public function useAppTeams(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::USE_APP_TEAMS_KEY, '0') === '1';
	}

	/**
	 * Get user IDs that the given user can act on as "manager" (team members, excluding self).
	 * If use_app_teams: members of all teams (and descendant teams) where user is a manager.
	 * Else: same-group members (legacy).
	 *
	 * @param string $managerUserId Current user ID (the potential approver)
	 * @return list<string> User IDs of team members
	 */
	public function getTeamMemberIds(string $managerUserId): array
	{
		if ($this->useAppTeams()) {
			try {
				return $this->getTeamMemberIdsFromAppTeams($managerUserId);
			} catch (\Throwable $e) {
				// at_teams/at_team_members/at_team_managers may not exist if migration hasn't run
				$msg = $e->getMessage();
				if (str_contains($msg, "doesn't exist") || str_contains($msg, 'at_team')) {
					return [];
				}
				throw $e;
			}
		}
		return $this->getTeamMemberIdsFromGroups($managerUserId);
	}

	/**
	 * Team members from app-owned teams: all teams (incl. descendants) where user is manager.
	 *
	 * @return list<string>
	 */
	private function getTeamMemberIdsFromAppTeams(string $managerUserId): array
	{
		$managedTeamIds = $this->teamManagerMapper->getTeamIdsForManager($managerUserId);
		if (empty($managedTeamIds)) {
			return [];
		}
		$allTeamIds = [];
		foreach ($managedTeamIds as $tid) {
			foreach ($this->teamMapper->getIdsWithDescendants($tid) as $id) {
				$allTeamIds[$id] = true;
			}
		}
		$teamIds = array_keys($allTeamIds);
		$memberIds = $this->teamMemberMapper->getMemberUserIdsByTeamIds($teamIds);
		$result = [];
		foreach ($memberIds as $uid) {
			if ($uid !== $managerUserId && !in_array($uid, $result, true)) {
				$result[] = $uid;
			}
		}
		return $result;
	}

	/**
	 * Legacy: team members as same Nextcloud group members.
	 *
	 * @return list<string>
	 */
	private function getTeamMemberIdsFromGroups(string $managerUserId): array
	{
		$manager = $this->userManager->get($managerUserId);
		if ($manager === null) {
			return [];
		}
		$managerGroups = $this->groupManager->getUserGroups($manager);
		if (empty($managerGroups)) {
			return [];
		}
		$teamMemberIds = [];
		foreach ($managerGroups as $group) {
			$groupUsers = $group->getUsers();
			foreach ($groupUsers as $user) {
				$userId = $user->getUID();
				if ($userId !== $managerUserId && !in_array($userId, $teamMemberIds, true)) {
					$teamMemberIds[] = $userId;
				}
			}
		}
		return $teamMemberIds;
	}

	/**
	 * Get colleague user IDs (users in same team/group) for substitute selection.
	 * Used when an employee selects who will cover for them during absence (Vertretung).
	 * If use_app_teams: members of all teams where user is a member or a manager (excluding self).
	 * Else: same Nextcloud group members (excluding self).
	 *
	 * @param string $userId Current user ID (the employee creating the absence)
	 * @return list<string> User IDs of colleagues who can be selected as substitute
	 */
	public function getColleagueIds(string $userId): array
	{
		$useApp = $this->useAppTeams();
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleagueIds userId=' . $userId . ' useAppTeams=' . ($useApp ? '1' : '0'),
			['app' => 'arbeitszeitcheck']
		);
		if ($useApp) {
			try {
				$ids = $this->getColleagueIdsFromAppTeams($userId);
				\OCP\Log\logger('arbeitszeitcheck')->debug(
					'[Vertretung] getColleagueIdsFromAppTeams returned ' . count($ids) . ' ids',
					['app' => 'arbeitszeitcheck', 'ids' => $ids]
				);
				return $ids;
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'[Vertretung] getColleagueIdsFromAppTeams exception: ' . $e->getMessage(),
					['app' => 'arbeitszeitcheck', 'exception' => $e]
				);
				return [];
			}
		}
		try {
			$ids = $this->getTeamMemberIdsFromGroups($userId);
			\OCP\Log\logger('arbeitszeitcheck')->debug(
				'[Vertretung] getTeamMemberIdsFromGroups returned ' . count($ids) . ' ids',
				['app' => 'arbeitszeitcheck', 'ids' => $ids]
			);
			return $ids;
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'[Vertretung] getTeamMemberIdsFromGroups exception: ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
			return [];
		}
	}

	/**
	 * Colleagues from app-owned teams: all members of teams where user is a member or a manager.
	 * This ensures users who are only managers (not members) still see team members as substitute options.
	 *
	 * @return list<string>
	 */
	private function getColleagueIdsFromAppTeams(string $userId): array
	{
		$teamIds = [];
		$memberTeams = $this->teamMemberMapper->findByUserId($userId);
		foreach ($memberTeams as $m) {
			$teamIds[$m->getTeamId()] = true;
		}
		$managerTeamIds = $this->teamManagerMapper->getTeamIdsForManager($userId);
		foreach ($managerTeamIds as $tid) {
			foreach ($this->teamMapper->getIdsWithDescendants($tid) as $id) {
				$teamIds[$id] = true;
			}
		}
		$teamIds = array_keys($teamIds);
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleagueIdsFromAppTeams teamIds=' . json_encode($teamIds) . ' (memberCount=' . count($memberTeams) . ', managerTeamCount=' . count($managerTeamIds) . ')',
			['app' => 'arbeitszeitcheck']
		);
		if (empty($teamIds)) {
			return [];
		}
		$memberIds = $this->teamMemberMapper->getMemberUserIdsByTeamIds($teamIds);
		$result = [];
		foreach ($memberIds as $uid) {
			if ($uid !== $userId && !in_array($uid, $result, true)) {
				$result[] = $uid;
			}
		}
		return $result;
	}

	/**
	 * Whether the given user can approve/reject resources (absences, time entry corrections) for the given employee.
	 */
	public function canUserManageEmployee(string $approverUserId, string $employeeUserId): bool
	{
		if ($approverUserId === $employeeUserId) {
			return false;
		}
		$teamIds = $this->getTeamMemberIds($approverUserId);
		return in_array($employeeUserId, $teamIds, true);
	}

	/**
	 * Get manager (Vorgesetzte) user IDs for a given employee.
	 * Only available when app-owned teams are enabled. For legacy group-based
	 * setups this returns an empty list, because groups do not model explicit managers.
	 *
	 * @param string $employeeUserId
	 * @return list<string>
	 */
	public function getManagerIdsForEmployee(string $employeeUserId): array
	{
		if (!$this->useAppTeams()) {
			return [];
		}

		try {
			$memberships = $this->teamMemberMapper->findByUserId($employeeUserId);
			if (empty($memberships)) {
				return [];
			}

			$teamIds = [];
			foreach ($memberships as $membership) {
				$teamIds[] = $membership->getTeamId();
			}
			if (empty($teamIds)) {
				return [];
			}

			$managerIds = [];
			foreach ($teamIds as $teamId) {
				$managers = $this->teamManagerMapper->findByTeamId($teamId);
				foreach ($managers as $manager) {
					$uid = $manager->getUserId();
					if ($uid !== $employeeUserId && !in_array($uid, $managerIds, true)) {
						$managerIds[] = $uid;
					}
				}
			}

			return $managerIds;
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (str_contains($msg, "doesn't exist") || str_contains($msg, 'at_team')) {
				return [];
			}
			throw $e;
		}
	}
}
