<?php

declare(strict_types=1);

/**
 * MH-19: one-shot post-upgrade cache invalidation + malformed-row report
 * for the effective-dated assignment rollout.
 *
 * The segmented resolver changes required-hours semantics for users whose
 * assignment rows are anything but "exactly one window covering today"
 * (D-03): multiple rows, coverage gaps, overlaps, only-ended windows. Their
 * cached vacation/overtime stats would silently show pre-upgrade numbers
 * until the TTL or the next mutation hit — this step bumps the stats-cache
 * keyspace once per affected user so the first post-upgrade read already
 * shows corrected values (R-08).
 *
 * Malformed legacy rows (end < start, NULL start, dangling model_id) are
 * tolerated by the resolver — treated as non-covering — but are reported
 * here once so admins can clean them up (R-09). The step never crashes on
 * bad data: findings go to the upgrade transcript, not to an exception.
 *
 * Idempotent: the cache bump is a fresh token every run (harmless), the
 * report is read-only. Literal table/column names on purpose: upgrade-path
 * code must not reference app constants or newly added mapper methods that
 * may not exist in the still-loaded previous release (see
 * UpgradePathConstantsSafetyTest).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class ReportAssignmentStateAfterUpgrade implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly AbsenceService $absenceService,
		private readonly AuditLogMapper $auditLogMapper,
	) {
	}

	public function getName(): string
	{
		return 'Invalidate stats caches for users with segmented working-time assignments';
	}

	public function run(IOutput $output): void
	{
		if (!$this->db->tableExists('at_user_models')) {
			return;
		}

		$knownModels = [];
		if ($this->db->tableExists('at_models')) {
			$result = $this->db->getQueryBuilder()
				->select('id')
				->from('at_models')
				->executeQuery();
			while ($row = $result->fetch()) {
				$knownModels[(int)$row['id']] = true;
			}
			$result->closeCursor();
		}

		$today = (new \DateTime('today'))->format('Y-m-d');
		$result = $this->db->getQueryBuilder()
			->select('id', 'user_id', 'working_time_model_id', 'start_date', 'end_date')
			->from('at_user_models')
			->orderBy('user_id', 'ASC')
			->addOrderBy('start_date', 'ASC')
			->executeQuery();

		$byUser = [];
		while ($row = $result->fetch()) {
			$byUser[(string)$row['user_id']][] = $row;
		}
		$result->closeCursor();

		$invalidated = 0;
		$malformedTotal = 0;
		foreach ($byUser as $userId => $rows) {
			$affected = count($rows) > 1;
			$coversToday = false;
			$cursor = null;
			foreach ($rows as $row) {
				$start = (string)$row['start_date'];
				$end = $row['end_date'] === null ? null : (string)$row['end_date'];
				if ($start === '') {
					$malformedTotal++;
					$output->warning("arbeitszeitcheck: user {$userId} assignment #{$row['id']}: NULL start_date (treated as non-covering)");
					continue;
				}
				if ($end !== null && $end < $start) {
					$malformedTotal++;
					$output->warning("arbeitszeitcheck: user {$userId} assignment #{$row['id']}: end_date {$end} before start_date {$start} (treated as non-covering)");
					continue;
				}
				if (!isset($knownModels[(int)$row['working_time_model_id']])) {
					$malformedTotal++;
					$output->warning("arbeitszeitcheck: user {$userId} assignment #{$row['id']}: dangling working_time_model_id {$row['working_time_model_id']} (treated as non-covering)");
				}
				if ($cursor !== null && $cursor < '9999-12-31' && $start > date('Y-m-d', strtotime($cursor . ' +1 day'))) {
					$affected = true; // coverage gap between windows
				}
				if ($cursor !== null && $start <= $cursor) {
					$affected = true; // overlapping windows
				}
				if ($start <= $today && ($end === null || $end >= $today)) {
					$coversToday = true;
				}
				if ($end === null) {
					// Open window swallows the tail; sentinel still lets a
					// later row flag overlap while never tripping the gap
					// check or strtotime overflow above.
					$cursor = '9999-12-31';
					continue;
				}
				if ($cursor === null || $end > $cursor) {
					$cursor = $end;
				}
			}
			if (!$coversToday) {
				$affected = true; // every window already ended
			}
			if (!$affected) {
				continue;
			}
			try {
				$this->absenceService->invalidateVacationStatsCache($userId);
				$invalidated++;
			} catch (\Throwable) {
				// cache invalidation is best-effort; TTL is the safety net
			}
		}

		$summary = sprintf(
			'scanned %d users, invalidated stats cache for %d, malformed rows: %d',
			count($byUser),
			$invalidated,
			$malformedTotal,
		);
		$output->info('arbeitszeitcheck: ' . $summary);

		if ($invalidated > 0 || $malformedTotal > 0) {
			try {
				$this->auditLogMapper->logAction(
					'system',
					'assignment_upgrade_cache_invalidation',
					'user_working_time_model',
					null,
					null,
					[
						'users_scanned' => count($byUser),
						'caches_invalidated' => $invalidated,
						'malformed_rows' => $malformedTotal,
					],
					'system:upgrade'
				);
			} catch (\Throwable) {
				// audit write failure must never fail the upgrade
			}
		}
	}
}
