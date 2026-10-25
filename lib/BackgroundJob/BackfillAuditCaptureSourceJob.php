<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Migration\Version1046Date20260921120000;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Continues Offline-Sync capture_source backfill if Version1046 hit its time budget.
 */
class BackfillAuditCaptureSourceJob extends TimedJob
{
	private const CHUNK = 500;

	public function __construct(
		ITimeFactory $timeFactory,
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		parent::__construct($timeFactory);
		// Hourly until idle; cheap no-op when nothing left.
		$this->setInterval(60 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'audit capture-source backfill',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping audit capture-source backfill — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		if (!$this->db->tableExists('at_audit')) {
			return;
		}

		$updated = 0;
		try {
			// Probe: column missing → stop quietly (pre-1046 installs).
			$probe = $this->db->getQueryBuilder();
			$probe->select(Version1046Date20260921120000::COLUMN)
				->from('at_audit')
				->setMaxResults(1);
			$probe->executeQuery()->closeCursor();

			do {
				$select = $this->db->getQueryBuilder();
				$select->select('id')
					->from('at_audit')
					->where($select->expr()->isNull(Version1046Date20260921120000::COLUMN))
					->andWhere($select->expr()->like(
						'new_values',
						$select->createNamedParameter(Version1046Date20260921120000::LEGACY_JSON_NEEDLE)
					))
					->setMaxResults(self::CHUNK);

				$rows = $select->executeQuery()->fetchAll();
				$ids = [];
				foreach ($rows as $row) {
					$id = isset($row['id']) ? (int)$row['id'] : 0;
					if ($id > 0) {
						$ids[] = $id;
					}
				}
				if ($ids === []) {
					break;
				}

				$update = $this->db->getQueryBuilder();
				$update->update('at_audit')
					->set(
						Version1046Date20260921120000::COLUMN,
						$update->createNamedParameter(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC)
					)
					->where($update->expr()->in(
						'id',
						$update->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
					));
				$updated += $update->executeStatement();
			} while (\count($ids) === self::CHUNK && $updated < self::CHUNK * 4);
		} catch (\Throwable $e) {
			// Unknown column / missing table → nothing to do until migration runs.
			$this->logger->debug('arbeitszeitcheck: capture_source backfill skipped: ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
			]);
			return;
		}

		if ($updated > 0) {
			$this->logger->info('arbeitszeitcheck: capture_source backfill updated {n} row(s)', [
				'app' => 'arbeitszeitcheck',
				'n' => $updated,
			]);
		}
	}
}
