<?php

declare(strict_types=1);

/**
 * Null out `tariff_rule_set_id` references that point at rule sets which no
 * longer exist in `at_tariff_rule_sets`.
 *
 * The column is nullable and has no foreign key by design (draft rule sets
 * may be deleted while a vacation layer still references them). Before the
 * reader-side degradation (typed TariffRuleSetUnavailableException + layer
 * fallback), those dangling ids produced DoesNotExistException 500s in
 * user-detail loads and absence creation — the exact incident a customer
 * fixed by hand in `at_org_vacation_defaults` / `at_user_vacation_policies`
 * (support ticket 2026-10-23, documented under
 * documentation/arbeitszeitcheck/bugs/20261022-admin-user-detail-dangling-tariff-500.md).
 *
 * Reader-side degradation already prevents the crash; this step removes the
 * corpses so every vacation-resolution stops emitting
 * `tariff_rule_set_unavailable` trace rows and admins see clean layer data.
 * It is deliberately a NULL-out, never a row delete: a NULL reference means
 * "no tariff configured → inherit/lower layer", which is the documented
 * fallback semantic — dropping the whole policy row would silently discard
 * the admin's other policy values (manual_days, description, windows).
 *
 * Idempotent: rows already NULL or pointing at existing rule sets are
 * untouched; safe to run on every `occ upgrade`. Audit: a single
 * `tariff_rule_set_refs_cleaned` entry per run that actually fixed rows,
 * carrying per-table counts — matching the audit trail the tariff rule set
 * admin actions already write.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class CleanDanglingTariffRuleSetRefs implements IRepairStep
{
	/**
	 * Vacation/entitlement layer tables that carry a nullable
	 * `tariff_rule_set_id` without a foreign key. Literal names on purpose:
	 * upgrade-path code must not reference app constants that may not exist
	 * in the still-loaded previous release (see UpgradePathConstantsSafetyTest).
	 */
	private const TABLES = [
		'at_org_vacation_defaults',
		'at_model_vacation_defaults',
		'at_team_vacation_policies',
		'at_user_vacation_policies',
		'at_se_policy',
	];

	public function __construct(
		private IDBConnection $db,
		private AuditLogMapper $auditLogMapper,
	) {
	}

	public function getName(): string
	{
		return 'Clear dangling tariff rule set references in vacation policy layers';
	}

	public function run(IOutput $output): void
	{
		try {
			// Fresh installs run migrations first; the guard only matters for
			// partially-migrated or restored datasets.
			if (!$this->db->tableExists('at_tariff_rule_sets')) {
				return;
			}

			$counts = [];
			$total = 0;
			foreach (self::TABLES as $table) {
				try {
					if (!$this->db->tableExists($table)) {
						continue;
					}
					$affected = (int)$this->db->executeStatement(
						'UPDATE `*PREFIX*' . $table . '` SET `tariff_rule_set_id` = NULL'
						. ' WHERE `tariff_rule_set_id` IS NOT NULL'
						. ' AND `tariff_rule_set_id` NOT IN (SELECT `id` FROM `*PREFIX*at_tariff_rule_sets`)'
					);
					if ($affected > 0) {
						$counts[$table] = $affected;
						$total += $affected;
					}
				} catch (\Throwable $e) {
					// One broken layer table must not block cleanup of the rest.
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'CleanDanglingTariffRuleSetRefs: failed for table ' . $table,
						['app' => 'arbeitszeitcheck', 'exception' => $e]
					);
				}
			}

			if ($total === 0) {
				return;
			}

			try {
				$this->auditLogMapper->logAction(
					'system',
					'tariff_rule_set_refs_cleaned',
					'tariff_rule_set',
					null,
					null,
					['cleaned' => $counts, 'total' => $total],
					'system'
				);
			} catch (\Throwable $e) {
				// Audit failure must not undo the cleanup itself.
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'CleanDanglingTariffRuleSetRefs: audit write failed',
					['app' => 'arbeitszeitcheck', 'exception' => $e]
				);
			}

			$output->info(sprintf(
				'Cleared %d dangling tariff rule set reference(s): %s.',
				$total,
				implode(', ', array_map(
					static fn (string $table, int $count): string => $table . '=' . $count,
					array_keys($counts),
					$counts
				))
			));
		} catch (\Throwable $e) {
			// Non-fatal: log and continue; the next `occ upgrade` re-tries.
			$output->warning('Could not clear dangling tariff rule set references: ' . $e->getMessage());
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'CleanDanglingTariffRuleSetRefs failed: ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
		}
	}
}
