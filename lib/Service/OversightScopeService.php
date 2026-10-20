<?php

declare(strict_types=1);

/**
 * Single authority for HR/manager "oversight" read scopes.
 *
 * The historical bug this fixes: every manager-scoped surface resolved its
 * user set via TeamResolverService::getTeamMemberIds($actor), which returns
 * [] for app admins that manage no team — although those admins are
 * authorized to read (and even approve) everyone. The result: empty pending
 * queues, empty overviews, and exports containing only the caller's own
 * rows for admins/HR.
 *
 * Contract:
 *  - admin (system admin or dedicated app admin)  → organization scope:
 *    all enabled users that pass the app access door
 *    (PermissionService::listAppAccessUserIds).
 *  - manager                                     → managed team scope:
 *    TeamResolverService::getTeamMemberIds (raw; callers that render
 *    people must still skip disabled/deleted rows themselves).
 *  - anyone else                                 → empty scope.
 *
 * Request-scoped selectors (userId / userIds / teamId / scope) are resolved
 * by resolveRequestedScope() which fails closed: unknown values, ambiguous
 * combinations, out-of-scope ids and ghost users are all rejected, and
 * "not found" vs "not yours" collapse into one generic denial for
 * non-admin actors (no existence oracle).
 *
 * TeamResolverService itself is intentionally NOT widened: its
 * empty-scope-means-nothing contract also feeds write paths, where an
 * admin fallback to "all" would be dangerous.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCP\IUserManager;

final class OversightScopeService
{
	public const SCOPE_SELF = 'self';
	public const SCOPE_MANAGED = 'managed';
	public const SCOPE_ORGANIZATION = 'organization';

	/** Cap for explicit `userIds` selector lists (CSV). */
	public const MAX_EXPLICIT_USER_IDS = 200;

	// Machine-readable scope resolution error codes. Controllers map them
	// to HTTP status + a generic translated message.
	public const ERR_AMBIGUOUS = 'ambiguous_scope';
	public const ERR_INVALID_SCOPE = 'invalid_scope_value';
	public const ERR_INVALID_TEAM_ID = 'invalid_team_id';
	public const ERR_INVALID_USER_IDS = 'invalid_user_ids';
	public const ERR_TOO_MANY_USERS = 'too_many_users';
	public const ERR_DENIED = 'scope_denied';

	public function __construct(
		private readonly PermissionService $permissionService,
		private readonly TeamResolverService $teamResolver,
		private readonly TeamMapper $teamMapper,
		private readonly TeamMemberMapper $teamMemberMapper,
		private readonly TeamManagerMapper $teamManagerMapper,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * User IDs the actor may oversee on manager read surfaces.
	 *
	 * Admin → all enabled users that pass the app access door (the org).
	 * Manager → managed team member ids (raw resolver result; may contain
	 * stale uids — callers rendering people skip non-existing users).
	 * Everyone else → [].
	 *
	 * @return list<string>
	 */
	public function managedUserIds(string $actorUserId): array
	{
		if ($actorUserId === '') {
			return [];
		}
		if ($this->permissionService->isAdmin($actorUserId)) {
			return $this->permissionService->listAppAccessUserIds();
		}
		if (!$this->permissionService->canAccessManagerDashboard($actorUserId)) {
			return [];
		}
		return $this->teamResolver->getTeamMemberIds($actorUserId);
	}

	/**
	 * {@see managedUserIds()} restricted to existing, enabled accounts —
	 * the set that may appear on people-overview / picker surfaces.
	 *
	 * @return list<string>
	 */
	public function managedEnabledUserIds(string $actorUserId): array
	{
		return $this->filterExistingEnabled($this->managedUserIds($actorUserId));
	}

	/**
	 * Whether the actor may read oversight data (absences, compliance,
	 * vacation/overtime balances, exports) for the target user.
	 *
	 * Requires account existence — deleted users and stale member-row ghost
	 * uids never pass — plus self / admin / managed-membership. Disabled
	 * accounts stay selectable on purpose: offboarding payroll and HR
	 * exports legitimately cover not-yet-deactivated employees, matching
	 * canViewUserReport semantics.
	 */
	public function canOverseeUser(string $actorUserId, string $targetUserId): bool
	{
		if ($actorUserId === '' || $targetUserId === '') {
			return false;
		}
		if ($this->userManager->get($targetUserId) === null) {
			return false;
		}
		if ($actorUserId === $targetUserId) {
			return true;
		}
		if ($this->permissionService->isAdmin($actorUserId)) {
			return true;
		}
		if (!$this->permissionService->canAccessManagerDashboard($actorUserId)) {
			return false;
		}
		return in_array($targetUserId, $this->teamResolver->getTeamMemberIds($actorUserId), true);
	}

	/**
	 * Resolve request scope selectors into a concrete user-id list.
	 *
	 * Selectors (all optional, mutually exclusive):
	 *  - `userId`  single user id — must be overseeable by the actor
	 *  - `userIds` CSV of user ids — every id must be overseeable (atomic:
	 *    one unauthorized or unknown id rejects the whole request)
	 *  - `teamId`  app-team id — admin: any existing team; manager: a team
	 *    inside the actor's managed subtree; members resolve to the team
	 *    and all its descendants, intersected with the actor's scope
	 *  - `scope`   enum: `self` | `managed` | `organization` (admin only)
	 *
	 * @return array{
	 *     ok: bool,
	 *     code: ?string,
	 *     userIds: ?list<string>,
	 *     scope: string
	 * } `userIds === null` means "no selector supplied" — the caller applies
	 *   its own default (usually self-scope).
	 */
	public function resolveRequestedScope(
		string $actorUserId,
		?string $userId = null,
		?string $userIds = null,
		?string $teamId = null,
		?string $scope = null,
	): array {
		$actorUserId = trim($actorUserId);
		if ($actorUserId === '') {
			return $this->denied(self::SCOPE_SELF);
		}

		$selectors = 0;
		foreach ([$userId, $userIds, $teamId, $scope] as $param) {
			if ($param !== null && trim($param) !== '') {
				$selectors++;
			}
		}
		if ($selectors > 1) {
			return ['ok' => false, 'code' => self::ERR_AMBIGUOUS, 'userIds' => null, 'scope' => ''];
		}
		if ($selectors === 0) {
			return ['ok' => true, 'code' => null, 'userIds' => null, 'scope' => ''];
		}

		if ($scope !== null && trim($scope) !== '') {
			return $this->resolveNamedScope($actorUserId, trim($scope));
		}
		if ($teamId !== null && trim($teamId) !== '') {
			return $this->resolveTeamScope($actorUserId, trim($teamId));
		}
		if ($userIds !== null && trim($userIds) !== '') {
			return $this->resolveUserIdsList($actorUserId, $userIds);
		}

		// Single userId selector.
		$target = trim((string)$userId);
		if (!$this->canOverseeUser($actorUserId, $target)) {
			return $this->denied(self::SCOPE_MANAGED);
		}
		return ['ok' => true, 'code' => null, 'userIds' => [$target], 'scope' => self::SCOPE_MANAGED];
	}

	/**
	 * @return array{ok: bool, code: ?string, userIds: ?list<string>, scope: string}
	 */
	private function resolveNamedScope(string $actorUserId, string $scope): array
	{
		switch ($scope) {
			case self::SCOPE_SELF:
				return ['ok' => true, 'code' => null, 'userIds' => [$actorUserId], 'scope' => self::SCOPE_SELF];
			case self::SCOPE_MANAGED:
				if (!$this->permissionService->isAdmin($actorUserId)
					&& !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
					return $this->denied($scope);
				}
				return ['ok' => true, 'code' => null, 'userIds' => $this->managedUserIds($actorUserId), 'scope' => $scope];
			case self::SCOPE_ORGANIZATION:
				if (!$this->permissionService->isAdmin($actorUserId)) {
					return $this->denied($scope);
				}
				return [
					'ok' => true,
					'code' => null,
					'userIds' => $this->permissionService->listAppAccessUserIds(),
					'scope' => $scope,
				];
		}
		return ['ok' => false, 'code' => self::ERR_INVALID_SCOPE, 'userIds' => null, 'scope' => ''];
	}

	/**
	 * @return array{ok: bool, code: ?string, userIds: ?list<string>, scope: string}
	 */
	private function resolveUserIdsList(string $actorUserId, string $raw): array
	{
		$ids = [];
		foreach (explode(',', $raw) as $piece) {
			$piece = trim($piece);
			if ($piece !== '' && !in_array($piece, $ids, true)) {
				$ids[] = $piece;
			}
		}
		if ($ids === []) {
			return ['ok' => false, 'code' => self::ERR_INVALID_USER_IDS, 'userIds' => null, 'scope' => ''];
		}
		if (count($ids) > self::MAX_EXPLICIT_USER_IDS) {
			return ['ok' => false, 'code' => self::ERR_TOO_MANY_USERS, 'userIds' => null, 'scope' => ''];
		}
		$isAdmin = $this->permissionService->isAdmin($actorUserId);
		$managed = null;
		foreach ($ids as $uid) {
			if ($this->userManager->get($uid) === null) {
				return $this->denied(self::SCOPE_MANAGED);
			}
			if ($uid === $actorUserId || $isAdmin) {
				continue;
			}
			if (!$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				return $this->denied(self::SCOPE_MANAGED);
			}
			$managed ??= array_flip($this->teamResolver->getTeamMemberIds($actorUserId));
			if (!isset($managed[$uid])) {
				return $this->denied(self::SCOPE_MANAGED);
			}
		}
		return ['ok' => true, 'code' => null, 'userIds' => $ids, 'scope' => self::SCOPE_MANAGED];
	}

	/**
	 * @return array{ok: bool, code: ?string, userIds: ?list<string>, scope: string}
	 */
	private function resolveTeamScope(string $actorUserId, string $teamIdRaw): array
	{
		if (!preg_match('/^\d+$/', $teamIdRaw)) {
			return ['ok' => false, 'code' => self::ERR_INVALID_TEAM_ID, 'userIds' => null, 'scope' => ''];
		}
		$teamId = (int)$teamIdRaw;
		$isAdmin = $this->permissionService->isAdmin($actorUserId);

		try {
			if ($isAdmin) {
				// Admin may select any existing team; nonexistent collapses to
				// the same denial to keep responses uniform.
				$this->teamMapper->find($teamId);
			} else {
				if (!$this->permissionService->canAccessManagerDashboard($actorUserId)) {
					return $this->denied('team');
				}
				$managedSubtree = [];
				foreach ($this->teamManagerMapper->getTeamIdsForManager($actorUserId) as $managedId) {
					foreach ($this->teamMapper->getIdsWithDescendants($managedId) as $id) {
						$managedSubtree[$id] = true;
					}
				}
				if (!isset($managedSubtree[$teamId])) {
					// Foreign or nonexistent team — same denial either way
					// (no existence oracle for non-admin actors).
					return $this->denied('team');
				}
			}
			$memberIds = $this->teamMemberMapper->getMemberUserIdsByTeamIds(
				$this->teamMapper->getIdsWithDescendants($teamId)
			);
		} catch (\Throwable $e) {
			return $this->denied('team');
		}

		$memberIds = $this->filterExisting($memberIds);
		if (!$isAdmin) {
			// Defense in depth: even if descendant/member resolution ever
			// disagreed with managedUserIds(), a manager can never receive
			// uids outside their managed scope.
			$managed = array_flip($this->teamResolver->getTeamMemberIds($actorUserId));
			$memberIds = array_values(array_filter(
				$memberIds,
				static fn (string $uid) => isset($managed[$uid])
			));
		}
		return ['ok' => true, 'code' => null, 'userIds' => array_values($memberIds), 'scope' => 'team'];
	}

	/**
	 * @return array{ok: false, code: string, userIds: null, scope: string}
	 */
	private function denied(string $scope): array
	{
		return ['ok' => false, 'code' => self::ERR_DENIED, 'userIds' => null, 'scope' => $scope];
	}

	private function isExistingEnabled(string $userId): bool
	{
		$user = $this->userManager->get($userId);
		return $user !== null && $user->isEnabled();
	}

	/**
	 * @param list<string> $userIds
	 * @return list<string>
	 */
	private function filterExistingEnabled(array $userIds): array
	{
		$out = [];
		foreach ($userIds as $uid) {
			if ($this->isExistingEnabled($uid)) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/**
	 * Existing (not deleted) accounts — disabled users stay selectable for
	 * legitimate offboarding/reporting needs, deleted users never resolve.
	 *
	 * @param list<string> $userIds
	 * @return list<string>
	 */
	private function filterExisting(array $userIds): array
	{
		$out = [];
		foreach ($userIds as $uid) {
			if ($this->userManager->get($uid) !== null) {
				$out[] = $uid;
			}
		}
		return $out;
	}
}
