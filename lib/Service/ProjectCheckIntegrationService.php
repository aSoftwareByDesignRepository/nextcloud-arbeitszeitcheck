<?php

declare(strict_types=1);

/**
 * ProjectCheck integration service for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Service for integrating with ProjectCheck app
 */
class ProjectCheckIntegrationService
{
	/** @see \OCA\ProjectCheck\Util\CostRateMode::PROJECT_MEMBER */
	private const COST_RATE_MODE_PROJECT_MEMBER = 'project_member';

	/**
	 * @deprecated Per-user toggle removed; use {@see Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED}.
	 *             Kept only so legacy rows in user_settings are ignored, not read.
	 */
	public const SETTING_LINK_ENABLED = 'projectcheck_link_enabled';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IDBConnection $db,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?object $projectCheckProjectService = null,
		private readonly ?object $projectCheckTimeEntryService = null,
	) {
	}

	/**
	 * Whether ProjectCheck is enabled on this Nextcloud instance.
	 *
	 * Uses {@see IAppManager::isInstalled()} (enabled on the server), not
	 * {@see IAppManager::isEnabledForUser()}. Admins who are outside a
	 * "limit to groups" restriction must still be able to turn the org-wide
	 * connection on. Background jobs have no current user.
	 */
	public function isProjectCheckAvailable(): bool
	{
		return $this->appManager->isInstalled(Constants::APP_ID_PROJECTCHECK) === true;
	}

	/**
	 * Whether ProjectCheck is enabled for this specific person (group limits).
	 *
	 * @param \OCP\IUser|null $user Null = currently logged-in user
	 */
	public function isProjectCheckEnabledForUser(?\OCP\IUser $user = null): bool
	{
		if (!$this->isProjectCheckAvailable()) {
			return false;
		}
		return $this->appManager->isEnabledForUser(Constants::APP_ID_PROJECTCHECK, $user) === true;
	}

	/**
	 * Whether an administrator has enabled the ArbeitszeitCheck ↔ ProjectCheck
	 * connection for everyone on this server.
	 */
	public function isAdminIntegrationEnabled(): bool
	{
		if (!$this->isProjectCheckAvailable()) {
			return false;
		}
		return $this->appConfig->getAppValueString(
			Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED,
			Constants::CONFIG_PROJECTCHECK_INTEGRATION_DEFAULT,
		) === '1';
	}

	/**
	 * Whether ProjectCheck linking is active for this user's *own* time
	 * (clock-in picker and manual time-entry picker / linking).
	 *
	 * Governed solely by the admin global toggle
	 * ({@see Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED}) plus
	 * {@see self::isProjectCheckAvailable()}. Per-project access checks in
	 * {@see self::userMayAttachProjectCheckProjectToOwnTime()} still apply.
	 *
	 * Also governs manager/HR on-behalf assignment
	 * ({@see self::managerMayAttachProjectCheckProjectForEmployee()}).
	 */
	public function isLinkingEnabledForUser(string $userId): bool
	{
		unset($userId);
		return $this->isAdminIntegrationEnabled();
	}

	/**
	 * Safely read a (possibly magic) string getter from a foreign entity.
	 *
	 * ProjectCheck entities expose their columns through the Nextcloud
	 * {@see \OCP\AppFramework\Db\Entity} `__call()` magic, for which
	 * `method_exists()` returns false. `is_callable()` honours `__call()`, so it
	 * is the correct probe for loosely-coupled cross-app objects.
	 */
	private function readStringGetter(object $obj, string $method): string
	{
		if (!is_callable([$obj, $method])) {
			return '';
		}
		try {
			$value = $obj->{$method}();
		} catch (\Throwable $e) {
			$this->logger->debug(sprintf('ProjectCheck entity %s() failed: %s', $method, $e->getMessage()));
			return '';
		}
		return $value === null ? '' : (string)$value;
	}

	/**
	 * Optional ProjectCheck ProjectService when the app is present (injected from the server container).
	 */
	private function projectService(): ?object
	{
		return $this->projectCheckProjectService;
	}

	/**
	 * Projects the current user may link to their own ArbeitszeitCheck time entries.
	 *
	 * Rules (aligned with ProjectCheck billing):
	 * - Project must exist, be visible per ProjectCheck access (member, creator, or org/system admin),
	 *   and accept new time (Active / On Hold).
	 * - If pricing is "per person on the project" ({@see self::COST_RATE_MODE_PROJECT_MEMBER}), the user
	 *   must be an active team member with a resolvable rate path in ProjectCheck — not merely an admin
	 *   browsing the project.
	 *
	 * @return list<array{id: string, name: string, customerId: int|null, customerName: string, displayName: string, costRateMode: string}>
	 */
	public function getAvailableProjects(string $userId): array
	{
		if (!$this->isLinkingEnabledForUser($userId)) {
			return [];
		}

		$svc = $this->projectService();
		if ($svc === null) {
			$this->logger->warning('ProjectCheck is enabled but ProjectService is not available to ArbeitszeitCheck; project picker will be empty.');
			return [];
		}

		try {
			if (!is_callable([$svc, 'getProjectsForUserTimeEntry'])) {
				return [];
			}
			/** @var list<object> $projects */
			$projects = $svc->getProjectsForUserTimeEntry($userId, [
				'status' => ['Active', 'On Hold'],
				'sort' => 'name',
				'direction' => 'ASC',
			]);
			$out = [];
			foreach ($projects as $p) {
				if (!is_object($p)) {
					continue;
				}
				if (!is_callable([$p, 'allowsTimeTracking']) || !$p->allowsTimeTracking()) {
					continue;
				}
				$pid = is_callable([$p, 'getId']) ? (int)$p->getId() : 0;
				if ($pid <= 0) {
					continue;
				}
				$mode = $this->readStringGetter($p, 'getCostRateMode');
				if ($mode === self::COST_RATE_MODE_PROJECT_MEMBER) {
					if (!is_callable([$svc, 'isActiveTeamMember']) || !$svc->isActiveTeamMember($pid, $userId)) {
						continue;
					}
				}
				$customerName = $this->readStringGetter($p, 'getCustomerName');
				$name = $this->readStringGetter($p, 'getName');
				$customerId = is_callable([$p, 'getCustomerId']) ? $p->getCustomerId() : null;
				if ($name === '') {
					$fromDb = $this->getProjectDetails((string)$pid);
					if ($fromDb !== null) {
						$name = trim((string)($fromDb['name'] ?? ''));
						if ($customerName === '' && isset($fromDb['customerName']) && $fromDb['customerName'] !== null) {
							$customerName = trim((string)$fromDb['customerName']);
						}
					}
				}
				if ($name === '') {
					$this->logger->warning('Skipping ProjectCheck project with empty name in picker.', ['projectId' => $pid]);
					continue;
				}
				$display = $customerName !== ''
					? sprintf('%s (%s)', $name, $customerName)
					: $name;
				$out[] = [
					'id' => (string)$pid,
					'name' => $name,
					'customerId' => $customerId !== null ? (int)$customerId : null,
					'customerName' => $customerName !== '' ? $customerName : $this->l10n->t('No Customer'),
					'displayName' => $display,
					'costRateMode' => $mode,
				];
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load projects from ProjectCheck: ' . $e->getMessage(), ['exception' => $e]);
			return [];
		}
	}
	/**
	 * Projects a manager may assign when creating or correcting an employee's ArbeitszeitCheck entry.
	 *
	 * @return list<array{id: string, name: string, customerId: int|null, customerName: string, displayName: string, costRateMode: string}>
	 */
	public function getAssignableProjectsForManagerOnBehalfOfEmployee(string $managerUserId, string $employeeUserId): array
	{
		if (!$this->isProjectCheckAvailable()) {
			return [];
		}
		$svc = $this->projectService();
		if ($svc === null || !\is_callable([$svc, 'mayBillArbeitszeitCheckTimeForUser'])) {
			return [];
		}

		$out = [];
		foreach ($this->getAvailableProjects($employeeUserId) as $row) {
			$pid = (int)$row['id'];
			if ($pid <= 0) {
				continue;
			}
			try {
				if ($svc->mayBillArbeitszeitCheckTimeForUser($managerUserId, $employeeUserId, $pid)) {
					$out[] = $row;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Skipping project for manager assign list: ' . $e->getMessage());
			}
		}
		return $out;
	}

	/**
	 * Whether a manager may attach this ProjectCheck project when writing time for an employee.
	 */
	public function managerMayAttachProjectCheckProjectForEmployee(string $managerUserId, string $employeeUserId, string $projectIdStr): bool
	{
		if (!$this->isProjectCheckAvailable() || !$this->isAdminIntegrationEnabled()) {
			return false;
		}
		$pid = (int)trim($projectIdStr);
		if ($pid <= 0) {
			return false;
		}
		$svc = $this->projectService();
		if ($svc === null || !\is_callable([$svc, 'mayBillArbeitszeitCheckTimeForUser'])) {
			return false;
		}
		try {
			return (bool)$svc->mayBillArbeitszeitCheckTimeForUser($managerUserId, $employeeUserId, $pid);
		} catch (\Throwable $e) {
			$this->logger->warning('Manager ProjectCheck attach validation failed: ' . $e->getMessage(), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Whether the user may attach this ProjectCheck project ID to their own time entry (clock-in or manual).
	 *
	 * Uses ProjectCheck's {@see \OCA\ProjectCheck\Service\ProjectService::canUserAddTimeEntryForProject}
	 * when available so the attach gate matches the picker ({@see getAvailableProjects}).
	 */
	public function userMayAttachProjectCheckProjectToOwnTime(string $userId, string $projectIdStr): bool
	{
		if (!$this->isProjectCheckAvailable()) {
			return false;
		}
		if (!$this->isLinkingEnabledForUser($userId)) {
			return false;
		}
		$pid = (int)trim($projectIdStr);
		if ($pid <= 0) {
			return false;
		}
		$svc = $this->projectService();
		if ($svc === null) {
			// Fail closed: without ProjectCheck's ProjectService we cannot verify
			// team membership, pricing mode, or project status — never trust a raw id.
			$this->logger->warning('ProjectCheck attach rejected: ProjectService unavailable to ArbeitszeitCheck.');
			return false;
		}
		try {
			// Prefer the same gate the ProjectCheck create-form / picker uses.
			if (is_callable([$svc, 'canUserAddTimeEntryForProject'])) {
				return (bool)$svc->canUserAddTimeEntryForProject($userId, $pid);
			}
			if (!is_callable([$svc, 'getProject'])) {
				$this->logger->warning('ProjectCheck attach rejected: ProjectService has no getProject.');
				return false;
			}
			$project = $svc->getProject($pid);
			if ($project === null || !is_callable([$project, 'allowsTimeTracking']) || !$project->allowsTimeTracking()) {
				return false;
			}
			$mode = $this->readStringGetter($project, 'getCostRateMode');
			if ($mode === self::COST_RATE_MODE_PROJECT_MEMBER) {
				return is_callable([$svc, 'isActiveTeamMember']) && $svc->isActiveTeamMember($pid, $userId);
			}
			return is_callable([$svc, 'canUserAccessProject']) && $svc->canUserAccessProject($userId, $pid);
		} catch (\Throwable $e) {
			$this->logger->warning('ProjectCheck attach validation failed: ' . $e->getMessage(), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Get project details from ProjectCheck
	 *
	 * @return array<string, mixed>|null
	 */
	public function getProjectDetails(string $projectId): ?array
	{
		if (!$this->isProjectCheckAvailable()) {
			return null;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select(['p.*', 'c.name as customer_name'])
				->from('pc_projects', 'p')
				->leftJoin('p', 'pc_customers', 'c', $query->expr()->eq('p.customer_id', 'c.id'))
				->where($query->expr()->eq('p.id', $query->createNamedParameter((int)$projectId, IQueryBuilder::PARAM_INT)));

			$result = $query->executeQuery();
			$project = $result->fetch();

			$result->closeCursor();

			if ($project) {
				return [
					'id' => (string)$project['id'],
					'name' => $project['name'],
					'description' => $project['short_description'] ?? $project['detailed_description'] ?? '',
					'customerId' => $project['customer_id'],
					'customerName' => $project['customer_name'],
					'status' => $project['status'],
					'budget' => $project['total_budget'] ?? 0,
					'hourlyRate' => $project['hourly_rate'] ?? 0,
					'startDate' => $project['start_date'] ?? null,
					'endDate' => $project['end_date'] ?? null,
					'costRateMode' => $project['cost_rate_mode'] ?? null,
				];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load project details from ProjectCheck: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get time entries from ProjectCheck for a project (for migration/comparison).
	 *
	 * @deprecated Not used by production code; retained behind a try/catch for
	 *             future use cases. The shape of the returned array mirrors the
	 *             projectcheck schema, which is not part of this app's public
	 *             contract and may change without notice.
	 * @internal
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getProjectCheckTimeEntries(string $projectId): array
	{
		if (!$this->isProjectCheckAvailable()) {
			return [];
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('*')
				->from('pc_time_entries')
				->where($query->expr()->eq('project_id', $query->createNamedParameter((int)$projectId, IQueryBuilder::PARAM_INT)))
				->orderBy('date', 'DESC');

			$result = $query->executeQuery();
			$entries = [];

			while ($row = $result->fetch()) {
				$entries[] = [
					'id' => $row['id'],
					'projectId' => $row['project_id'],
					'userId' => $row['user_id'],
					'date' => $row['date'],
					'hours' => $row['hours'],
					'description' => $row['description'],
					'hourlyRate' => $row['hourly_rate'],
					'createdAt' => $row['created_at'],
					'source' => 'projectcheck',
				];
			}

			$result->closeCursor();

			return $entries;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load time entries from ProjectCheck: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Sync time entries between ArbeitszeitCheck and ProjectCheck.
	 *
	 * @deprecated The implementation references `hours` and `hourly_rate`
	 *             columns which do not exist on the `at_entries` schema, so
	 *             this method cannot succeed against a real database. It is
	 *             kept solely to preserve the historical contract for the
	 *             `ProjectCheckIntegrationServiceTest` mocks. Do NOT call
	 *             from production code.
	 * @internal
	 *
	 * @return array<string, mixed>
	 */
	public function syncTimeEntriesToProjectCheck(string $userId, ?\DateTime $since = null): array
	{
		if (!$this->isProjectCheckAvailable()) {
			return ['success' => false, 'error' => 'ProjectCheck not available'];
		}

		try {
			// Get ArbeitszeitCheck entries that have ProjectCheck project IDs
			$query = $this->db->getQueryBuilder();
			$query->select('*')
				->from('at_entries')
				->where($query->expr()->isNotNull('project_check_project_id'))
				->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
				->andWhere($query->expr()->eq('status', $query->createNamedParameter('completed')));

			if ($since) {
				$query->andWhere($query->expr()->gte('created_at', $query->createNamedParameter($since)));
			}

			$result = $query->executeQuery();
			$synced = 0;
			$errors = 0;

			while ($entry = $result->fetch()) {
				try {
					// Check if this entry already exists in ProjectCheck
					$existingQuery = $this->db->getQueryBuilder();
					$existingQuery->select('id')
						->from('pc_time_entries')
						->where($existingQuery->expr()->eq('project_id', $existingQuery->createNamedParameter($entry['project_check_project_id'])))
						->andWhere($existingQuery->expr()->eq('user_id', $existingQuery->createNamedParameter($entry['user_id'])))
						->andWhere($existingQuery->expr()->eq('date', $existingQuery->createNamedParameter($entry['start_time'] instanceof \DateTime ? $entry['start_time']->format('Y-m-d') : $entry['start_time'], IQueryBuilder::PARAM_STR)));

					$existing = $existingQuery->executeQuery()->fetch();

					if (!$existing) {
						// Insert into ProjectCheck time entries
						$insertQuery = $this->db->getQueryBuilder();
						$insertQuery->insert('pc_time_entries')
							->values([
								'project_id' => $insertQuery->createNamedParameter($entry['project_check_project_id']),
								'user_id' => $insertQuery->createNamedParameter($entry['user_id']),
								'date' => $insertQuery->createNamedParameter($entry['start_time'] instanceof \DateTime ? $entry['start_time']->format('Y-m-d') : $entry['start_time'], IQueryBuilder::PARAM_STR),
								'hours' => $insertQuery->createNamedParameter($entry['hours']),
								'description' => $insertQuery->createNamedParameter($entry['description'] ?? ''),
								'hourly_rate' => $insertQuery->createNamedParameter($entry['hourly_rate'] ?? 0),
								'created_at' => $insertQuery->createNamedParameter($entry['created_at']),
							])
							->executeStatement();

						$synced++;
					}
				} catch (\Throwable $e) {
					// best-effort: per-entry sync failure must not abort the batch;
					// the error count is returned to the caller for surfacing.
					$this->logger->warning('Failed to sync time entry to ProjectCheck: ' . $e->getMessage());
					$errors++;
				}
			}

			$result->closeCursor();

			return [
				'success' => true,
				'synced' => $synced,
				'errors' => $errors,
			];
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to sync time entries to ProjectCheck: ' . $e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Get project budget information from ProjectCheck.
	 *
	 * @deprecated Not used by production code; reads directly from the
	 *             projectcheck schema and is sensitive to changes there.
	 * @internal
	 *
	 * @return array<string, float>|null
	 */
	public function getProjectBudgetInfo(string $projectId): ?array
	{
		if (!$this->isProjectCheckAvailable()) {
			return null;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select(['total_budget', 'hourly_rate'])
				->from('pc_projects')
				->where($query->expr()->eq('id', $query->createNamedParameter((int)$projectId, IQueryBuilder::PARAM_INT)));

			$result = $query->executeQuery();
			$project = $result->fetch();

			$result->closeCursor();

			if ($project) {
				return [
					'budget' => (float)($project['total_budget'] ?? 0),
					'hourlyRate' => (float)($project['hourly_rate'] ?? 0),
				];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load project budget from ProjectCheck: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get project time statistics combining both apps.
	 *
	 * @deprecated The implementation references `hours` and `hourly_rate`
	 *             columns which do not exist on the `at_entries` schema, so
	 *             the ArbeitszeitCheck portion of the stats always errors out
	 *             at runtime (and is logged and ignored). Do NOT call from
	 *             production code – the {@see \OCA\ArbeitszeitCheck\Service\OvertimeService}
	 *             and report services are the canonical sources of working-time
	 *             aggregates.
	 * @internal
	 *
	 * @return array<string, mixed>
	 */
	public function getProjectTimeStats(string $projectId): array
	{
		$stats = [
			'projectId' => $projectId,
			'arbeitszeitcheck' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0,
			],
			'projectcheck' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0,
			],
			'combined' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0,
			],
		];

		// Get ArbeitszeitCheck stats
		try {
			$query = $this->db->getQueryBuilder();
			$query->select([
				$query->createFunction('SUM(hours) as total_hours'),
				$query->createFunction('SUM(hours * hourly_rate) as total_cost'),
				$query->createFunction('COUNT(*) as entries_count'),
			])
				->from('at_entries')
				->where($query->expr()->eq('project_check_project_id', $query->createNamedParameter($projectId)))
				->andWhere($query->expr()->eq('status', $query->createNamedParameter('completed')));

			$result = $query->executeQuery();
			$row = $result->fetch();

			$stats['arbeitszeitcheck'] = [
				'totalHours' => (float)($row['total_hours'] ?? 0),
				'totalCost' => (float)($row['total_cost'] ?? 0),
				'entriesCount' => (int)($row['entries_count'] ?? 0),
			];

			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to get ArbeitszeitCheck project stats: ' . $e->getMessage());
		}

		// Get ProjectCheck stats
		if ($this->isProjectCheckAvailable()) {
			try {
				$query = $this->db->getQueryBuilder();
				$query->select([
					$query->createFunction('SUM(hours) as total_hours'),
					$query->createFunction('SUM(hours * hourly_rate) as total_cost'),
					$query->createFunction('COUNT(*) as entries_count'),
				])
					->from('pc_time_entries')
					->where($query->expr()->eq('project_id', $query->createNamedParameter((int)$projectId, IQueryBuilder::PARAM_INT)));

				$result = $query->executeQuery();
				$row = $result->fetch();

				$stats['projectcheck'] = [
					'totalHours' => (float)($row['total_hours'] ?? 0),
					'totalCost' => (float)($row['total_cost'] ?? 0),
					'entriesCount' => (int)($row['entries_count'] ?? 0),
				];

				$result->closeCursor();
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to get ProjectCheck project stats: ' . $e->getMessage());
			}
		}

		// Calculate combined stats
		$stats['combined'] = [
			'totalHours' => $stats['arbeitszeitcheck']['totalHours'] + $stats['projectcheck']['totalHours'],
			'totalCost' => $stats['arbeitszeitcheck']['totalCost'] + $stats['projectcheck']['totalCost'],
			'entriesCount' => $stats['arbeitszeitcheck']['entriesCount'] + $stats['projectcheck']['entriesCount'],
		];

		return $stats;
	}

	/**
	 * Check if a project exists in ProjectCheck (any status).
	 */
	public function projectExists(string $projectId): bool
	{
		return $this->projectExistsInDatabase((int)trim($projectId));
	}

	private function projectExistsInDatabase(int $projectId): bool
	{
		if (!$this->isProjectCheckAvailable() || $projectId <= 0) {
			return false;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('id')
				->from('pc_projects')
				->where($query->expr()->eq('id', $query->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);

			$result = $query->executeQuery();
			$exists = $result->fetch() !== false;
			$result->closeCursor();

			return $exists;
		} catch (\Throwable $e) {
			return false;
		}
	}
}
