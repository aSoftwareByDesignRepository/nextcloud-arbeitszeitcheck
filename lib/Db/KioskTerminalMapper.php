<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class KioskTerminalMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_kiosk_terminals', KioskTerminal::class);
	}

	public function findByTerminalId(string $terminalId): ?KioskTerminal
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)));
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	/** @return KioskTerminal[] */
	public function findAllActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return KioskTerminal[] */
	public function findPendingPairing(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Atomically activate a pending terminal. Returns 1 on success, 0 if already
	 * claimed, expired, or not pending (safe under concurrent pair requests).
	 *
	 * unique-conflict:ok — only SETs status/token_hash/label/pairing_* on a row
	 * matched by terminal_id; terminal_id itself (the at_kiosk_term_tid_uq
	 * column) is never SET, so the update cannot collide.
	 */
	public function claimPendingAsActive(
		string $terminalId,
		string $tokenHash,
		?\DateTimeInterface $now,
		?string $label = null,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter('active'))
			->set('token_hash', $qb->createNamedParameter($tokenHash))
			->set('pairing_code_hash', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('pairing_expires_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		if ($label !== null && $label !== '') {
			$qb->set('label', $qb->createNamedParameter($label));
		}
		$qb->where($qb->expr()->eq('terminal_id', $qb->createNamedParameter($terminalId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		if ($now !== null) {
			$qb->andWhere($qb->expr()->gt(
				'pairing_expires_at',
				$qb->createNamedParameter($now->format('Y-m-d H:i:s')),
			));
		}
		return $qb->executeStatement();
	}
}
