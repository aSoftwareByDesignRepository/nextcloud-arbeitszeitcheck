<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for vacation unit (Phase C).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

/**
 * @param list<string> $filters
 */
function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$suiteFilters = [
	'VacationUnitServiceTest',
	'VacationAllocationHoursModeTest',
	'VacationUnitMigrationGateTest',
	'VacationUnitMigrationConversionTest',
	'VacationUnitMigrationLockTest',
	'VacationUnitMigrationPendingHealTest',
	'VacationUnitHealOnReadContractTest',
	'VacationUnitMigrationReverseAbsenceTest',
	'AbsenceVacationHoursRangeExpandTest',
	'AbsenceHoursParamsForwardingContractTest',
	'VacationUnitAwareClientGateTest',
	'VacationHoursDebitServiceTest',
	'LayeredVacationAdminDaysConvertTest',
	'AdminVacationLayersUnitCopyContractTest',
	'VacationHoursEstimateEndpointContractTest',
	'VacationAllocationHoursSplitTest',
	'AbsenceServiceTest::testCreateAbsenceVacationHoursWeekendWithDurationRejected',
	'AbsenceServiceTest::testCreateAbsenceVacationZeroWorkingDays',
	'AbsenceVacationHoursShortenDebitTest',
	'AbsenceVacationUnitMigrateIdleGateTest',
	'AbsenceVacationUnitMigrateIdleCallSitesTest',
	'AbsenceVacationApprovalScopeLockTest',
	'VacationRolloverServiceTest',
	'VacationAllocationRefreshOpenTest',
	'VacationUnitMigrationWithIdleSharedTest',
];

