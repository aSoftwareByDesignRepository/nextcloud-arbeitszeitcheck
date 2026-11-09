<?php

declare(strict_types=1);

/**
 * WidgetToken mapper — hash-only lookups; raw tokens never stored.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<WidgetToken> */
class WidgetTokenMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'azc_widget_tokens', WidgetToken::class);
	}

	public function findByHash(string $tokenHash): ?WidgetToken
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Count live tokens for a user (not revoked, not expired) — feeds the
	 * per-user issuance cap in WidgetTokenService.
	 */
	public function countActiveByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('expires_at'),
				$qb->expr()->gt('expires_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return WidgetToken[] */
	public function findByUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * E.6-2 per-device issuance: active tokens for (user, deviceLabel) — a
	 * reissued device replaces its prior token instead of accumulating.
	 *
	 * @return WidgetToken[]
	 */
	public function findActiveByDevice(string $userId, string $deviceLabel): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('device_label', $qb->createNamedParameter($deviceLabel)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('expires_at'),
				$qb->expr()->gt('expires_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			));
		return $this->findEntities($qb);
	}

	public function revoke(int $id, string $userId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revoked_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $qb->executeStatement() > 0;
	}

	public function touchUsed(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('last_used_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
