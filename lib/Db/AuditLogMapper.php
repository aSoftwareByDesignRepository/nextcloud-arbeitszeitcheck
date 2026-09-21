<?php

declare(strict_types=1);

/**
 * AuditLogMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Constants;
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
		$log->setCaptureSource($this->extractCaptureSource($newValues));
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
	 * Promote known capture_source values from newValues onto the indexed column.
	 *
	 * @param array<string, mixed>|null $newValues
	 */
	private function extractCaptureSource(?array $newValues): ?string
	{
		if ($newValues === null || !isset($newValues['capture_source'])) {
			return null;
		}
		$raw = trim((string)$newValues['capture_source']);
		if ($raw === Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC) {
			return Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC;
		}

		return null;
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
		return $this->searchByDateRange($startDate, $endDate, [
			'user_id' => $userId,
			'action' => $action,
			'entity_type' => $entityType,
		]);
	}

	/**
	 * Search audit logs with optional filters and pagination.
	 *
	 * Supported filter keys:
	 * - user_id: exact user id match
	 * - user_id_like: partial match on user_id (case-sensitive per DB collation)
	 * - action: exact action match
	 * - actions_in: list of action strings (IN clause)
	 * - entity_type: exact entity type match
	 * - limit, offset: pagination
	 *
	 * @param array<string, mixed> $filters
	 * @return AuditLog[]
	 */
	public function searchByDateRange(\DateTime $startDate, \DateTime $endDate, array $filters = []): array
	{
		$qb = $this->buildDateRangeQuery($startDate, $endDate, $filters);
		$qb->select('*')
			->orderBy('created_at', 'DESC');

		if (isset($filters['limit']) && $filters['limit'] !== null) {
			$qb->setMaxResults(max(1, (int)$filters['limit']));
		}
		if (isset($filters['offset']) && $filters['offset'] !== null) {
			$qb->setFirstResult(max(0, (int)$filters['offset']));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count audit logs matching the same filters as {@see searchByDateRange}.
	 *
	 * @param array<string, mixed> $filters
	 */
	public function countByDateRange(\DateTime $startDate, \DateTime $endDate, array $filters = []): int
	{
		$qb = $this->buildDateRangeQuery($startDate, $endDate, $filters);
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'audit_count');

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function buildDateRangeQuery(\DateTime $startDate, \DateTime $endDate, array $filters): IQueryBuilder
	{
		$exclusiveEnd = clone $endDate;
		$exclusiveEnd->modify('+1 day');
		$exclusiveEnd->setTime(0, 0, 0);

		$qb = $this->db->getQueryBuilder();
		$qb->from($this->getTableName())
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($startDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($exclusiveEnd->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));

		$userId = isset($filters['user_id']) ? trim((string)$filters['user_id']) : '';
		if ($userId !== '') {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}

		$userIdLike = isset($filters['user_id_like']) ? trim((string)$filters['user_id_like']) : '';
		if ($userIdLike !== '' && $userId === '') {
			$qb->andWhere($qb->expr()->like(
				'user_id',
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($userIdLike) . '%')
			));
		}

		$action = isset($filters['action']) ? trim((string)$filters['action']) : '';
		if ($action !== '') {
			$qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
		} elseif (!empty($filters['actions_in']) && is_array($filters['actions_in'])) {
			$actions = array_values(array_filter(array_map('strval', $filters['actions_in']), static fn (string $a): bool => $a !== ''));
			if ($actions !== []) {
				$qb->andWhere($qb->expr()->in('action', $qb->createNamedParameter($actions, IQueryBuilder::PARAM_STR_ARRAY)));
			}
		}

		$entityType = isset($filters['entity_type']) ? trim((string)$filters['entity_type']) : '';
		if ($entityType !== '') {
			$qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
		}

		// Indexed Offline-Sync filter (Version1046+); column is set on write + backfill.
		if (!empty($filters['offline_sync'])) {
			$qb->andWhere($qb->expr()->eq(
				'capture_source',
				$qb->createNamedParameter(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC)
			));
		}

		return $qb;
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
		// IMPORTANT: do not use `count` or `unique_users` as aliases - `count` is
		// a reserved function name in PostgreSQL/Oracle, and Doctrine's
		// "ORDER BY <alias>" can collide with the function. We use neutral
		// aliases instead.
		$qb->select([
			'action',
			'entity_type',
			$qb->createFunction('COUNT(*) AS entry_count'),
			$qb->createFunction('COUNT(DISTINCT user_id) AS distinct_users'),
		])
		->from($this->getTableName())
		->groupBy('action', 'entity_type')
		->orderBy('entry_count', 'DESC');

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
			$count = (int)($row['entry_count'] ?? 0);
			$uniqueUsers = (int)($row['distinct_users'] ?? 0);

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