echo "== baseline vacation unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'drop_client_gate',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => 'if ($targetUnit === Constants::VACATION_UNIT_HOURS && !$clientConfirmed) {
				throw new \\RuntimeException(Constants::VAC_UNIT_CLIENT_GATE);
			}',
		'to' => 'if (false) {
				throw new \\RuntimeException(Constants::VAC_UNIT_CLIENT_GATE);
			}',
		'filters' => ['VacationUnitMigrationGateTest::testHoursWithoutClientConfirmationThrowsGate'],
	],
	[
		'name' => 'ignore_duration_hours_debit',
		'file' => 'lib/Service/VacationAllocationService.php',
		'from' => 'if ($rawHours !== null && is_finite((float)$rawHours) && (float)$rawHours > 0) {
			$hours = (float)$rawHours;',
		'to' => 'if (false && $rawHours !== null && is_finite((float)$rawHours) && (float)$rawHours > 0) {
			$hours = (float)$rawHours;',
		'filters' => ['VacationAllocationHoursModeTest::testFourHourBookingDebitsPureHoursFromTwoHundred'],
	],
	[
		'name' => 'hours_mode_always_false',
		'file' => 'lib/Service/VacationUnitService.php',
		'from' => 'return $raw === Constants::VACATION_UNIT_HOURS
			? Constants::VACATION_UNIT_HOURS
			: Constants::VACATION_UNIT_DAYS;',
		'to' => 'return Constants::VACATION_UNIT_DAYS;',
		'filters' => ['VacationUnitServiceTest::testHoursModeAndConversion'],
	],
	[
		'name' => 'skip_carryover_max_rescale',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => '$new = $toHours
			? round($val * $hoursPerDay, 2, PHP_ROUND_HALF_UP)
			: round($val / $hoursPerDay, 2, PHP_ROUND_HALF_UP);
		$capMax = $toHours ? 4000.0 : 366.0;
		$new = max(0.0, min($capMax, $new));
		$this->config->setAppValue(
			\'arbeitszeitcheck\',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
			(string)$new
		);',
		'to' => '// mutated: skip carryover max rescale
		return;',
		'filters' => ['VacationUnitMigrationConversionTest::testCarryoverMaxConfigRescalesWithFactor'],
	],
	[
		'name' => 'hours_sanitize_still_366',
		'file' => 'lib/Service/VacationProrationService.php',
		'from' => 'return round(max(0.0, min($maxAmount, $value)), 2, PHP_ROUND_HALF_UP);',
		'to' => 'return round(max(0.0, min(366.0, $value)), 2, PHP_ROUND_HALF_UP);',
		'filters' => ['VacationUnitMigrationConversionTest::testHoursCeilingAllowsFourHundredHourEntitlement'],
	],
	[
		'name' => 'reintroduce_org_mapper_double_convert',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => '$this->rescaleManualAmountColumn(\'at_org_vacation_defaults\', \'manual_days\', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn(\'at_model_vacation_defaults\', \'manual_days\', $scaleFactor, $toHours);',
		'to' => 'if ($this->orgDefaultMapper !== null) {
					try {
						$org = $this->orgDefaultMapper->findActiveByDate(new \\DateTimeImmutable(\'today\'));
						if ($org !== null && $org->getManualDays() !== null) {
							$manual = (float)$org->getManualDays();
							if ($toHours) {
								$org->setManualDays(round($manual * $scaleFactor, 2, PHP_ROUND_HALF_UP));
							} else {
								$org->setManualDays($scaleFactor > 0.0001 ? round($manual / $scaleFactor, 2, PHP_ROUND_HALF_UP) : 0.0);
							}
							$this->orgDefaultMapper->update($org);
						}
					} catch (\\Throwable) {
					}
				}
				$this->rescaleManualAmountColumn(\'at_org_vacation_defaults\', \'manual_days\', $scaleFactor, $toHours);
				$this->rescaleManualAmountColumn(\'at_model_vacation_defaults\', \'manual_days\', $scaleFactor, $toHours);',
		'filters' => ['VacationUnitMigrationConversionTest::testOrgDefaultMapperIsNeverUpdatedDuringMigrate'],
	],
	[
		'name' => 'always_expand_despite_require',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '$authoritative = !empty($data[\'require_duration_hours\']);
			if (!$authoritative && $workingDays >= 1.99 && abs($hours - $oneDay) < 0.011) {
				return $maxForRange;
			}',
		'to' => '$authoritative = !empty($data[\'require_duration_hours\']);
			if ($workingDays >= 1.99 && abs($hours - $oneDay) < 0.011) {
				return $maxForRange;
			}',
		'filters' => ['AbsenceVacationHoursRangeExpandTest::testAuthoritativeOneDayTotalIsNotExpanded'],
	],
	[
		'name' => 'skip_holiday_clamp',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => 'if ($hours > $maxForRange + 0.011) {
				return $maxForRange;
			}',
		'to' => 'if (false && $hours > $maxForRange + 0.011) {
				return $maxForRange;
			}',
		'filters' => [
			'AbsenceVacationHoursRangeExpandTest::testHolidayClampCapsWeekdayEstimate',
			'AbsenceVacationHoursRangeExpandTest::testScheduleNetsHolidayOvershootAlwaysClamped',
		],
	],
	[
		'name' => 'drop_migration_lock',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => 'if ($locking !== null) {
			try {
				$locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, \'Vacation unit migration\');
			} catch (LockedException $e) {
				throw new \\RuntimeException(\'VAC_UNIT_MIGRATE_IN_PROGRESS\', 0, $e);
			}
		}',
		'to' => 'if (false && $locking !== null) {
			try {
				$locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, \'Vacation unit migration\');
			} catch (LockedException $e) {
				throw new \\RuntimeException(\'VAC_UNIT_MIGRATE_IN_PROGRESS\', 0, $e);
			}
		}',
		'filters' => ['VacationUnitMigrationLockTest::testLockedMigrationThrowsInProgress'],
	],
	[
		'name' => 'flip_unit_before_commit',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => '$this->db->commit();
			} catch (\\Throwable $e) {
				$this->db->rollBack();
				$this->clearPendingMigration();
				throw $e;
			}

			// Persist factor + unit only after DB commit (IConfig is not transactional).
			$this->persistUnitFlipAfterCommit($targetUnit, $hoursPerDay, $scaleFactor, $toHours, $clientConfirmed);
			$this->clearPendingMigration();',
		'to' => '$this->config->setAppValue(\'arbeitszeitcheck\', Constants::CONFIG_VACATION_UNIT, $targetUnit);
				$this->config->setAppValue(
					\'arbeitszeitcheck\',
					Constants::CONFIG_VACATION_HOURS_PER_DAY,
					(string)$hoursPerDay
				);
				$this->db->commit();
			} catch (\\Throwable $e) {
				$this->db->rollBack();
				$this->clearPendingMigration();
				throw $e;
			}

			// mutated: unit already flipped before commit
			$this->persistUnitFlipAfterCommit($targetUnit, $hoursPerDay, $scaleFactor, $toHours, $clientConfirmed);
			$this->clearPendingMigration();',
		'filters' => ['VacationUnitMigrationLockTest::testUnitConfigWrittenOnlyAfterSuccessfulCommit'],
	],
	[
		'name' => 'skip_pending_heal_without_audit',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => 'if (!$this->hasCommittedMigrationAudit($pending)) {
			// Exclusive lock held ⇒ migrate is not mid-TX. No matching audit means
			// the rewrite never committed (or rolled back) — drop stale flag.
			$this->clearPendingMigration();
			return false;
		}',
		'to' => 'if (false && !$this->hasCommittedMigrationAudit($pending)) {
			// Exclusive lock held ⇒ migrate is not mid-TX. No matching audit means
			// the rewrite never committed (or rolled back) — drop stale flag.
			$this->clearPendingMigration();
			return false;
		}',
		'filters' => ['VacationUnitMigrationPendingHealTest::testStalePendingWithoutAuditIsClearedWithoutFlip'],
	],
	[
		'name' => 'heal_clears_pending_while_migrate_locked',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => '} catch (LockedException) {
				// Migrate (or another heal) holds exclusive — leave pending untouched.
				return false;
			}',
		'to' => '} catch (LockedException) {
				// mutated: clear pending while migrate holds exclusive
				$this->clearPendingMigration();
				return false;
			}',
		'filters' => ['VacationUnitMigrationPendingHealTest::testHealDoesNotClearPendingWhenExclusiveLockHeldByMigrate'],
	],
	[
		'name' => 'heal_accepts_stale_token_audit',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => 'if ($token !== \'\') {
				if ((string)($new[\'migration_token\'] ?? \'\') !== $token) {
					continue;
				}
				return true;
			}',
		'to' => 'if ($token !== \'\') {
				if (false && (string)($new[\'migration_token\'] ?? \'\') !== $token) {
					continue;
				}
				return true;
			}',
		'filters' => ['VacationUnitMigrationPendingHealTest::testStalePriorAuditDoesNotMatchNewTokenPending'],
	],
	[
		'name' => 'legacy_heal_ignores_unparseable_started_at',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => 'try {
				$started = new \\DateTimeImmutable($startedAt);
			} catch (\\Throwable) {
				continue;
			}',
		'to' => 'try {
				$started = new \\DateTimeImmutable($startedAt);
			} catch (\\Throwable) {
				return true;
			}',
		'filters' => ['VacationUnitMigrationPendingHealTest::testLegacyPendingRequiresParseableStartedAtNotUnitFlipAlone'],
	],
	[
		'name' => 'skip_heal_on_get_unit',
		'file' => 'lib/Service/VacationUnitService.php',
		'from' => 'public function getUnit(): string
	{
		$this->healPendingMigrationIfPossible();
		return $this->readUnitFromConfig();
	}',
		'to' => 'public function getUnit(): string
	{
		return $this->readUnitFromConfig();
	}',
		'filters' => ['VacationUnitHealOnReadContractTest::testGetUnitHealsPendingViaMigrationService'],
	],
	[
		'name' => 'rollover_log_caps_at_366',
		'file' => 'lib/Db/VacationRolloverLogMapper.php',
		'from' => '$e->setAmount(max(0.0, min(4000.0, $amount)));',
		'to' => '$e->setAmount(max(0.0, min(366.0, $amount)));',
		'filters' => ['VacationRolloverLogMapperHoursCapTest::testInsertLogAllowsHourAmountsAbove366'],
	],
	[
		'name' => 'skip_unaware_hours_gate',
		'file' => 'lib/Service/DashboardWidgetDataService.php',
		'from' => 'if ($vacationUnitAwareClient || $unit !== Constants::VACATION_UNIT_HOURS) {
			$payload[\'vacationClientUpdateRequired\'] = false;
			return $payload;
		}',
		'to' => 'if (true || $vacationUnitAwareClient || $unit !== Constants::VACATION_UNIT_HOURS) {
			$payload[\'vacationClientUpdateRequired\'] = false;
			return $payload;
		}',
		'filters' => ['VacationUnitAwareClientGateTest::testUnawareClientGetsDayEquivalentsInHoursMode'],
	],
	[
		'name' => 'reverse_clear_hours_only',
		'file' => 'lib/Service/VacationUnitMigrationService.php',
		'from' => '} else {
						// Hours→days: debit amount lives in duration_hours (partial days too).
						// Rewrite days to the day-equivalent so reverse keeps 4h → 0.5d, not 1d.
						if ($storedHours !== null && is_finite((float)$storedHours) && (float)$storedHours >= 0) {
							$newDays = $scaleFactor > 0.0001
								? round((float)$storedHours / $scaleFactor, 2, PHP_ROUND_HALF_UP)
								: 0.0;
							$absence->setDays($newDays);
						}
						$absence->setDurationHours(null);
					}',
		'to' => '} else {
						$absence->setDurationHours(null);
					}',
		'filters' => ['VacationUnitMigrationReverseAbsenceTest::testReverseHoursToDaysUsesDurationHoursNotCalendarDays'],
	],
	[
		'name' => 'flat_eight_ignores_schedule',
		'file' => 'lib/Service/VacationHoursDebitService.php',
		'from' => 'if ($schedule !== null) {
			$hours = $schedule->requiredHoursForDateRange(
				$startDt,
				$endDt,
				fn (\\DateTime $d): float => $this->holidayService->getHolidayWeightForUser($userId, $d)
			);',
		'to' => 'if (false && $schedule !== null) {
			$hours = $schedule->requiredHoursForDateRange(
				$startDt,
				$endDt,
				fn (\\DateTime $d): float => $this->holidayService->getHolidayWeightForUser($userId, $d)
			);',
		'filters' => ['VacationHoursDebitServiceTest::testBanssWeekUsesScheduleNetsNotFlatEight'],
	],
	[
		'name' => 'skip_admin_days_to_hours',
		'file' => 'lib/Service/LayeredVacationDefaultsService.php',
		'from' => 'if ($days !== null && $this->vacationUnitService !== null) {
				$payload[\'manualDays\'] = $this->vacationUnitService->adminDaysToStoredAmount($days);
			}',
		'to' => 'if ($days !== null && $this->vacationUnitService !== null) {
				$payload[\'manualDays\'] = $days;
			}',
		'filters' => ['LayeredVacationAdminDaysConvertTest::testUpsertConvertsAdminDaysToStoredHours'],
	],
	[
		'name' => 'present_ignores_stored_key',
		'file' => 'lib/Service/LayeredVacationDefaultsService.php',
		'from' => 'if (array_key_exists(\'manualAmountStored\', $summary) && $summary[\'manualAmountStored\'] !== null) {
			$stored = (float)$summary[\'manualAmountStored\'];
		} else {
			$stored = array_key_exists(\'manualDays\', $summary) && $summary[\'manualDays\'] !== null
				? (float)$summary[\'manualDays\']
				: null;
		}',
		'to' => 'if (false && array_key_exists(\'manualAmountStored\', $summary) && $summary[\'manualAmountStored\'] !== null) {
			$stored = (float)$summary[\'manualAmountStored\'];
		} else {
			$stored = array_key_exists(\'manualDays\', $summary) && $summary[\'manualDays\'] !== null
				? (float)$summary[\'manualDays\']
				: null;
		}',
		'filters' => ['LayeredVacationAdminDaysConvertTest::testPresentRoundTripDoesNotDoubleConvert'],
	],
	[
		'name' => 'validate_resolve_drops_user_range',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '$durationHours = $this->resolveVacationDurationHours(
					$data,
					(float)$totalRequested,
					$userId,
					$startDate,
					$endDate
				);',
		'to' => '$durationHours = $this->resolveVacationDurationHours(
					$data,
					(float)$totalRequested
				);',
		'filters' => ['VacationHoursEstimateEndpointContractTest::testValidatePathPassesUserAndRangeIntoResolve'],
	],
	[
		'name' => 'reintroduce_safe_days_one',
		'file' => 'lib/Service/VacationHoursDebitService.php',
		'from' => '$safeDays = max(0.0, $workingDays);',
		'to' => '$safeDays = $workingDays > 0.009 ? $workingDays : 1.0;',
		'filters' => [
			'VacationHoursDebitServiceTest::testWeekendOnlyRangeReturnsZeroHoursNotInventedDay',
			'VacationHoursEstimateEndpointContractTest::testDebitServiceNeverInventSafeDaysOne',
		],
	],
	[
		'name' => 'hours_empty_range_allows_duration',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '// Hours mode: schedule-/holiday-aware debit is authoritative (Sat work models
			// can have net hours while Mon–Fri “working days” is 0).
			if ($hoursMode && ($durationHours === null || $durationHours < 0.01)) {
				throw new \\Exception($this->l10n->t(\'Vacation must include at least one working day. The selected period contains only weekends or public holidays.\'));
			}',
		'to' => '// mutated: skip empty debit gate (allow 0 h vacation)
			if (false && $hoursMode && ($durationHours === null || $durationHours < 0.01)) {
				throw new \\Exception($this->l10n->t(\'Vacation must include at least one working day. The selected period contains only weekends or public holidays.\'));
			}',
		'filters' => [
			'VacationHoursEstimateEndpointContractTest::testValidatePathPassesUserAndRangeIntoResolve',
			'AbsenceServiceTest::testCreateAbsenceVacationHoursWeekendWithDurationRejected',
		],
	],
	[
		'name' => 'hours_empty_gate_reintroduces_mon_fri_only',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '// Hours mode: schedule-/holiday-aware debit is authoritative (Sat work models
			// can have net hours while Mon–Fri “working days” is 0).
			if ($hoursMode && ($durationHours === null || $durationHours < 0.01)) {
				throw new \\Exception($this->l10n->t(\'Vacation must include at least one working day. The selected period contains only weekends or public holidays.\'));
			}',
		'to' => '// mutated: Mon–Fri day count blocks schedule weekend work
			if ($hoursMode && ($totalRequested < 0.01 || $durationHours === null || $durationHours < 0.01)) {
				throw new \\Exception($this->l10n->t(\'Vacation must include at least one working day. The selected period contains only weekends or public holidays.\'));
			}',
		'filters' => [
			'VacationHoursEstimateEndpointContractTest::testValidatePathPassesUserAndRangeIntoResolve',
		],
	],
	[
		'name' => 'widget_default_aware_fail_open',
		'file' => 'lib/Service/DashboardWidgetDataService.php',
		'from' => 'bool $vacationUnitAwareClient = false',
		'to' => 'bool $vacationUnitAwareClient = true',
		'filters' => ['VacationHoursEstimateEndpointContractTest::testWidgetDefaultsToUnawareClient'],
	],
	[
		'name' => 'shorten_reverts_to_day_ratio',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => 'if ($oldHoursStored !== null && is_finite((float)$oldHoursStored) && (float)$oldHoursStored > 0 && $oldEstHours > 0.0001) {
			$ratio = min(1.0, (float)$oldHoursStored / $oldEstHours);
			$absence->setDurationHours(
				$this->vacationUnitService->roundAmount($newEstHours * $ratio)
			);
			return;
		}',
		'to' => 'if ($oldHoursStored !== null && is_finite((float)$oldHoursStored) && (float)$oldHoursStored > 0 && $oldDays > 0.0001 && $workingDays >= 0) {
			$absence->setDurationHours(
				$this->vacationUnitService->roundAmount(((float)$oldHoursStored) * ($workingDays / $oldDays))
			);
			return;
		}',
		'filters' => ['AbsenceVacationHoursShortenDebitTest::testShortenFullWeekToThursdayUsesBanSSNetsNotDayRatio'],
	],
	[
		'name' => 'shorten_fill_drops_server_may_fill',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '$this->applyVacationDurationHours(
			$absence,
			[\'server_may_fill_hours\' => true],
			$workingDays
		);',
		'to' => '$this->applyVacationDurationHours(
			$absence,
			[],
			$workingDays
		);',
		'filters' => ['AbsenceVacationHoursShortenDebitTest::testMissingStoredHoursRefillsFromSchedule'],
	],
	[
		'name' => 'drop_idle_assert_create_absence',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => 'if (($data[\'type\'] ?? \'\') === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->validateAbsenceData($data, $userId, null, null, []);',
		'to' => 'if (($data[\'type\'] ?? \'\') === Absence::TYPE_VACATION) {
				// mutated: skip idle assert
			}
			$this->validateAbsenceData($data, $userId, null, null, []);',
		'filters' => ['AbsenceVacationUnitMigrateIdleCallSitesTest::testVacationMutationEntryPointsAssertMigrateIdle'],
	],
	[
		'name' => 'drop_idle_assert_approve_absence',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => 'if ($lockAbsence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->db->beginTransaction();
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \\Exception($this->l10n->t(\'Absence not found\'));
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				throw new ConcurrentDecisionException(
					$this->l10n->t(\'This absence was already decided by another manager.\')
				);
			}
			$this->assertAbsenceMutable($absence);

			if ($absence->getType() === Absence::TYPE_VACATION) {
				$sd = $absence->getStartDate();
				$ed = $absence->getEndDate();
				if ($sd && $ed) {
					$this->lockVacationApprovalScope($absence->getUserId(), $sd, $ed);
				}
			}',
		'to' => 'if ($lockAbsence->getType() === Absence::TYPE_VACATION) {
				// mutated: skip idle assert
			}
			$this->db->beginTransaction();
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \\Exception($this->l10n->t(\'Absence not found\'));
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				throw new ConcurrentDecisionException(
					$this->l10n->t(\'This absence was already decided by another manager.\')
				);
			}
			$this->assertAbsenceMutable($absence);

			if ($absence->getType() === Absence::TYPE_VACATION) {
				$sd = $absence->getStartDate();
				$ed = $absence->getEndDate();
				if ($sd && $ed) {
					$this->lockVacationApprovalScope($absence->getUserId(), $sd, $ed);
				}
			}',
		'filters' => ['AbsenceVacationUnitMigrateIdleCallSitesTest::testVacationMutationEntryPointsAssertMigrateIdle'],
	],
	[
		'name' => 'calendar_lock_ignores_windows',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '$windows = $this->vacationYearWindowResolver->windowsOverlappingRange($userId, $startDate, $endDate);
		if ($windows === []) {
			$startYear = (int)$startDate->format(\'Y\');
			$endYear = (int)$endDate->format(\'Y\');
			$scopeStart = new \\DateTime(sprintf(\'%04d-01-01\', min($startYear, $endYear)));
			$scopeEnd = new \\DateTime(sprintf(\'%04d-12-31\', max($startYear, $endYear)));
		} else {
			$scopeStart = null;
			$scopeEnd = null;
			foreach ($windows as $window) {
				$wStart = \\DateTime::createFromImmutable($window->startInclusive);
				$wEnd = \\DateTime::createFromImmutable($window->lastInclusiveDay());
				if ($scopeStart === null || $wStart < $scopeStart) {
					$scopeStart = $wStart;
				}
				if ($scopeEnd === null || $wEnd > $scopeEnd) {
					$scopeEnd = $wEnd;
				}
			}
			/** @var \\DateTime $scopeStart */
			/** @var \\DateTime $scopeEnd */
		}',
		'to' => '$startYear = (int)$startDate->format(\'Y\');
		$endYear = (int)$endDate->format(\'Y\');
		$scopeStart = new \\DateTime(sprintf(\'%04d-01-01\', min($startYear, $endYear)));
		$scopeEnd = new \\DateTime(sprintf(\'%04d-12-31\', max($startYear, $endYear)));',
		'filters' => ['AbsenceVacationApprovalScopeLockTest::testLockVacationApprovalScopeUsesWindowsOverlappingRange'],
	],
	[
		'name' => 'rollover_hours_mode_clears_hours_column',
		'file' => 'lib/Service/VacationRolloverService.php',
		'from' => '$write = function () use ($userId, $fromYear, $toYear, $amount, $parts): array {
			$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
			$this->vacationYearBalanceMapper->upsert(
				$userId,
				$toYear,
				$amount,
				$hoursMode ? $amount : null,
				!$hoursMode
			);',
		'to' => '$write = function () use ($userId, $fromYear, $toYear, $amount, $parts): array {
			$hoursMode = false;
			$this->vacationYearBalanceMapper->upsert(
				$userId,
				$toYear,
				$amount,
				$hoursMode ? $amount : null,
				!$hoursMode
			);',
		'filters' => ['VacationRolloverServiceTest::testHoursModeUpsertWritesCarryoverHoursAndDoesNotClearDaysColumnAsDaysMode'],
	],
	[
		'name' => 'rollover_skips_migrate_idle_assert',
		'file' => 'lib/Service/VacationRolloverService.php',
		'from' => 'if ($this->vacationUnitMigrationService !== null) {
			return $this->vacationUnitMigrationService->withIdleShared($write);
		}

		return $write();',
		'to' => 'if (false && $this->vacationUnitMigrationService !== null) {
			return $this->vacationUnitMigrationService->withIdleShared($write);
		}

		return $write();',
		'filters' => ['VacationRolloverServiceTest::testProcessBlocksWhenVacationUnitMigrationInProgress'],
	],
	[
		'name' => 'absence_shared_idle_releases_immediately',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_SHARED, \'Vacation unit migrate idle shared\');
		} catch (\\OCP\\Lock\\LockedException $e) {
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t(\'Vacation unit migration is in progress. Please try again in a moment.\'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		// Re-check pending after acquire (flag may flip while waiting for the lock).
		$pendingAfter = trim((string)$this->config->getAppValue(
			\'arbeitszeitcheck\',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			\'\'
		));
		if ($pendingAfter !== \'\') {
			try {
				$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_SHARED);
			} catch (\\Throwable) {
				// best-effort
			}
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t(\'Vacation unit migration is in progress. Please try again in a moment.\'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		$this->heldVacationUnitMigrateSharedLock = $key;',
		'to' => '$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_SHARED, \'Vacation unit migrate idle shared\');
			try {
				$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_SHARED);
			} catch (\\Throwable) {
				// mutated: release immediately (TOCTOU)
			}
		} catch (\\OCP\\Lock\\LockedException $e) {
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t(\'Vacation unit migration is in progress. Please try again in a moment.\'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		$this->heldVacationUnitMigrateSharedLock = null;',
		'filters' => ['AbsenceVacationUnitMigrateIdleGateTest::testIdleAcquiresSharedAndHoldsUntilRelease'],
	],
	[
		'name' => 'refresh_open_aborts_on_blank_instead_of_skip',
		'file' => 'lib/Service/VacationAllocationService.php',
		'from' => '$uid = trim((string)$userId);
			if ($uid === \'\') {
				continue;
			}',
		'to' => '$uid = trim((string)$userId);
			if ($uid === \'\') {
				throw new \\RuntimeException(\'blank user\');
			}',
		'filters' => ['VacationAllocationRefreshOpenTest::testRefreshCountsSuccessfulUsersAndSkipsEmptyIds'],
	],
	[
		'name' => 'refresh_swallows_failures_without_reporting',
		'file' => 'lib/Service/VacationAllocationService.php',
			'from' => '} catch (\\Throwable) {
				// Per-user failures must not abort the org-wide mode switch.
				$failed[] = $uid;
			}',
		'to' => '} catch (\\Throwable) {
				// mutated: silent failure
			}',
		'filters' => ['VacationAllocationRefreshOpenTest::testRefreshCountsSuccessfulUsersAndSkipsEmptyIds'],
	],
];

$failed = 0;
foreach ($mutations as $m) {
	$source = $appRoot . '/' . $m['file'];
	$backup = $source . '.mutbak';
	$filters = $m['filters'] ?? $suiteFilters;
	echo "== mutate: {$m['name']} ==\n";
	$src = file_get_contents($source);
	if ($src === false || !str_contains($src, $m['from'])) {
		fwrite(STDERR, "Mutation source fragment not found: {$m['name']}\n");
		$failed++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($m['from'], $m['to'], $src));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['name']} (tests should have failed)\n");
		$failed++;
	} else {
		echo "KILLED {$m['name']}\n";
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failed}\n");
	exit(1);
}
echo "All vacation-unit mutations killed.\n";
exit(0);
