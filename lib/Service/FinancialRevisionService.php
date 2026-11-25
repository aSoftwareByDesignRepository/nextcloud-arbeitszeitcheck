<?php

declare(strict_types=1);

/**
 * Financial revision service (SPEC optional-absence-corrections-hourly-leave-year-end §9.6/§13.4).
 *
 * Every balance-touching writer bumps at_balance_rev(user, year) INSIDE
 * the same atomic unit as its business write. Year-end preview binds to
 * this revision so late source changes surface as needs_review instead of
 * silently executing against stale numbers.
 *
 * Global inputs (policy changes, catalog changes, holiday tables) bump a
 * shared epoch domain instead of fanning out per user — a full-table
 * bump inside one transaction would not scale.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BalanceRevisionMapper;
use OCP\IDBConnection;

class FinancialRevisionService
{
	/** Shared epoch domains for global financial inputs. */
	public const EPOCH_POLICY = 'workforce_policy';
	public const EPOCH_ABSENCE_CATALOG = 'absence_catalog';
	public const EPOCH_HOLIDAYS = 'holidays';
	public const EPOCH_MODEL_CATALOG = 'working_time_models';

	private ?\OCA\ArbeitszeitCheck\Db\YearResetItemMapper $itemMapper = null;

	public function __construct(
		private readonly BalanceRevisionMapper $balanceRevisionMapper,
		private readonly IDBConnection $db,
	) {
	}

	private function itemMapper(): \OCA\ArbeitszeitCheck\Db\YearResetItemMapper
	{
		return $this->itemMapper ??= \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\YearResetItemMapper::class);
	}

	/**
	 * Bump the revision of one user for every calendar year a write can
	 * affect. MUST be called inside the writer's atomic unit — a revision
	 * bump that commits without its write (or vice versa) breaks the
	 * needs_review detection contract.
	 *
	 * @param int[] $years
	 */
	public function bumpForWrite(string $userId, array $years): int
	{
		$latest = 0;
		foreach ($years as $year) {
			$latest = max($latest, $this->balanceRevisionMapper->bump($userId, (int)$year));
		}
		// §9.6 needs_review: flag reset items whose stored source token no
		// longer matches, inside the same transaction as the write itself.
		foreach ($years as $year) {
			$this->itemMapper()->flagStaleForUser(
				$userId,
				(int)$year,
				$this->sourceRevision($userId, (int)$year),
			);
		}
		return $latest;
	}

	/**
	 * Bump a bounded year span (cumulative balance: a write dated in year Y
	 * affects every later closing year's balance). Callers clamp the span
	 * themselves; the guard below caps pathological ranges.
	 */
	public function bumpForWriteRange(string $userId, int $fromYear, int $toYear): void
	{
		$current = (int)(new \DateTime('now'))->format('Y');
		$from = max(2000, min($fromYear, $toYear));
		$to = min($current, max($fromYear, $toYear));
		if ($to - $from > 30) {
			$to = $from + 30;
		}
		if ($to < $from) {
			return;
		}
		$years = range($from, $to);
		foreach ($years as $year) {
			$this->balanceRevisionMapper->bump($userId, (int)$year);
		}
		foreach ($years as $year) {
			$this->itemMapper()->flagStaleForUser(
				$userId,
				(int)$year,
				$this->sourceRevision($userId, (int)$year),
			);
		}
	}

	/** Convenience for single-date writes. */
	public function bumpForDate(string $userId, \DateTimeInterface $date): int
	{
		return $this->bumpForWrite($userId, [(int)$date->format('Y')]);
	}

	public function current(string $userId, int $calendarYear): int
	{
		return $this->balanceRevisionMapper->current($userId, $calendarYear);
	}

	/**
	 * Bump a shared epoch for global financial inputs. Serialized by the
	 * row's primary key; the increment is atomic at statement level.
	 *
	 * A missing `at_balance_epoch` table (upgrade not yet applied / schema
	 * drift) must never roll back the caller's business write — the epoch
	 * is bookkeeping, the write is the product. Degrade to a logged no-op;
	 * the EnsureSchema repair recreates the table on the next run.
	 */
	public function bumpEpoch(string $domain): int
	{
		try {
			return $this->doBumpEpoch($domain);
		} catch (\OCP\DB\Exception $e) {
			if (!$this->db->tableExists('at_balance_epoch')) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'at_balance_epoch missing — epoch bump skipped for domain ' . $domain . ' (schema not yet upgraded)',
					['exception' => $e]
				);
				return 0;
			}
			throw $e;
		}
	}

	private function doBumpEpoch(string $domain): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('at_balance_epoch')
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('domain', $qb->createNamedParameter($domain)));
		if ($qb->executeStatement() === 0) {
			try {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('at_balance_epoch')
					->values([
						'domain' => $ins->createNamedParameter($domain),
						'revision' => $ins->createNamedParameter(1),
					]);
				$ins->executeStatement();
				return 1;
			} catch (\Exception) {
				$qb->executeStatement();
			}
		}
		return $this->epoch($domain);
	}

	public function epoch(string $domain): int
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('revision')->from('at_balance_epoch')
				->where($qb->expr()->eq('domain', $qb->createNamedParameter($domain)));
			$res = $qb->executeQuery();
			$row = $res->fetch();
			$res->closeCursor();
			return $row === false ? 0 : (int)$row['revision'];
		} catch (\OCP\DB\Exception $e) {
			// Missing table = pre-upgrade schema: epoch is 0, never a 500.
			if (!$this->db->tableExists('at_balance_epoch')) {
				return 0;
			}
			throw $e;
		}
	}

	/**
	 * Combined source-revision token for a user: per-year counter plus the
	 * global epochs that shape the balance math. Stored on year-end items
	 * so late changes anywhere in the input set are detectable.
	 */
	public function sourceRevision(string $userId, int $calendarYear): string
	{
		$parts = [
			'u' . $this->current($userId, $calendarYear),
			'p' . $this->epoch(self::EPOCH_POLICY),
			'c' . $this->epoch(self::EPOCH_ABSENCE_CATALOG),
			'h' . $this->epoch(self::EPOCH_HOLIDAYS),
			'm' . $this->epoch(self::EPOCH_MODEL_CATALOG),
		];
		return implode('.', $parts);
	}

	/**
	 * A stored source revision is stale when ANY component changed.
	 */
	public function isStale(string $storedSourceRevision, string $userId, int $calendarYear): bool
	{
		return !hash_equals($this->sourceRevision($userId, $calendarYear), $storedSourceRevision);
	}
}
