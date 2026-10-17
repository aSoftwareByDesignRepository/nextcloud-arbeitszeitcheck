<?php

declare(strict_types=1);

/**
 * Per-day work-location overrides (Workforce Policy Suite, Phase 3).
 *
 * One row per (user, local_date). Writes are CAS-guarded by `revision`:
 * a missing, stale, or unknown revision on an existing row is a conflict —
 * never merged, never last-writer-wins. Insert-vs-update races are closed
 * by the `at_wl_day_user_uq` unique index; a losing insert is retried once
 * as a CAS update so concurrent first-writes converge deterministically.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDayOverride;
use OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper;
use OCA\ArbeitszeitCheck\Exception\WorkLocationDayConflictException;
use OCP\DB\Exception as DbException;

final class WorkLocationDayOverrideService
{
	public const SOURCE_WEB = 'web';
	public const SOURCE_MOBILE = 'mobile';
	public const SOURCE_ADMIN = 'admin';
	public const SOURCES = [self::SOURCE_WEB, self::SOURCE_MOBILE, self::SOURCE_ADMIN];

	/** Overrides may target today through today + this many days. */
	public const FUTURE_WINDOW_DAYS = 31;

	public const ERROR_REVISION_CONFLICT = 'REVISION_CONFLICT';
	public const ERROR_DATE_OUT_OF_WINDOW = 'DAY_OVERRIDE_OUT_OF_WINDOW';

	public function __construct(
		private readonly WorkLocationDayOverrideMapper $mapper,
		private readonly WorkLocationCatalog $catalog,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly \OCP\Lock\ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * Current override for (user, date) or null.
	 *
	 * @return array{locationCode:string,revision:int}|null
	 */
	public function get(string $userId, string $localDate): ?array
	{
		$row = $this->mapper->findForUserDate($userId, $localDate);
		if ($row === null) {
			return null;
		}
		return [
			'locationCode' => (string)$row->getLocationCode(),
			'revision' => (int)$row->getRevision(),
		];
	}

	/**
	 * Sets (or clears, with $code === null) the override for a day.
	 *
	 * CAS contract (fail-closed):
	 * - existing row: $expectedRevision must equal the stored revision —
	 *   missing/null/mismatched → conflict, no merge;
	 * - no row: $expectedRevision must be null/0 for create — a non-zero
	 *   expectation against a non-existent row is a conflict (the client is
	 *   operating on a deleted or never-created state).
	 *
	 * @param mixed $expectedRevision null|int|string digits
	 * @return array{locationCode:string|null,revision:int}
	 * @throws WorkLocationDayConflictException on CAS failure
	 * @throws \InvalidArgumentException on invalid date/code/source
	 */
	public function setOverride(
		string $userId,
		string $localDate,
		mixed $code,
		mixed $expectedRevision,
		string $source,
		string $actorUserId,
	): array {
		self::assertDateInWindow($localDate);
		if (!in_array($source, self::SOURCES, true)) {
			throw new \InvalidArgumentException('Invalid override source');
		}
		$newCode = $code === null ? null : $this->catalog->normalizeCreatable($code);
		$expected = $this->normalizeRevision($expectedRevision);

		$lock = DbLockKeys::workLocationDay($userId, $localDate);
		$this->lockingProvider->acquireLock(
			$lock,
			\OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE,
			'Work-location day override for user ' . $userId,
		);

		try {
			$existing = $this->mapper->findForUserDate($userId, $localDate);

			if ($existing === null) {
				if ($expected !== null && $expected !== 0) {
					throw new WorkLocationDayConflictException('Override does not exist; reload and retry.');
				}
				if ($newCode === null) {
					return ['locationCode' => null, 'revision' => 0];
				}
				$now = new \DateTime();
				$row = new WorkLocationDayOverride();
				$row->setUserId($userId);
				$row->setLocalDate(new \DateTime($localDate));
				$row->setLocationCode($newCode);
				$row->setRevision(1);
				$row->setSource($source);
				$row->setCreatedBy($actorUserId);
				$row->setCreatedAt($now);
				$row->setUpdatedAt($now);
				try {
					$this->mapper->insert($row);
				} catch (DbException $e) {
					// Unique-index race: another writer created the row between
					// our read and insert. Re-read under the same lock and
					// converge deterministically — identical concurrent writes
					// (double-submit, offline retry) succeed idempotently on the
					// winner's state; divergent writes conflict so the client
					// reloads instead of silently overwriting.
					$existing = $this->mapper->findForUserDate($userId, $localDate);
					if ($existing === null) {
						throw $e;
					}
					if ((string)$existing->getLocationCode() === $newCode) {
						return ['locationCode' => $newCode, 'revision' => (int)$existing->getRevision()];
					}
					throw new WorkLocationDayConflictException('Override was created concurrently; reload and retry.');
				}
				$this->audit($userId, $localDate, null, $newCode, 1, $actorUserId);
				return ['locationCode' => $newCode, 'revision' => 1];
			}

			if ($expected === null || (int)$existing->getRevision() !== $expected) {
				throw new WorkLocationDayConflictException('Stale or missing revision; reload and retry.');
			}

			if ($newCode === null) {
				$this->mapper->deleteForUserDate($userId, $localDate);
				$this->audit($userId, $localDate, (string)$existing->getLocationCode(), null, 0, $actorUserId);
				return ['locationCode' => null, 'revision' => 0];
			}

			if ((string)$existing->getLocationCode() !== $newCode || (string)$existing->getSource() !== $source) {
				$oldCode = (string)$existing->getLocationCode();
				$this->applyUpdate($existing, $newCode, $source);
				$this->audit($userId, $localDate, $oldCode, $newCode, (int)$existing->getRevision(), $actorUserId, true);
			}
			return ['locationCode' => $newCode, 'revision' => (int)$existing->getRevision()];
		} finally {
			$this->lockingProvider->releaseLock($lock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Resolution step 2 of the documented chain: returns the override code
	 * for a day when it still resolves to a creatable location. Inactive or
	 * orphaned codes are skipped (the chain continues to the policy layer)
	 * — historical rows keep rendering through the catalog's unknown-safe
	 * label path.
	 */
	public function effectiveCodeFor(string $userId, string $localDate): ?string
	{
		$row = $this->mapper->findForUserDate($userId, $localDate);
		if ($row === null) {
			return null;
		}
		$code = (string)$row->getLocationCode();
		return $this->catalog->isCreatable($code) ? $code : null;
	}

	private function applyUpdate(WorkLocationDayOverride $row, string $newCode, string $source): void
	{
		$row->setLocationCode($newCode);
		$row->setRevision((int)$row->getRevision() + 1);
		$row->setSource($source);
		$row->setUpdatedAt(new \DateTime());
		$this->mapper->update($row);
	}

	private function audit(string $userId, string $localDate, ?string $oldCode, ?string $newCode, int $revision, string $actorUserId, bool $isUpdate = false): void
	{
		$this->auditLogMapper->logAction(
			$userId,
			$isUpdate ? 'work_location_day_updated' : ($newCode === null ? 'work_location_day_cleared' : 'work_location_day_set'),
			'work_location_day',
			null,
			['local_date' => $localDate, 'location_code' => $oldCode],
			['local_date' => $localDate, 'location_code' => $newCode, 'revision' => $revision],
			$actorUserId,
		);
	}

	/**
	 * @return int|null Strict digits-only revision; null when absent.
	 * @throws WorkLocationDayConflictException on a malformed token — unknown
	 *         CAS tokens conflict rather than fail open.
	 */
	private function normalizeRevision(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_int($raw)) {
			return $raw >= 0 ? $raw : throw new WorkLocationDayConflictException('Invalid revision token.');
		}
		if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
			return (int)$raw;
		}
		throw new WorkLocationDayConflictException('Invalid revision token.');
	}

	private function assertDateInWindow(string $localDate): void
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
			throw new \InvalidArgumentException('Invalid date format');
		}
		$today = new \DateTime('today');
		$max = (clone $today)->modify('+' . self::FUTURE_WINDOW_DAYS . ' days');
		$target = new \DateTime($localDate);
		if ($target < $today || $target > $max) {
			throw new \InvalidArgumentException(self::ERROR_DATE_OUT_OF_WINDOW);
		}
	}
}
