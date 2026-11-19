<?php

declare(strict_types=1);

/**
 * Heal physically impossible `daily_hours` on working time models.
 *
 * The column had no upper bound until 1.12.1, so legacy rows can carry
 * garbage like 78.0 (a weekly or even monthly figure typed into the daily
 * field). Every read path now derives the day Soll from `weekly_hours / 5`
 * when the stored daily is outside (0, 24] — but the stored value stayed
 * corrupt and kept poisoning surfaces that print it verbatim (admin model
 * table, exports, future consumers).
 *
 * Recovery rule (identical to the read-path fallback):
 *   healed = weekly_hours / 5 when that lands in (0, 24], else 8.0 —
 *   the schema default for the column.
 *
 * Scope: `daily_hours > 24` or `daily_hours <= 0` on non-on-demand rows.
 * A zero daily is the legitimate state of an on-demand contract (Arbeit
 * auf Abruf — no contractual Soll), so those rows are never touched; a
 * zero/negative daily on a contracted model is the same poison the new
 * `daily < 0 || daily > 24` validation bound rejects.
 *
 * Idempotent: healed rows no longer match the predicate, so re-running
 * `occ upgrade` is a no-op. Audited: one `daily_hours_healed` audit row
 * per run that fixed anything, carrying the per-model old→new values, and
 * one IOutput line per healed model for the upgrade transcript.
 *
 * Literal table/column names on purpose: upgrade-path code must not
 * reference app constants that may not exist in the still-loaded previous
 * release (see UpgradePathConstantsSafetyTest).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class HealCorruptDailyHours implements IRepairStep
{
	/** Column default — the fallback when no weekly contract can be derived. */
	private const DAILY_FALLBACK = 8.0;

	/** Physical upper bound — mirrors WorkingTimeModel::validate(). */
	private const DAILY_MAX = 24.0;

	public function __construct(
		private IDBConnection $db,
		private AuditLogMapper $auditLogMapper,
	) {
	}

	public function getName(): string
	{
		return 'Heal corrupt daily_hours values on working time models';
	}

	public function run(IOutput $output): void
	{
		try {
			// Fresh installs run migrations first; the guard only matters for
			// partially-migrated or restored datasets.
			if (!$this->db->tableExists('at_models')) {
				return;
			}

			$rows = $this->db->executeQuery(
				'SELECT `id`, `daily_hours`, `weekly_hours` FROM `*PREFIX*at_models`'
				. ' WHERE `daily_hours` > ' . self::DAILY_MAX
				. ' OR (`daily_hours` <= 0 AND `type` <> \'on_demand\')'
			)->fetchAll();

			if ($rows === []) {
				return;
			}

			$healed = [];
			foreach ($rows as $row) {
				$id = (int)$row['id'];
				$old = (float)$row['daily_hours'];
				$weekly = (float)$row['weekly_hours'];
				$derived = $weekly > 0.0 ? round($weekly / 5, 2) : 0.0;
				$new = ($derived > 0.0 && $derived <= self::DAILY_MAX)
					? $derived
					: self::DAILY_FALLBACK;

				$this->db->executeStatement(
					'UPDATE `*PREFIX*at_models` SET `daily_hours` = ? WHERE `id` = ?',
					[$new, $id]
				);

				$healed[] = ['id' => $id, 'old' => $old, 'new' => $new, 'weekly' => $weekly];
				$output->info(sprintf(
					'Healed daily_hours on working time model #%d: %s -> %s (weekly_hours=%s).',
					$id,
					rtrim(rtrim(number_format($old, 2, '.', ''), '0'), '.'),
					rtrim(rtrim(number_format($new, 2, '.', ''), '0'), '.'),
					rtrim(rtrim(number_format($weekly, 2, '.', ''), '0'), '.')
				));
			}

			try {
				$this->auditLogMapper->logAction(
					'system',
					'daily_hours_healed',
					'working_time_model',
					null,
					null,
					['healed' => $healed, 'total' => count($healed)],
					'system'
				);
			} catch (\Throwable $e) {
				// Audit failure must not undo the healing itself.
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'HealCorruptDailyHours: audit write failed',
					['app' => 'arbeitszeitcheck', 'exception' => $e]
				);
			}
		} catch (\Throwable $e) {
			// Non-fatal: log and continue; the next `occ upgrade` re-tries.
			$output->warning('Could not heal corrupt daily_hours values: ' . $e->getMessage());
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'HealCorruptDailyHours failed: ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
		}
	}
}
