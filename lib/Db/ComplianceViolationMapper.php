<?php

declare(strict_types=1);

/**
 * ComplianceViolationMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\QueryInChunker;
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
	 * Find all violations linked to a time entry (dedup anchor for
	 * per-entry policy checks).
	 *
	 * @return ComplianceViolation[]
	 */
	public function findByTimeEntryId(int $timeEntryId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('time_entry_id', $qb->createNamedParameter($timeEntryId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
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
	 * Find violations for a set of users, optionally bounded by violation
	 * date and filtered by type / severity / resolved state.
	 *
	 * Multi-user variant of {@see findByDateRange()} used by scoped
	 * manager/HR surfaces and exports. Date bounds are inclusive-start /
	 * exclusive-end, matching findByDateRange semantics. Large user sets
	 * are chunked via QueryInChunker for portable IN() handling.
	 *
	 * @param list<string> $userIds
	 * @param \DateTimeInterface|null $startDate Inclusive lower bound on `date`
	 * @param \DateTimeInterface|null $endDateExclusive Exclusive upper bound on `date`
	 * @param string|null $violationType
	 * @param string|null $severity
	 * @param bool|null $resolved
	 * @return ComplianceViolation[]
	 */
	public function findByUserIdsAndDateRange(
		array $userIds,
		?\DateTimeInterface $startDate = null,
		?\DateTimeInterface $endDateExclusive = null,
		?string $violationType = null,
		?string $severity = null,
		?bool $resolved = null,
		?int $limit = null,
		?int $offset = null,
	): array {
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->buildUserIdsDateRangeQuery($userIds, $startDate, $endDateExclusive, $violationType, $severity, $resolved);
		$qb->select('*')
			->orderBy('date', 'DESC')
			->addOrderBy('id', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/**
	 * Count variant of {@see findByUserIdsAndDateRange()}.
	 *
	 * @param list<string> $userIds
	 */
	public function countByUserIdsAndDateRange(
		array $userIds,
		?\DateTimeInterface $startDate = null,
		?\DateTimeInterface $endDateExclusive = null,
		?string $violationType = null,
		?string $severity = null,
		?bool $resolved = null,
	): int {
		if (empty($userIds)) {
			return 0;
		}

		$qb = $this->buildUserIdsDateRangeQuery($userIds, $startDate, $endDateExclusive, $violationType, $severity, $resolved);
		$qb->select($qb->createFunction('COUNT(*)'));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Per-user unresolved-violation counts for a set of users — one query,
	 * grouped. Used by the people overview to avoid N+1 counting.
	 *
	 * @param list<string> $userIds
	 * @return array<string, int> userId => unresolved count (users without
	 *         violations are absent from the map)
	 */
	public function countUnresolvedByUserIds(array $userIds): array
	{
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', $qb->createFunction('COUNT(*) AS cnt'))
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('user_id');

		$counts = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$counts[(string)$row['user_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @param list<string> $userIds
	 */
	private function buildUserIdsDateRangeQuery(
		array $userIds,
		?\DateTimeInterface $startDate,
		?\DateTimeInterface $endDateExclusive,
		?string $violationType,
		?string $severity,
		?bool $resolved,
	): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY));

		if ($startDate !== null) {
			$qb->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
		}
		if ($endDateExclusive !== null) {
			$qb->andWhere($qb->expr()->lt('date', $qb->createNamedParameter($endDateExclusive->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
		}
		if ($violationType !== null && $violationType !== '') {
			$qb->andWhere($qb->expr()->eq('violation_type', $qb->createNamedParameter($violationType)));
		}
		if ($severity !== null && $severity !== '') {
			$qb->andWhere($qb->expr()->eq('severity', $qb->createNamedParameter($severity)));
		}
		if ($resolved !== null) {
			$qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_BOOL)));
		}
		return $qb;
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
		// Use a non-reserved alias ('cnt'). PostgreSQL/Oracle treat `count` as a
		// function name and quoting can interact awkwardly with some driver versions.
		$qb->select([
			'violation_type',
			'severity',
			'resolved',
			$qb->createFunction('COUNT(*) AS cnt'),
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
			$count = (int)($row['cnt'] ?? 0);
			$stats['total_violations'] += $count;

			// Cross-database boolean normalisation:
			// - MySQL/MariaDB/SQLite: TINYINT(1) returns 0/1 or '0'/'1'
			// - PostgreSQL: BOOLEAN returns true/false or 't'/'f'
			// - Oracle: NUMBER(1) returns 0/1 or '0'/'1'
			if ($this->isTruthyDbValue($row['resolved'] ?? null)) {
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
	 * Normalise a database boolean cell to a PHP bool, regardless of platform.
	 *
	 * Different drivers materialise BOOLEAN columns differently (e.g. 't'/'f' on
	 * PostgreSQL, 1/0 on MySQL/SQLite, true/false when DBAL hydrates). This
	 * helper centralises the conversion so callers cannot accidentally rely on
	 * a platform-specific representation.
	 */
	private function isTruthyDbValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int)$value === 1;
		}
		$normalised = strtolower((string)$value);
		return in_array($normalised, ['1', 't', 'true', 'y', 'yes'], true);
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