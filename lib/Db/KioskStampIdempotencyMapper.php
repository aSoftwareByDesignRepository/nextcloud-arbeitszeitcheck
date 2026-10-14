<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * @extends QBMapper<KioskStampIdempotency>
 */
class KioskStampIdempotencyMapper extends QBMapper
{
	public const TABLE = 'at_kiosk_stamp_idem';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, KioskStampIdempotency::class);
	}

	public function findByTerminalAndRequestId(string $terminalId, string $clientRequestId): ?KioskStampIdempotency
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Returns the inserted entity on success (its id is the claim token) or
	 * null when the unique (terminal_id, client_request_id) index rejected a
	 * concurrent claim.
	 */
	public function tryInsert(
		string $terminalId,
		string $clientRequestId,
		string $responseJson,
		int $createdAt,
		?string $payloadHash = null,
	): ?KioskStampIdempotency {
		$entity = new KioskStampIdempotency();
		$entity->setTerminalId($terminalId);
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
	 */
	public function deleteStalePending(
		string $terminalId,
		string $clientRequestId,
		string $pendingJson,
		int $createdBefore,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)))
			->andWhere($qb->expr()->eq('response_json', $qb->createNamedParameter($pendingJson)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($createdBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/** Retention sweep: drop idempotency rows older than $createdBefore. */
	public function deleteOlderThan(int $createdBefore): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($createdBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function updateResponseJson(string $terminalId, string $clientRequestId, string $responseJson): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('response_json', $qb->createNamedParameter($responseJson))
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		$qb->executeStatement();
	}

	public function deleteByTerminalAndRequestId(string $terminalId, string $clientRequestId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)))
			->andWhere($qb->expr()->eq('client_request_id', $qb->createNamedParameter($clientRequestId)));
		$qb->executeStatement();
	}
}
