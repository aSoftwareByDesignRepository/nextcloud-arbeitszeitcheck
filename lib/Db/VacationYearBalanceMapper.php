<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class VacationYearBalanceMapper extends QBMapper
{
	private ?\OCP\ICache $statsCache = null;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_vacation_year_balance', VacationYearBalance::class);
	}

	/**
	 * Carryover is a direct input of AbsenceService::getVacationStats, which
	 * is cached per user behind a version key. Every write through this
	 * mapper (admin edit, rollover, import, unit migration) must orphan the
	 * user's cached stats. Call this AFTER the write statement: bumping the
	 * version beforehand lets a concurrent reader cache pre-write data under
	 * the new token. Callers inside an outer transaction must additionally
	 * invalidate post-commit (see AdminUserProfileUpdateService) — the bump
	 * here only orders correctly against autocommit readers.
	 */
	private function invalidateStatsCache(string $userId): void
	{
		try {
			$this->statsCache ??= \OCP\Server::get(\OCP\ICacheFactory::class)
				->createDistributed('azc_vacation_stats');
			// Fresh version token orphans every cached stats entry — see
			// AbsenceService::invalidateVacationStatsCache for why a remove()
			// would resurrect the very entries it means to drop.
			$this->statsCache->set(
				\OCA\ArbeitszeitCheck\Service\AbsenceService::VACATION_STATS_VERSION_PREFIX . $userId,
				bin2hex(random_bytes(8)),
				\OCA\ArbeitszeitCheck\Service\AbsenceService::VACATION_STATS_VERSION_TTL
			);
		} catch (\Throwable) {
			// A cache-backend hiccup must never break a balance write.
		}
	}

	public function findByUserAndYear(string $userId, int $year): VacationYearBalance
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Opening carryover in the active unit.
	 * Hours mode prefers carryover_hours when set; otherwise falls back to carryover_days
	 * (post-migration both hold hour amounts in Q3=A).
	 */
	public function getCarryoverAmount(string $userId, int $year, bool $preferHours): float
	{
		try {
			$row = $this->findByUserAndYear($userId, $year);
			if ($preferHours) {
				$hours = $row->getCarryoverHours();
				if ($hours !== null && is_finite((float)$hours)) {
					return (float)$hours;
				}
			}
			return (float)$row->getCarryoverDays();
		} catch (DoesNotExistException $e) {
			return 0.0;
		}
	}

	/**
	 * @return float Carryover days or 0 if no row
	 */
	public function getCarryoverDays(string $userId, int $year): float
	{
		try {
			return $this->findByUserAndYear($userId, $year)->getCarryoverDays();
		} catch (DoesNotExistException $e) {
			return 0.0;
		}
	}

	/**
	 * @param bool $clearCarryoverHours When true, null out carryover_hours (days-mode reverse migrate).
	 *                                  When false and $carryoverHours is null, leave hours column unchanged.
	 */
	public function upsert(
		string $userId,
		int $year,
		float $carryoverDays,
		?float $carryoverHours = null,
		bool $clearCarryoverHours = false,
	): VacationYearBalance {
		$now = new \DateTime();
		$normalized = max(0.0, min(4000.0, $carryoverDays));
		$normalizedHours = $carryoverHours;
		if ($normalizedHours !== null) {
			$normalizedHours = max(0.0, min(4000.0, $normalizedHours));
		}
		try {
			$entity = $this->findByUserAndYear($userId, $year);
			$entity->setCarryoverDays($normalized);
			if ($clearCarryoverHours) {
				$entity->setCarryoverHours(null);
			} elseif ($normalizedHours !== null) {
				$entity->setCarryoverHours($normalizedHours);
			}
			$entity->setUpdatedAt($now);
			$saved = $this->update($entity);
		} catch (DoesNotExistException $e) {
			$entity = new VacationYearBalance();
			$entity->setUserId($userId);
			$entity->setYear($year);
			$entity->setCarryoverDays($normalized);
			if ($clearCarryoverHours) {
				$entity->setCarryoverHours(null);
			} elseif ($normalizedHours !== null) {
				$entity->setCarryoverHours($normalizedHours);
			}
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);
			try {
				$saved = $this->insert($entity);
			} catch (UniqueConstraintViolationException) {
				$existing = $this->findByUserAndYear($userId, $year);
				$existing->setCarryoverDays($normalized);
				if ($clearCarryoverHours) {
					$existing->setCarryoverHours(null);
				} elseif ($normalizedHours !== null) {
					$existing->setCarryoverHours($normalizedHours);
				}
				$existing->setUpdatedAt($now);
				$saved = $this->update($existing);
			}
		}
		$this->invalidateStatsCache($userId);
		return $saved;
	}

	/**
	 * Delete all vacation year balance rows for a user (e.g. on account deletion).
	 */
	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
		$this->invalidateStatsCache($userId);
	}
}
