<?php

declare(strict_types=1);

/**
 * ComplianceViolationMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * ComplianceViolationMapper
 */
class ComplianceViolationMapper extends QBMapper
{
	/**
	 * ComplianceViolationMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_violations', ComplianceViolation::class);
	}

	/**
	 * Create a new compliance violation
	 *
	 * @param string $userId
	 * @param string $violationType
	 * @param string $description
	 * @param \DateTime $date
	 * @param int|null $timeEntryId
	 * @param string $severity
	 * @return ComplianceViolation
	 */
	public function createViolation(
		string $userId,
		string $violationType,
		string $description,
		\DateTime $date,
		?int $timeEntryId = null,
		string $severity = ComplianceViolation::SEVERITY_WARNING
	): ComplianceViolation {
		$violation = new ComplianceViolation();
		$violation->setUserId($userId);
		$violation->setViolationType($violationType);
		$violation->setDescription($description);
		$violation->setDate($date);
		$violation->setTimeEntryId($timeEntryId);
		$violation->setSeverity($severity);
		$violation->setResolved(false);
		$violation->setCreatedAt(new \DateTime());

		return $this->insert($violation);
	}

	/**
	 * Find violations by user
	 *
	 * @param string $userId
	 * @param bool|null $resolved Filter by resolved status (null = all)
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return ComplianceViolation[]
	 */
	public function findByUser(string $userId, ?bool $resolved = null, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		if ($resolved !== null) {
			$qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_BOOL)));
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
	 * Find unresolved violations
	 *
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return ComplianceViolation[]
	 */
	public function findUnresolved(?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('resolved', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('severity', 'DESC')
			->addOrderBy('created_at', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Delete all violations for a user (used on user deletion)
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

	/**
	 * Delete all violations that are linked to a given time entry.
	 *
	 * This is used when a manual time entry is deleted so that we do not keep
	 * orphaned compliance records pointing to a non-existent entry. The user
	 * still has an audit trail for the deletion itself via the audit log.
	 *
	 * @param int $timeEntryId
	 * @return int Number of deleted rows
	 */
	public function deleteByTimeEntryId(int $timeEntryId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('time_entry_id', $qb->createNamedParameter($timeEntryId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Find violations by type
	 *
	 * @param string $violationType
	 * @param bool|null $resolved
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return ComplianceViolation[]
	 */
	public function findByType(string $violationType, ?bool $resolved = null, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('violation_type', $qb->createNamedParameter($violationType)))
			->orderBy('created_at', 'DESC');

		if ($resolved !== null) {
			$qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_BOOL)));
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
	 * Find violations by date range
	 *
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param string|null $userId
	 * @param bool|null $resolved
	 * @return ComplianceViolation[]
	 */
	public function findByDateRange(\DateTime $startDate, \DateTime $endDate, ?string $userId = null, ?bool $resolved = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('date', 'DESC');

		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}

		if ($resolved !== null) {
			$qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_BOOL)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count violations with optional filters
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

		if (isset($filters['violation_type'])) {
			$qb->andWhere($qb->expr()->eq('violation_type', $qb->createNamedParameter($filters['violation_type'])));
		}

		if (isset($filters['resolved'])) {
			$qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($filters['resolved'], IQueryBuilder::PARAM_BOOL)));
		}

		if (isset($filters['severity'])) {
			$qb->andWhere($qb->expr()->eq('severity', $qb->createNamedParameter($filters['severity'])));
		}

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('date', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Get violation statistics
	 *
	 * @param array $filters
	 * @return array
	 */
	public function getStatistics(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select([
			'violation_type',
			'severity',
			'resolved',
			$qb->createFunction('COUNT(*) as count')
		])
		->from($this->getTableName())
		->groupBy('violation_type', 'severity', 'resolved')
		->orderBy('violation_type');

		if (isset($filters['user_id'])) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($filters['user_id'])));
		}

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('date', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		$results = $qb->executeQuery()->fetchAll();

		$stats = [
			'total_violations' => 0,
			'unresolved_violations' => 0,
			'resolved_violations' => 0,
			'by_type' => [],
			'by_severity' => []
		];

		foreach ($results as $row) {
			$count = (int)$row['count'];
			$stats['total_violations'] += $count;

			if ($row['resolved'] === '1') {
				$stats['resolved_violations'] += $count;
			} else {
				$stats['unresolved_violations'] += $count;
			}

			// Group by type
			if (!isset($stats['by_type'][$row['violation_type']])) {
				$stats['by_type'][$row['violation_type']] = 0;
			}
			$stats['by_type'][$row['violation_type']] += $count;

			// Group by severity
			if (!isset($stats['by_severity'][$row['severity']])) {
				$stats['by_severity'][$row['severity']] = 0;
			}
			$stats['by_severity'][$row['severity']] += $count;
		}

		return $stats;
	}

	/**
	 * Find violation by ID
	 *
	 * @param int $id
	 * @return ComplianceViolation
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): ComplianceViolation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Mark violation as resolved
	 *
	 * @param int $id
	 * @param string $resolvedBy Nextcloud user ID of the resolver
	 * @return ComplianceViolation
	 * @throws \Exception
	 */
	public function resolveViolation(int $id, string $resolvedBy): ComplianceViolation
	{
		$violation = $this->find($id);
		$violation->markAsResolved($resolvedBy);

		return $this->update($violation);
	}
}