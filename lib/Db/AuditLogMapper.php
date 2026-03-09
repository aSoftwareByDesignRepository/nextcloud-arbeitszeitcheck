<?php

declare(strict_types=1);

/**
 * AuditLogMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * AuditLogMapper
 */
class AuditLogMapper extends QBMapper
{
	/**
	 * AuditLogMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_audit', AuditLog::class);
	}

	/**
	 * Log an action
	 *
	 * @param string $userId
	 * @param string $action
	 * @param string $entityType
	 * @param int|null $entityId
	 * @param array|null $oldValues
	 * @param array|null $newValues
	 * @param string|null $performedBy
	 * @return AuditLog
	 */
	public function logAction(
		string $userId,
		string $action,
		string $entityType,
		?int $entityId = null,
		?array $oldValues = null,
		?array $newValues = null,
		?string $performedBy = null
	): AuditLog {
		$log = new AuditLog();
		$log->setUserId($userId);
		$log->setAction($action);
		$log->setEntityType($entityType);
		$log->setEntityId($entityId);
		$log->setOldValues($oldValues ? json_encode($oldValues) : null);
		$log->setNewValues($newValues ? json_encode($newValues) : null);
		$log->setPerformedBy($performedBy);
		$log->setCreatedAt(new \DateTime());

		// Get IP address and user agent if available
		if (isset($_SERVER['REMOTE_ADDR'])) {
			$log->setIpAddress($_SERVER['REMOTE_ADDR']);
		}

		if (isset($_SERVER['HTTP_USER_AGENT'])) {
			$log->setUserAgent($_SERVER['HTTP_USER_AGENT']);
		}

		return $this->insert($log);
	}

	/**
	 * Find audit logs by user
	 *
	 * @param string $userId
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return AuditLog[]
	 */
	public function findByUser(string $userId, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find audit logs by entity
	 *
	 * @param string $entityType
	 * @param int|null $entityId
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return AuditLog[]
	 */
	public function findByEntity(string $entityType, ?int $entityId = null, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->orderBy('created_at', 'DESC');

		if ($entityId !== null) {
			$qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find audit logs by action
	 *
	 * @param string $action
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return AuditLog[]
	 */
	public function findByAction(string $action, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->orderBy('created_at', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find audit logs by date range
	 *
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param string|null $userId
	 * @param string|null $action
	 * @param string|null $entityType
	 * @return AuditLog[]
	 */
	public function findByDateRange(
		\DateTime $startDate,
		\DateTime $endDate,
		?string $userId = null,
		?string $action = null,
		?string $entityType = null
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($endDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');

		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}

		if ($action !== null) {
			$qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
		}

		if ($entityType !== null) {
			$qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count audit logs with optional filters
	 *
	 * @param array $filters
	 * @return int
	 */
	public function count(array $filters = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'));

		$qb->from($this->getTableName());

		if (isset($filters['user_id'])) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($filters['user_id'])));
		}

		if (isset($filters['action'])) {
			$qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($filters['action'])));
		}

		if (isset($filters['entity_type'])) {
			$qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($filters['entity_type'])));
		}

		if (isset($filters['entity_id'])) {
			$qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
		}

		if (isset($filters['performed_by'])) {
			$qb->andWhere($qb->expr()->eq('performed_by', $qb->createNamedParameter($filters['performed_by'])));
		}

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d H:i:s') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d H:i:s') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Get audit log statistics
	 *
	 * @param array $filters
	 * @return array
	 */
	public function getStatistics(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select([
			'action',
			'entity_type',
			$qb->createFunction('COUNT(*) as count'),
			$qb->createFunction('COUNT(DISTINCT user_id) as unique_users')
		])
		->from($this->getTableName())
		->groupBy('action', 'entity_type')
		->orderBy('count', 'DESC');

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d H:i:s') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d H:i:s') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		$results = $qb->executeQuery()->fetchAll();

		$stats = [
			'total_logs' => 0,
			'unique_users' => 0,
			'by_action' => [],
			'by_entity_type' => []
		];

		foreach ($results as $row) {
			$count = (int)$row['count'];
			$uniqueUsers = (int)$row['unique_users'];

			$stats['total_logs'] += $count;
			$stats['unique_users'] = max($stats['unique_users'], $uniqueUsers);

			// Group by action
			if (!isset($stats['by_action'][$row['action']])) {
				$stats['by_action'][$row['action']] = 0;
			}
			$stats['by_action'][$row['action']] += $count;

			// Group by entity type
			if (!isset($stats['by_entity_type'][$row['entity_type']])) {
				$stats['by_entity_type'][$row['entity_type']] = 0;
			}
			$stats['by_entity_type'][$row['entity_type']] += $count;
		}

		return $stats;
	}

	/**
	 * Clean up old audit logs (keep last 2 years)
	 *
	 * @return int Number of deleted logs
	 */
	public function cleanupOldLogs(): int
	{
		$twoYearsAgo = new \DateTime();
		$twoYearsAgo->modify('-2 years');

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($twoYearsAgo->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all audit logs for a user (used on user deletion)
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}