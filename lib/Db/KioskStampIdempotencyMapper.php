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

	public function tryInsert(string $terminalId, string $clientRequestId, string $responseJson, int $createdAt): bool
	{
		$entity = new KioskStampIdempotency();
		$entity->setTerminalId($terminalId);
		$entity->setClientRequestId($clientRequestId);
		$entity->setResponseJson($responseJson);
		$entity->setCreatedAt($createdAt);
		try {
			$this->insert($entity);
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
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
