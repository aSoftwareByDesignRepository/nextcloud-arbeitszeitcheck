<?php

declare(strict_types=1);

/**
 * Single source of truth for roles and permissions in ArbeitszeitCheck.
 * Used for access control and audit; see the internal roles/permissions documentation.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class PermissionService
{
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly TeamResolverService $teamResolver,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Returns configured allowlist group IDs (portfolio door).
	 *
	 * Prefers app-config {@see Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS}; falls
	 * back to Nextcloud app restriction for installs that have not saved yet.
	 *
	 * @return list<string>
	 */
	public function getAllowedAccessGroups(): array
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS, ''));
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$groups = [];
				foreach ($decoded as $groupId) {
					$candidate = trim((string)$groupId);
					if ($candidate === '' || isset($groups[$candidate])) {
						continue;
					}
					$groups[$candidate] = true;
				}
				return array_keys($groups);
			}
		}

		$groups = [];
		foreach ($this->appManager->getAppRestriction(Application::APP_ID) as $groupId) {
			$candidate = trim((string)$groupId);
			if ($candidate === '') {
				continue;
			}
			$groups[$candidate] = true;
		}

		return array_keys($groups);
	}

	/**
	 * @return list<string>
	 */
	public function getAllowedAccessUserIds(): array
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, '[]'));
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$unique = [];
		foreach ($decoded as $candidate) {
			$userId = trim((string)$candidate);
			if ($userId === '' || isset($unique[$userId])) {
				continue;
			}
			$unique[$userId] = true;
		}
		return array_keys($unique);
	}

	/**
	 * Directory door: Open vs Restricted (portfolio ACCESS-AND-DIRECTORY-PICKERS §2).
	 * Roles (employee / manager) are enforced separately after the door.
	 */
	public function isAccessRestrictionEnabled(): bool
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, ''));
		if ($raw === '0') {
			return false;
		}
		if ($raw === '1') {
			return true;
		}
		// Legacy: NC / stored group allowlist non-empty ⇒ Restricted.
		return $this->getAllowedAccessGroups() !== [] || $this->getAllowedAccessUserIds() !== [];
	}

	/**
	 * Whether the user may open ArbeitszeitCheck (directory door only).
	 */
	public function isUserAllowedByAccessGroups(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		// System admin OR dedicated app admin always pass the door.
		if ($this->isAdmin($userId)) {
			return true;
		}
		if (!$this->isAccessRestrictionEnabled()) {
			return true;
		}
		if (in_array($userId, $this->getAllowedAccessUserIds(), true)) {
			return true;
		}
		foreach ($this->getAllowedAccessGroups() as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}
		// Restricted + empty allowlists ⇒ fail closed.
		return false;
	}

	/**
	 * Enabled Nextcloud accounts that may open ArbeitszeitCheck (directory door).
	 *
	 * @return list<string>
	 */
	public function listAppAccessUserIds(int $maxScan = Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN): array
	{
		$maxScan = max(1, min($maxScan, Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN));
		/** @var list<\OCP\IUser> $users */
		$users = $this->userManager->search('', $maxScan, 0);
		$unique = [];
		foreach ($users as $user) {
			$userId = (string)$user->getUID();
			if ($userId === '' || isset($unique[$userId])) {
				continue;
			}
			if (!$user->isEnabled()) {
				continue;
			}
			if (!$this->isUserAllowedByAccessGroups($userId)) {
				continue;
			}
			$unique[$userId] = true;
		}

		return array_keys($unique);
	}

	/**
	 * Dedicated App Admin (portfolio ACCESS-AND-DIRECTORY-PICKERS §2.1 / BudgetCheck):
	 * Nextcloud system admin OR listed in app_admin_user_ids.
	 */
	public function isAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		if (!in_array($userId, $this->getConfiguredAppAdminUserIds(), true)) {
			return false;
		}

		// Stale / disabled UIDs must not keep app-admin powers after re-auth.
		$user = $this->userManager->get($userId);
		return $user !== null && $user->isEnabled();
	}

	/**
	 * Returns configured dedicated app admin user IDs.
	 *
	 * Empty list means only Nextcloud admins have app-admin powers (they always pass
	 * via {@see isAdmin}). Non-empty list adds delegated colleagues on top.
	 *
	 * @return list<string>
	 */
	public function getConfiguredAppAdminUserIds(): array
	{
		$raw = trim($this->config->getAppValue(Application::APP_ID, Constants::CONFIG_APP_ADMIN_USER_IDS, '[]'));
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$unique = [];
		foreach ($decoded as $candidate) {
			$userId = trim((string)$candidate);
			if ($userId === '' || isset($unique[$userId])) {
				continue;
			}
			$unique[$userId] = true;
		}

		return array_keys($unique);
	}

	/**
	 * Portfolio §2.1 / user lifecycle: strip deleted UIDs from app-admin and allow lists.
	 */
	public function purgeUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		foreach ([
			Constants::CONFIG_APP_ADMIN_USER_IDS,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
		] as $key) {
			$ids = $key === Constants::CONFIG_APP_ADMIN_USER_IDS
				? $this->getConfiguredAppAdminUserIds()
				: $this->getAllowedAccessUserIds();
			$filtered = array_values(array_filter(
				$ids,
				static fn (string $id): bool => $id !== $userId,
			));
			if ($filtered !== $ids) {
				// These keys are also written via the typed string API by
				// AdminController (setAppValueString) — untyped writes then
				// throw AppConfigTypeConflictException (NC >= 34); fall back typed.
				\OCA\ArbeitszeitCheck\Support\TypedAppConfigWrite::setString(
					$this->config,
					Application::APP_ID,
					$key,
					json_encode($filtered, JSON_THROW_ON_ERROR),
				);
			}
		}
	}

	/**
	 * Whether the actor may perform manager actions (approve/reject absences, time corrections,
	 * view reports/compliance) for the given employee.
	 * True if actor is admin or is in a team with the employee (same group).
	 */
	public function canManageEmployee(string $managerUserId, string $employeeUserId): bool
	{
		if ($managerUserId === $employeeUserId) {
			return false;
		}
		if ($this->isAdmin($managerUserId)) {
			return true;
		}
		// Security hardening: manager-level access to sensitive employee data requires
		// explicit app-team manager assignments. Legacy group-based inference is too broad.
		if (!$this->teamResolver->useAppTeams()) {
			return false;
		}
		return $this->teamResolver->canUserManageEmployee($managerUserId, $employeeUserId);
	}

	/**
	 * Whether the user may access the manager dashboard (has at least one team member or is admin).
	 */
	public function canAccessManagerDashboard(string $userId): bool
	{
		if ($this->isAdmin($userId)) {
			return true;
		}
		// Security hardening: manager area requires explicit app-team manager assignments.
		if (!$this->teamResolver->useAppTeams()) {
			return false;
		}
		$teamMemberIds = $this->teamResolver->getTeamMemberIds($userId);
		return count($teamMemberIds) > 0;
	}

	/**
	 * Whether the viewer may access the target user's report (self, admin, or manager for target).
	 */
	public function canViewUserReport(string $viewerUserId, string $targetUserId): bool
	{
		if ($viewerUserId === $targetUserId) {
			return true;
		}
		return $this->canManageEmployee($viewerUserId, $targetUserId);
	}

	/**
	 * Whether the viewer may access the target user's compliance data (self, admin, or manager for target).
	 */
	public function canViewUserCompliance(string $viewerUserId, string $targetUserId): bool
	{
		if ($viewerUserId === $targetUserId) {
			return true;
		}
		return $this->canManageEmployee($viewerUserId, $targetUserId);
	}

	/**
	 * Whether the actor may resolve a compliance violation for the given violation owner.
	 * True if actor is admin or can manage the employee (team).
	 */
	public function canResolveViolation(string $actorUserId, string $violationOwnerUserId): bool
	{
		if ($this->isAdmin($actorUserId)) {
			return true;
		}
		return $this->canManageEmployee($actorUserId, $violationOwnerUserId);
	}

	/**
	 * Log a permission denial for audit. Call when returning 403 so the attempt is traceable.
	 */
	public function logPermissionDenied(string $actorUserId, string $action, string $resourceType, ?string $resourceId = null): void
	{
		$this->logger->warning('Permission denied', [
			'actor' => $actorUserId,
			'action' => $action,
			'resource_type' => $resourceType,
			'resource_id' => $resourceId,
		]);
	}
}
