<?php

declare(strict_types=1);

/**
 * Days ↔ hours vacation unit migration wizard (Q3=A, Q8 gate).
 *
 * Bestandskunden safety:
 * - Default unit remains days; this service only runs on explicit admin Apply.
 * - Hours migration requires clientConfirmed (VAC_UNIT_CLIENT_GATE).
 * - Amount columns are converted exactly once (no double-rescale of org defaults).
 * - Carryover max config is rescaled with the same factor.
 * - Calendar working-day weights on absences are preserved on reverse.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

final class VacationUnitMigrationService
{
	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
		private VacationUnitService $unitService,
		private AbsenceMapper $absenceMapper,
		private VacationYearBalanceMapper $yearBalanceMapper,
		private AuditLogMapper $auditLogMapper,
		private ?OrgVacationDefaultMapper $orgDefaultMapper = null,
		private ?UserVacationPolicyAssignmentMapper $userPolicyMapper = null,
		private ?ILockingProvider $lockingProvider = null,
	) {
	}

	/**
	 * Block vacation balance / absence mutations while a days↔hours migration
	 * holds its exclusive lock or has a pending crash-window flag.
	 *
	 * Probe-only: use {@see withIdleShared()} when the caller will mutate balances
	 * so the shared lock is held for the whole critical section (no TOCTOU).
	 *
	 * @throws \RuntimeException message VAC_UNIT_MIGRATE_IN_PROGRESS
	 */
	public function assertIdle(): void
	{
		$pending = $this->readPendingMigration();
		if ($pending !== null) {
			throw new \RuntimeException(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS);
		}
		$locking = $this->lockingProvider;
		if ($locking === null) {
			return;
		}
		$lockKey = DbLockKeys::vacationUnitMigration();
		try {
			$locking->acquireLock($lockKey, ILockingProvider::LOCK_SHARED, 'Vacation unit migrate idle probe');
			try {
				$locking->releaseLock($lockKey, ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
				// best-effort
			}
		} catch (LockedException $e) {
			throw new \RuntimeException(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS, 0, $e);
		}
	}

	/**
	 * Run $fn while holding a shared lock on the vacation-unit migration key.
	 * Blocks exclusive migration for the duration; concurrent writers may share.
	 * Re-checks the pending crash-window flag after acquire (closes probe TOCTOU).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 * @throws \RuntimeException message VAC_UNIT_MIGRATE_IN_PROGRESS
	 */
	public function withIdleShared(callable $fn): mixed
	{
		$pending = $this->readPendingMigration();
		if ($pending !== null) {
			throw new \RuntimeException(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS);
		}
		$locking = $this->lockingProvider;
		if ($locking === null) {
			return $fn();
		}
		$lockKey = DbLockKeys::vacationUnitMigration();
		try {
			$locking->acquireLock($lockKey, ILockingProvider::LOCK_SHARED, 'Vacation unit idle shared');
		} catch (LockedException $e) {
			throw new \RuntimeException(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS, 0, $e);
		}
		try {
			if ($this->readPendingMigration() !== null) {
				throw new \RuntimeException(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS);
			}
			return $fn();
		} finally {
			try {
				$locking->releaseLock($lockKey, ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
				// best-effort
			}
		}
	}

	/**
	 * Finish a crash window after DB commit but before IConfig unit flip.
	 * Safe to call from migrate(), getUnit/status, and repair paths.
	 * Reads unit from IConfig directly (not VacationUnitService) to avoid
	 * re-entrancy when getUnit() triggers heal.
	 *
	 * Heal always takes the exclusive migrate lock (unless the caller already
	 * holds it). If migrate is mid-flight, heal no-ops without clearing pending —
	 * otherwise a concurrent getUnit() could drop the crash-window flag before
	 * the audit commits and leave amounts converted with the unit flag stuck.
	 *
	 * @param bool $alreadyHoldingExclusive True when called from migrate() under LOCK_EXCLUSIVE
	 * @return bool true when a pending flip was completed
	 */
	public function completePendingMigrationIfNeeded(bool $alreadyHoldingExclusive = false): bool
	{
		$locking = $this->lockingProvider;
		$lockKey = DbLockKeys::vacationUnitMigration();
		$acquiredHere = false;
		if (!$alreadyHoldingExclusive && $locking !== null) {
			try {
				$locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'Vacation unit migrate heal');
				$acquiredHere = true;
			} catch (LockedException) {
				// Migrate (or another heal) holds exclusive — leave pending untouched.
				return false;
			}
		}

		try {
			return $this->completePendingMigrationUnlocked();
		} finally {
			if ($acquiredHere && $locking !== null) {
				try {
					$locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
					// best-effort
				}
			}
		}
	}

	/**
	 * Heal body assuming exclusive migrate lock is held (or locking disabled).
	 */
	private function completePendingMigrationUnlocked(): bool
	{
		$pending = $this->readPendingMigration();
		if ($pending === null) {
			return false;
		}

		$current = $this->readConfiguredUnit();
		$target = (string)($pending['target'] ?? '');
		if ($target !== Constants::VACATION_UNIT_HOURS && $target !== Constants::VACATION_UNIT_DAYS) {
			$this->clearPendingMigration();
			return false;
		}

		if ($current === $target) {
			// Flip already applied; clear stale pending.
			$this->clearPendingMigration();
			return false;
		}

		if (!$this->hasCommittedMigrationAudit($pending)) {
			// Exclusive lock held ⇒ migrate is not mid-TX. No matching audit means
			// the rewrite never committed (or rolled back) — drop stale flag.
			$this->clearPendingMigration();
			return false;
		}

		$hoursPerDay = (float)($pending['hours_per_day'] ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY);
		$scaleFactor = (float)($pending['scale_factor'] ?? $hoursPerDay);
		$toHours = $target === Constants::VACATION_UNIT_HOURS;
		$clientConfirmed = !empty($pending['client_confirmed']);
		$this->persistUnitFlipAfterCommit($target, $hoursPerDay, $scaleFactor, $toHours, $clientConfirmed);
		$this->clearPendingMigration();

		return true;
	}

	/**
	 * Hours-mode migration needs additive columns from migration 1037.
	 * Fail fast with a stable code instead of a generic SQL error mid-transaction.
	 */
	private function assertHoursMigrationSchemaReady(string $targetUnit): void
	{
		if ($targetUnit !== Constants::VACATION_UNIT_HOURS) {
			return;
		}
		foreach (['at_absences' => 'duration_hours', 'at_vacation_year_balance' => 'carryover_hours'] as $table => $column) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select($column)
					->from($table)
					->setMaxResults(1);
				$result = $qb->executeQuery();
				$result->closeCursor();
			} catch (\Throwable $e) {
				if ($this->isUnknownColumnSchemaError($e, $column)) {
					throw new \RuntimeException(Constants::VAC_UNIT_SCHEMA_OUTDATED, 0, $e);
				}
				throw $e;
			}
		}
	}

	private function isUnknownColumnSchemaError(\Throwable $e, string $column): bool
	{
		$haystack = strtolower($e->getMessage());
		if (!str_contains($haystack, 'unknown column') && !str_contains($haystack, 'no such column')) {
			return false;
		}

		return str_contains($haystack, strtolower($column));
	}

	private function readConfiguredUnit(): string
	{
		$raw = strtolower(trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT,
			Constants::DEFAULT_VACATION_UNIT
		)));
		return $raw === Constants::VACATION_UNIT_HOURS
			? Constants::VACATION_UNIT_HOURS
			: Constants::VACATION_UNIT_DAYS;
	}

	/**
	 * Switch org unit with foolproof conversion of open balances and vacation absences.
	 *
	 * @return array{unit: string, converted_absences: int, converted_balances: int, hours_per_day: float}
	 */
	public function migrate(
		string $targetUnit,
		float $hoursPerDay,
		bool $clientConfirmed,
		string $actorUserId,
	): array {
		$targetUnit = $targetUnit === Constants::VACATION_UNIT_HOURS
			? Constants::VACATION_UNIT_HOURS
			: Constants::VACATION_UNIT_DAYS;

		$this->assertHoursMigrationSchemaReady($targetUnit);

		if ($hoursPerDay < 0.25 || $hoursPerDay > 24.0 || !is_finite($hoursPerDay)) {
			throw new \InvalidArgumentException('Invalid hours_per_day');
		}
		$hoursPerDay = round($hoursPerDay, 2, PHP_ROUND_HALF_UP);

		$lockKey = DbLockKeys::vacationUnitMigration();
		$locking = $this->lockingProvider;
		if ($locking !== null) {
			try {
				$locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'Vacation unit migration');
			} catch (LockedException $e) {
				throw new \RuntimeException('VAC_UNIT_MIGRATE_IN_PROGRESS', 0, $e);
			}
		}

		try {
			// Heal crash window before reading current unit (already hold exclusive).
			$this->completePendingMigrationIfNeeded(true);

			// Re-read inside lock — concurrent Apply must no-op if already converted.
			// peekUnit avoids nested heal that would try to re-acquire exclusive.
			$current = $this->unitService->peekUnit();
			if ($targetUnit === $current) {
				// Same unit: allow updating hours_per_day for presets / future conversions
				// without rewriting balances (amounts already live in the active unit).
				$previous = $this->unitService->getHoursPerDay();
				if (abs($previous - $hoursPerDay) > 0.0001) {
					$this->config->setAppValue(
						'arbeitszeitcheck',
						Constants::CONFIG_VACATION_HOURS_PER_DAY,
						(string)$hoursPerDay
					);
					$this->auditLogMapper->logAction(
						$actorUserId,
						'vacation_hours_per_day_updated',
						'app_config',
						0,
						['hours_per_day' => $previous],
						['hours_per_day' => $hoursPerDay, 'vacation_unit' => $current],
						$actorUserId
					);
				}
				return [
					'unit' => $current,
					'converted_absences' => 0,
					'converted_balances' => 0,
					'hours_per_day' => $hoursPerDay,
				];
			}

			if ($targetUnit === Constants::VACATION_UNIT_HOURS && !$clientConfirmed) {
				throw new \RuntimeException(Constants::VAC_UNIT_CLIENT_GATE);
			}

			$toHours = $targetUnit === Constants::VACATION_UNIT_HOURS;
			$convertedAbsences = 0;
			$convertedBalances = 0;

			// Reverse (hours→days) must use the factor from the last unit flip so a
			// later hours_per_day preset tweak cannot silently inflate/deflate amounts.
			$scaleFactor = $hoursPerDay;
			if (!$toHours) {
				$storedFactor = (float)str_replace(',', '.', (string)$this->config->getAppValue(
					'arbeitszeitcheck',
					Constants::CONFIG_VACATION_LAST_CONVERT_FACTOR,
					''
				));
				if (is_finite($storedFactor) && $storedFactor >= 0.25 && $storedFactor <= 24.0) {
					$scaleFactor = round($storedFactor, 2, PHP_ROUND_HALF_UP);
				}
			}

			// Mark in-flight before DB rewrite. IConfig is not transactional:
			// after commit, completePendingMigrationIfNeeded() finishes the unit
			// flip from the committed audit if the process dies mid-flip.
			$pendingStartedAt = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
			$migrationToken = bin2hex(random_bytes(16));
			$this->writePendingMigration([
				'from' => $current,
				'target' => $targetUnit,
				'hours_per_day' => $hoursPerDay,
				'scale_factor' => $scaleFactor,
				'client_confirmed' => $clientConfirmed,
				'actor' => $actorUserId,
				'started_at' => $pendingStartedAt,
				'token' => $migrationToken,
			]);

			// Rescale with the local scale factor only — do not write
			// hours_per_day / vacation_unit to IConfig until after DB commit
			// (config is not transactional; early writes race concurrent readers).
			$this->db->beginTransaction();
			try {
				// Carryover rows: rewrite amounts. Q3=A: legacy column holds active-unit amount.
				$qb = $this->db->getQueryBuilder();
				$qb->select('*')->from('at_vacation_year_balance');
				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$userId = (string)$row['user_id'];
					$year = (int)$row['year'];
					$days = (float)$row['carryover_days'];
					if ($toHours) {
						$hours = round($days * $scaleFactor, 2, PHP_ROUND_HALF_UP);
						$this->yearBalanceMapper->upsert($userId, $year, $hours, $hours);
					} else {
						$srcHours = isset($row['carryover_hours']) && $row['carryover_hours'] !== null
							? (float)$row['carryover_hours']
							: $days;
						$newDays = $scaleFactor > 0.0001
							? round($srcHours / $scaleFactor, 2, PHP_ROUND_HALF_UP)
							: 0.0;
						$this->yearBalanceMapper->upsert($userId, $year, $newDays, null, true);
					}
					$convertedBalances++;
				}
				$result->closeCursor();

				$absQb = $this->db->getQueryBuilder();
				$absQb->select('id')
					->from('at_absences')
					->where($absQb->expr()->eq('type', $absQb->createNamedParameter(Absence::TYPE_VACATION)));
				$absResult = $absQb->executeQuery();
				while ($absRow = $absResult->fetch()) {
					$id = (int)$absRow['id'];
					try {
						$absence = $this->absenceMapper->find($id);
					} catch (\Throwable) {
						continue;
					}
					$dayAmount = (float)($absence->getDays() ?? 0.0);
					$storedHours = $absence->getDurationHours();
					if ($toHours) {
						// Prefer existing duration_hours (authoritative debit); else day×factor.
						if ($storedHours !== null && is_finite((float)$storedHours) && (float)$storedHours > 0) {
							$absence->setDurationHours(round((float)$storedHours, 2, PHP_ROUND_HALF_UP));
						} else {
							$absence->setDurationHours(round(max(0.0, $dayAmount) * $scaleFactor, 2, PHP_ROUND_HALF_UP));
						}
					} else {
						// Hours→days: debit amount lives in duration_hours (partial days too).
						// Rewrite days to the day-equivalent so reverse keeps 4h → 0.5d, not 1d.
						if ($storedHours !== null && is_finite((float)$storedHours) && (float)$storedHours >= 0) {
							$newDays = $scaleFactor > 0.0001
								? round((float)$storedHours / $scaleFactor, 2, PHP_ROUND_HALF_UP)
								: 0.0;
							$absence->setDays($newDays);
						}
						$absence->setDurationHours(null);
					}
					$this->absenceMapper->update($absence);
					$convertedAbsences++;
				}
				$absResult->closeCursor();

				$this->rescaleManualAmountColumn('at_org_vacation_defaults', 'manual_days', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn('at_model_vacation_defaults', 'manual_days', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn('at_team_vacation_policies', 'manual_days', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn('at_user_vacation_policies', 'manual_days', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn('at_user_working_time_models', 'vacation_days_per_year', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn('at_working_time_models', 'vacation_days_per_year', $scaleFactor, $toHours);
				$this->rescaleUserSettingsVacationDays($scaleFactor, $toHours);
				$this->rescaleTariffRuleModuleDayAmounts($scaleFactor, $toHours);

				$this->auditLogMapper->logAction(
					$actorUserId,
					'vacation_unit_migrated',
					'app_config',
					0,
					['vacation_unit' => $current, 'hours_per_day' => $scaleFactor],
					[
						'vacation_unit' => $targetUnit,
						'hours_per_day' => $hoursPerDay,
						'scale_factor' => $scaleFactor,
						'migration_token' => $migrationToken,
						'converted_absences' => $convertedAbsences,
						'converted_balances' => $convertedBalances,
						'client_confirmed' => $clientConfirmed,
					],
					$actorUserId
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				$this->clearPendingMigration();
				throw $e;
			}

			// Persist factor + unit only after DB commit (IConfig is not transactional).
			$this->persistUnitFlipAfterCommit($targetUnit, $hoursPerDay, $scaleFactor, $toHours, $clientConfirmed);
			$this->clearPendingMigration();

			return [
				'unit' => $targetUnit,
				'converted_absences' => $convertedAbsences,
				'converted_balances' => $convertedBalances,
				'hours_per_day' => $hoursPerDay,
			];
		} finally {
			if ($locking !== null) {
				try {
					$locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
					// best-effort
				}
			}
		}
	}

	/**
	 * @param array{from: string, target: string, hours_per_day: float, scale_factor: float, client_confirmed: bool, actor: string, started_at: string, token: string} $pending
	 */
	private function writePendingMigration(array $pending): void
	{
		$json = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			is_string($json) ? $json : ''
		);
	}

	/**
	 * @return array{from?: string, target?: string, hours_per_day?: float, scale_factor?: float, client_confirmed?: bool, actor?: string, started_at?: string, token?: string}|null
	 */
	private function readPendingMigration(): ?array
	{
		$raw = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			''
		));
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function clearPendingMigration(): void
	{
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			''
		);
	}

	/**
	 * Match the in-flight pending marker to the committed audit for THIS attempt.
	 * Token match is authoritative; without a token (legacy pending) require
	 * parseable started_at + scale_factor so a prior days→hours audit cannot
	 * heal a later crash window early.
	 *
	 * @param array{from?: string, target?: string, started_at?: string, scale_factor?: float, token?: string} $pending
	 */
	private function hasCommittedMigrationAudit(array $pending): bool
	{
		$target = (string)($pending['target'] ?? '');
		$from = (string)($pending['from'] ?? '');
		$startedAt = trim((string)($pending['started_at'] ?? ''));
		$token = trim((string)($pending['token'] ?? ''));
		$pendingScale = isset($pending['scale_factor']) ? (float)$pending['scale_factor'] : null;

		$logs = $this->auditLogMapper->findByAction('vacation_unit_migrated', 10);
		foreach ($logs as $log) {
			$newRaw = $log->getNewValues();
			$oldRaw = $log->getOldValues();
			$new = is_string($newRaw) ? json_decode($newRaw, true) : null;
			$old = is_string($oldRaw) ? json_decode($oldRaw, true) : null;
			if (!is_array($new) || !is_array($old)) {
				continue;
			}
			if ((string)($new['vacation_unit'] ?? '') !== $target) {
				continue;
			}
			if ($from !== '' && (string)($old['vacation_unit'] ?? '') !== $from) {
				continue;
			}

			// Prefer exact attempt identity when present (current writes always set token).
			if ($token !== '') {
				if ((string)($new['migration_token'] ?? '') !== $token) {
					continue;
				}
				return true;
			}

			// Legacy pending without token: require parseable started_at — never
			// accept on unit flip alone (stale prior audit must not match).
			if ($startedAt === '') {
				continue;
			}
			try {
				$started = new \DateTimeImmutable($startedAt);
			} catch (\Throwable) {
				continue;
			}
			$created = $log->getCreatedAt();
			if (!$created instanceof \DateTimeInterface) {
				continue;
			}
			// Allow small clock skew; reject audits clearly older than pending.
			if ($created->getTimestamp() < ($started->getTimestamp() - 5)) {
				continue;
			}
			if ($pendingScale !== null && is_finite($pendingScale)) {
				$auditScale = isset($new['scale_factor'])
					? (float)$new['scale_factor']
					: (isset($new['hours_per_day']) ? (float)$new['hours_per_day'] : null);
				if ($auditScale === null || abs($auditScale - $pendingScale) > 0.001) {
					continue;
				}
			}
			return true;
		}

		return false;
	}

	private function persistUnitFlipAfterCommit(
		string $targetUnit,
		float $hoursPerDay,
		float $scaleFactor,
		bool $toHours,
		bool $clientConfirmed,
	): void {
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_HOURS_PER_DAY,
			(string)$hoursPerDay
		);
		$this->config->setAppValue('arbeitszeitcheck', Constants::CONFIG_VACATION_UNIT, $targetUnit);
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_CLIENT_CONFIRMED,
			($targetUnit === Constants::VACATION_UNIT_HOURS && $clientConfirmed) ? '1' : '0'
		);
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATED_AT,
			(new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM)
		);
		if ($toHours) {
			// Lock the convert factor used for this flip (preset tweaks must not affect reverse).
			$this->config->setAppValue(
				'arbeitszeitcheck',
				Constants::CONFIG_VACATION_LAST_CONVERT_FACTOR,
				(string)$hoursPerDay
			);
		} else {
			$this->config->setAppValue(
				'arbeitszeitcheck',
				Constants::CONFIG_VACATION_LAST_CONVERT_FACTOR,
				''
			);
		}
		$this->rescaleCarryoverMaxConfig($scaleFactor, $toHours);
	}

	/**
	 * Multiply/divide a numeric amount column by hours_per_day for every non-null row.
	 * Table/column names are internal constants only (not user input).
	 */
	private function rescaleManualAmountColumn(string $table, string $column, float $hoursPerDay, bool $toHours): void
	{
		$allowed = [
			'at_org_vacation_defaults' => 'manual_days',
			'at_model_vacation_defaults' => 'manual_days',
			'at_team_vacation_policies' => 'manual_days',
			'at_user_vacation_policies' => 'manual_days',
			'at_user_working_time_models' => 'vacation_days_per_year',
			'at_working_time_models' => 'vacation_days_per_year',
		];
		if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
			return;
		}
		if ($hoursPerDay < 0.0001) {
			return;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', $column)
				->from($table)
				->where($qb->expr()->isNotNull($column));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$id = (int)$row['id'];
				$val = (float)$row[$column];
				if (!is_finite($val)) {
					continue;
				}
				$new = $toHours
					? round($val * $hoursPerDay, 2, PHP_ROUND_HALF_UP)
					: round($val / $hoursPerDay, 2, PHP_ROUND_HALF_UP);
				$upd = $this->db->getQueryBuilder();
				$upd->update($table)
					->set($column, $upd->createNamedParameter($new))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
				$upd->executeStatement();
			}
			$result->closeCursor();
		} catch (\Throwable) {
			// Table may not exist on older DBs; skip.
		}
	}

	/**
	 * Rescale legacy at_settings.vacation_days_per_year for all users.
	 */
	private function rescaleUserSettingsVacationDays(float $hoursPerDay, bool $toHours): void
	{
		if ($hoursPerDay < 0.0001) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'setting_value')
				->from('at_settings')
				->where($qb->expr()->eq('setting_key', $qb->createNamedParameter('vacation_days_per_year')));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$id = (int)$row['id'];
				$raw = trim((string)($row['setting_value'] ?? ''));
				if ($raw === '') {
					continue;
				}
				$val = (float)str_replace(',', '.', $raw);
				if (!is_finite($val)) {
					continue;
				}
				$new = $toHours
					? round($val * $hoursPerDay, 2, PHP_ROUND_HALF_UP)
					: round($val / $hoursPerDay, 2, PHP_ROUND_HALF_UP);
				$upd = $this->db->getQueryBuilder();
				$upd->update('at_settings')
					->set('setting_value', $upd->createNamedParameter((string)$new))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
				$upd->executeStatement();
			}
			$result->closeCursor();
		} catch (\Throwable) {
			// Optional on older installs.
		}
	}

	/**
	 * Rescale day magnitudes inside tariff rule module JSON configs.
	 */
	private function rescaleTariffRuleModuleDayAmounts(float $hoursPerDay, bool $toHours): void
	{
		if ($hoursPerDay < 0.0001) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'module_type', 'config_json')
				->from('at_tariff_rule_modules');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$id = (int)$row['id'];
				$type = (string)($row['module_type'] ?? '');
				$decoded = json_decode((string)($row['config_json'] ?? '{}'), true);
				if (!is_array($decoded)) {
					continue;
				}
				$changed = false;
				$keys = match ($type) {
					'base_formula' => ['reference_days'],
					'additional_entitlements', 'deductions' => ['days'],
					default => [],
				};
				foreach ($keys as $key) {
					if (!isset($decoded[$key]) || !is_numeric($decoded[$key])) {
						continue;
					}
					$val = (float)$decoded[$key];
					if (!is_finite($val)) {
						continue;
					}
					$decoded[$key] = $toHours
						? round($val * $hoursPerDay, 2, PHP_ROUND_HALF_UP)
						: round($val / $hoursPerDay, 2, PHP_ROUND_HALF_UP);
					$changed = true;
				}
				if (!$changed) {
					continue;
				}
				$json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ($json === false) {
					continue;
				}
				$upd = $this->db->getQueryBuilder();
				$upd->update('at_tariff_rule_modules')
					->set('config_json', $upd->createNamedParameter($json))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
				$upd->executeStatement();
			}
			$result->closeCursor();
		} catch (\Throwable) {
			// Tariff tables may be absent.
		}
	}

	/**
	 * Convert CONFIG_VACATION_CARRYOVER_MAX_DAYS with the migration factor.
	 * Empty / unset stays empty (no cap).
	 */
	private function rescaleCarryoverMaxConfig(float $hoursPerDay, bool $toHours): void
	{
		$raw = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
			''
		));
		if ($raw === '' || $hoursPerDay < 0.0001) {
			return;
		}
		$val = (float)str_replace(',', '.', $raw);
		if (!is_finite($val) || $val < 0) {
			return;
		}
		$new = $toHours
			? round($val * $hoursPerDay, 2, PHP_ROUND_HALF_UP)
			: round($val / $hoursPerDay, 2, PHP_ROUND_HALF_UP);
		$capMax = $toHours ? 4000.0 : 366.0;
		$new = max(0.0, min($capMax, $new));
		$this->config->setAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
			(string)$new
		);
	}
}
