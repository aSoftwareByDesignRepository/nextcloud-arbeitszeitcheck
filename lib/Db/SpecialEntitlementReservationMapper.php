<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SpecialEntitlementReservationMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_se_reserv', SpecialEntitlementReservation::class);
	}

	public function findByRequestKey(string $requestKey): ?SpecialEntitlementReservation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByAbsenceId(int $absenceId): ?SpecialEntitlementReservation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('absence_id', $qb->createNamedParameter($absenceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByIdForUpdate(int $id): ?SpecialEntitlementReservation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->forUpdate()
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function transitionIfRevision(int $id, int $expectedRevision, string $status): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	public function updateAmountAndPeriodIfRevision(SpecialEntitlementReservation $reservation, int $periodId, float $amount): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT))
			->set('amount', $qb->createNamedParameter(round($amount, 2, PHP_ROUND_HALF_UP)))
			->set('updated_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->set('revision', $qb->createNamedParameter((int)$reservation->getRevision() + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($reservation->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($reservation->getRevision(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SpecialEntitlementReservation::STATUS_ACTIVE)));
		return $qb->executeStatement() === 1;
	}

	public function activeTotal(int $periodId): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COALESCE(SUM(amount), 0)'))->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SpecialEntitlementReservation::STATUS_ACTIVE)));
		return (float)$qb->executeQuery()->fetchOne();
	}

	public function activeAmountForPeriod(int $periodId): float
	{
		return $this->activeTotal($periodId);
	}

	/** @param list<int> $periodIds */
	public function countActiveForPeriods(array $periodIds): int
	{
		if ($periodIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))->from($this->getTableName())
			->where($qb->expr()->in('period_id', $qb->createNamedParameter($periodIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SpecialEntitlementReservation::STATUS_ACTIVE)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<SpecialEntitlementReservation> */
	public function findForPeriod(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}
}
