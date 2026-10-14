<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MobileStampIdempotency>
 */
class MobileStampIdempotencyMapper extends QBMapper
{
	public const TABLE = 'at_mob_stamp_idem';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, MobileStampIdempotency::class);
	}

	public function findByUserAndRequestId(string $userId, string $clientRequestId): ?MobileStampIdempotency
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Returns the inserted entity on success (its id is the claim token) or
	 * null when the unique (user_id, client_request_id) index rejected a
	 * concurrent claim.
	 */
	public function tryInsert(
		string $userId,
		string $clientRequestId,
		string $responseJson,
		int $createdAt,
		?string $payloadHash = null,
	): ?MobileStampIdempotency {
		$entity = new MobileStampIdempotency();
		$entity->setUserId($userId);
		$entity->setClientRequestId($clientRequestId);
		$entity->setResponseJson($responseJson);
		$entity->setCreatedAt($createdAt);
		$entity->setPayloadHash($payloadHash);
		try {
			return $this->insert($entity);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}
	}

	/**
	 * Finalize exactly the claim row we own (by id). A late writer whose claim
	 * was reclaimed must never overwrite the reclaimer's row.
	 */
	public function updateResponseJsonForId(int $id, string $responseJson): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('response_json', $qb->createNamedParameter($responseJson))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteById(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Conditional delete for stale-claim reclaim: only removes the row when it
	 * is still the expected PENDING claim created before $createdBefore, so a
	 * claim that completed between our read and this DELETE is never touched.
	 * Returns affected rows; >0 means this caller won the reclaim race.
	 */
	public function deleteStalePending(
		string $userId,
		string $clientRequestId,
		string $pendingJson,
		int $createdBefore,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)))
			->andWhere($qb->expr()->eq('response_json', $qb->createNamedParameter($pendingJson)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($createdBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Retention sweep: drop idempotency rows older than $createdBefore.
	 * Completed rows far past the replay window only grow the table.
	 */
	public function deleteOlderThan(int $createdBefore): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($createdBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function updateResponseJson(string $userId, string $clientRequestId, string $responseJson): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('response_json', $qb->createNamedParameter($responseJson))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		$qb->executeStatement();
	}

	public function deleteByUserAndRequestId(string $userId, string $clientRequestId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		$qb->executeStatement();
	}

	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
