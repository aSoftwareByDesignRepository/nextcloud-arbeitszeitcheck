<?php

declare(strict_types=1);

/**
 * ProjectCheck integration service for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Service for integrating with ProjectCheck app
 */
class ProjectCheckIntegrationService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IDBConnection $db,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Check if ProjectCheck app is installed and enabled
	 *
	 * @return bool
	 */
	public function isProjectCheckAvailable(): bool
	{
		return $this->appManager->isEnabledForUser('projectcheck');
	}

	/**
	 * Get available projects from ProjectCheck for a user
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getAvailableProjects(string $userId): array
	{
		if (!$this->isProjectCheckAvailable()) {
			return [];
		}

		try {
			// Query ProjectCheck database tables directly
			// This implementation queries the ProjectCheck database tables directly
			// as a working integration approach. If ProjectCheck provides a formal API
			// in the future, this can be refactored to use that API.

			$query = $this->db->getQueryBuilder();
			$query->select(['p.id', 'p.name', 'p.customer_id', 'c.name as customer_name'])
				->from('projectcheck_projects', 'p')
				->leftJoin('p', 'projectcheck_customers', 'c', $query->expr()->eq('p.customer_id', 'c.id'))
				->leftJoin('p', 'projectcheck_project_members', 'pm', $query->expr()->andX(
					$query->expr()->eq('p.id', 'pm.project_id'),
					$query->expr()->eq('pm.user_id', $query->createNamedParameter($userId))
				))
				->where($query->expr()->orX(
					$query->expr()->eq('pm.user_id', $query->createNamedParameter($userId)),
					$query->expr()->isNull('pm.user_id') // Allow projects without specific assignments
				))
				->andWhere($query->expr()->eq('p.status', $query->createNamedParameter('active')))
				->orderBy('p.name', 'ASC');

			$result = $query->executeQuery();
			$projects = [];

			while ($row = $result->fetch()) {
				$projects[] = [
					'id' => $row['id'],
					'name' => $row['name'],
					'customerId' => $row['customer_id'],
					'customerName' => $row['customer_name'] ?? $this->l10n->t('No Customer'),
					'displayName' => $row['customer_name']
						? sprintf('%s (%s)', $row['name'], $row['customer_name'])
						: $row['name']
				];
			}

			$result->closeCursor();

			return $projects;
		} catch (\Throwable $e) {
			// Log error but don't fail - ProjectCheck integration should be graceful
			$this->logger->warning('Failed to load projects from ProjectCheck: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get project details from ProjectCheck
	 *
	 * @param string $projectId
	 * @return array|null
	 */
	public function getProjectDetails(string $projectId): ?array
	{
		if (!$this->isProjectCheckAvailable()) {
			return null;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select(['p.*', 'c.name as customer_name'])
				->from('projectcheck_projects', 'p')
				->leftJoin('p', 'projectcheck_customers', 'c', $query->expr()->eq('p.customer_id', 'c.id'))
				->where($query->expr()->eq('p.id', $query->createNamedParameter($projectId)));

			$result = $query->executeQuery();
			$project = $result->fetch();

			$result->closeCursor();

			if ($project) {
				return [
					'id' => $project['id'],
					'name' => $project['name'],
					'description' => $project['description'],
					'customerId' => $project['customer_id'],
					'customerName' => $project['customer_name'],
					'status' => $project['status'],
					'budget' => $project['budget'] ?? 0,
					'hourlyRate' => $project['hourly_rate'] ?? 0,
					'startDate' => $project['start_date'],
					'endDate' => $project['end_date']
				];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load project details from ProjectCheck: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get time entries from ProjectCheck for a project (for migration/comparison)
	 *
	 * @param string $projectId
	 * @return array
	 */
	public function getProjectCheckTimeEntries(string $projectId): array
	{
		if (!$this->isProjectCheckAvailable()) {
			return [];
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('*')
				->from('projectcheck_time_entries')
				->where($query->expr()->eq('project_id', $query->createNamedParameter($projectId)))
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
					'source' => 'projectcheck'
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
	 * Sync time entries between ArbeitszeitCheck and ProjectCheck
	 * This allows time tracked in ArbeitszeitCheck to be visible in ProjectCheck
	 *
	 * @param string $userId
	 * @param \DateTime|null $since Only sync entries since this date
	 * @return array Sync results
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
						->from('projectcheck_time_entries')
						->where($existingQuery->expr()->eq('project_id', $existingQuery->createNamedParameter($entry['project_check_project_id'])))
						->andWhere($existingQuery->expr()->eq('user_id', $existingQuery->createNamedParameter($entry['user_id'])))
						->andWhere($existingQuery->expr()->eq('date', $existingQuery->createNamedParameter($entry['start_time'] instanceof \DateTime ? $entry['start_time']->format('Y-m-d') : $entry['start_time'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));

					$existing = $existingQuery->executeQuery()->fetch();

					if (!$existing) {
						// Insert into ProjectCheck time entries
						$insertQuery = $this->db->getQueryBuilder();
						$insertQuery->insert('projectcheck_time_entries')
							->values([
								'project_id' => $insertQuery->createNamedParameter($entry['project_check_project_id']),
								'user_id' => $insertQuery->createNamedParameter($entry['user_id']),
								'date' => $insertQuery->createNamedParameter($entry['start_time'] instanceof \DateTime ? $entry['start_time']->format('Y-m-d') : $entry['start_time'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
								'hours' => $insertQuery->createNamedParameter($entry['hours']),
								'description' => $insertQuery->createNamedParameter($entry['description'] ?? ''),
								'hourly_rate' => $insertQuery->createNamedParameter($entry['hourly_rate'] ?? 0),
								'created_at' => $insertQuery->createNamedParameter($entry['created_at'])
							])
							->executeStatement();

						$synced++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to sync time entry to ProjectCheck: ' . $e->getMessage());
					$errors++;
				}
			}

			$result->closeCursor();

			return [
				'success' => true,
				'synced' => $synced,
				'errors' => $errors
			];
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to sync time entries to ProjectCheck: ' . $e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Get project budget information from ProjectCheck
	 *
	 * @param string $projectId
	 * @return array|null
	 */
	public function getProjectBudgetInfo(string $projectId): ?array
	{
		if (!$this->isProjectCheckAvailable()) {
			return null;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select(['budget', 'hourly_rate'])
				->from('projectcheck_projects')
				->where($query->expr()->eq('id', $query->createNamedParameter($projectId)));

			$result = $query->executeQuery();
			$project = $result->fetch();

			$result->closeCursor();

			if ($project) {
				return [
					'budget' => (float)($project['budget'] ?? 0),
					'hourlyRate' => (float)($project['hourly_rate'] ?? 0)
				];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load project budget from ProjectCheck: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Get project time statistics combining both apps
	 *
	 * @param string $projectId
	 * @return array
	 */
	public function getProjectTimeStats(string $projectId): array
	{
		$stats = [
			'projectId' => $projectId,
			'arbeitszeitcheck' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0
			],
			'projectcheck' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0
			],
			'combined' => [
				'totalHours' => 0,
				'totalCost' => 0,
				'entriesCount' => 0
			]
		];

		// Get ArbeitszeitCheck stats
		try {
			$query = $this->db->getQueryBuilder();
			$query->select([
				$query->createFunction('SUM(hours) as total_hours'),
				$query->createFunction('SUM(hours * hourly_rate) as total_cost'),
				$query->createFunction('COUNT(*) as entries_count')
			])
			->from('at_entries')
			->where($query->expr()->eq('project_check_project_id', $query->createNamedParameter($projectId)))
			->andWhere($query->expr()->eq('status', $query->createNamedParameter('completed')));

			$result = $query->executeQuery();
			$row = $result->fetch();

			$stats['arbeitszeitcheck'] = [
				'totalHours' => (float)($row['total_hours'] ?? 0),
				'totalCost' => (float)($row['total_cost'] ?? 0),
				'entriesCount' => (int)($row['entries_count'] ?? 0)
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
					$query->createFunction('COUNT(*) as entries_count')
				])
				->from('projectcheck_time_entries')
				->where($query->expr()->eq('project_id', $query->createNamedParameter($projectId)));

				$result = $query->executeQuery();
				$row = $result->fetch();

				$stats['projectcheck'] = [
					'totalHours' => (float)($row['total_hours'] ?? 0),
					'totalCost' => (float)($row['total_cost'] ?? 0),
					'entriesCount' => (int)($row['entries_count'] ?? 0)
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
			'entriesCount' => $stats['arbeitszeitcheck']['entriesCount'] + $stats['projectcheck']['entriesCount']
		];

		return $stats;
	}

	/**
	 * Check if a project exists in ProjectCheck
	 *
	 * @param string $projectId
	 * @return bool
	 */
	public function projectExists(string $projectId): bool
	{
		if (!$this->isProjectCheckAvailable()) {
			return false;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('id')
				->from('projectcheck_projects')
				->where($query->expr()->eq('id', $query->createNamedParameter($projectId)))
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