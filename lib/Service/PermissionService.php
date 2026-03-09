<?php

declare(strict_types=1);

/**
 * Single source of truth for roles and permissions in ArbeitszeitCheck.
 * Used for access control and audit; see docs/ROLES_AND_PERMISSIONS.md.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class PermissionService
{
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly TeamResolverService $teamResolver,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the user is a Nextcloud administrator (admin group).
	 */
	public function isAdmin(string $userId): bool
	{
		return $this->groupManager->isAdmin($userId);
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
		if ($this->groupManager->isAdmin($managerUserId)) {
			return true;
		}
		return $this->teamResolver->canUserManageEmployee($managerUserId, $employeeUserId);
	}

	/**
	 * Whether the user may access the manager dashboard (has at least one team member or is admin).
	 */
	public function canAccessManagerDashboard(string $userId): bool
	{
		if ($this->groupManager->isAdmin($userId)) {
			return true;
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
		if ($this->groupManager->isAdmin($actorUserId)) {
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
