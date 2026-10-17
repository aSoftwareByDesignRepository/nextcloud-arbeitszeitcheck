<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculation;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Declarative per-day calculation engine (spec §9.5).
 *
 * Hard invariants:
 * - every real minute enters the calculation at most once
 *   (work_during_travel segments subtract from linked TimeEntry minutes);
 * - top_up is never a debit: max(0, account_target - actual_accountable);
 * - export minutes never change compliance or the time account;
 * - all inputs, rules and results are hashed into a snapshot;
 * - `actual_work_minutes` here is read from TimeEntry only — trips never
 *   write worked minutes anywhere.
 */
final class BusinessTripCalculationEngine
{
	public function __construct(
		private readonly BusinessTripRuleConfigValidator $ruleValidator,
		private readonly TravelComplianceClassifier $classifier,
		private readonly DailyWorkingHoursCalculator $workingHours,
	) {
	}

	/**
	 * Calculate every local day of a trip. Pure: returns row material, the
	 * caller owns persistence.
	 *
	 * Rule sets resolve per local day — an assignment change mid-trip may
	 * legitimately switch the winning rule set between days.
	 *
	 * @param list<BusinessTripSegment> $segments
	 * @param list<TimeEntry> $timeEntries linked entries (already ownership-checked)
	 * @param callable(string $localDate): ?BusinessTripRuleSet $resolveRuleSet
	 * @param ?int $normalCommuteMinutes user profile for CH-style profiles
	 * @return array{days:list<array<string,mixed>>, warnings:list<string>}
	 * @throws BusinessTripException on invalid rule config or unclassifiable facts
	 */
	public function calculate(
		BusinessTrip $trip,
		array $segments,
		array $timeEntries,
		callable $resolveRuleSet,
		?int $normalCommuteMinutes,
	): array {
		$tz = new \DateTimeZone((string)$trip->getEvaluationTimezone());
		$tripStart = \DateTimeImmutable::createFromInterface($trip->getStartsAtUtc());
		$tripEnd = \DateTimeImmutable::createFromInterface($trip->getEndsAtUtc());
		if ($tripEnd <= $tripStart) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'The trip must end after it starts.', 400, ['endsAt' => 'before_start']);
		}

		$localStart = $tripStart->setTimezone($tz)->setTime(0, 0);
		$localEnd = $tripEnd->setTimezone($tz)->setTime(0, 0);
		$singleDay = $localStart->format('Y-m-d') === $localEnd->format('Y-m-d');

		$days = [];
		$warnings = [];
		$configCache = [];
		for ($cursor = $localStart; $cursor <= $localEnd; $cursor = $cursor->modify('+1 day')) {
			$localDate = $cursor->format('Y-m-d');
			$ruleSet = $resolveRuleSet($localDate);
			if ($ruleSet === null) {
				throw new BusinessTripException(
					'TRIP_RULE_UNRESOLVED',
					'No business-trip rule set applies on ' . $localDate . '.',
					409,
					['localDate' => $localDate],
				);
			}
			$key = (int)$ruleSet->getId() . ':' . (string)$ruleSet->getConfigHash();
			$configCache[$key] ??= [$this->ruleValidator->validate((string)$ruleSet->getConfigJson()), $ruleSet];
			[$config, $resolvedSet] = $configCache[$key];

			$dayStartUtc = $cursor->setTimezone(new \DateTimeZone('UTC'));
			$dayEndUtc = $cursor->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
			$dayKind = $singleDay ? 'single' : ($cursor == $localStart ? 'first' : ($cursor == $localEnd ? 'last' : 'middle'));
			$days[] = $this->calculateDay(
				$trip, $segments, $timeEntries, $config, $resolvedSet,
				$dayStartUtc, $dayEndUtc, $localDate, $dayKind,
				$normalCommuteMinutes, $warnings,
			);
		}

		return ['days' => $days, 'warnings' => array_values(array_unique($warnings))];
	}

	private function calculateDay(
		BusinessTrip $trip,
		array $segments,
		array $timeEntries,
		array $config,
		BusinessTripRuleSet $ruleSet,
		\DateTimeImmutable $dayStartUtc,
		\DateTimeImmutable $dayEndUtc,
		string $localDate,
		string $dayKind,
		?int $normalCommuteMinutes,
		array &$warnings,
	): array {
		$tripStart = $trip->getStartsAtUtc()->getTimestamp();
		$tripEnd = $trip->getEndsAtUtc()->getTimestamp();
		$winStart = max($dayStartUtc->getTimestamp(), $tripStart);
		$winEnd = min($dayEndUtc->getTimestamp(), $tripEnd);
		$presence = max(0, intdiv($winEnd - $winStart, 60));

		$active = $passive = $during = $unclassified = 0;
		$duringWindows = [];
		foreach ($segments as $seg) {
			$s = max($seg->getStartsAtUtc()->getTimestamp(), $winStart);
			$e = min($seg->getEndsAtUtc()->getTimestamp(), $winEnd);
			if ($e <= $s) {
				continue;
			}
			$minutes = intdiv($e - $s, 60);
			match ($seg->getSegmentType()) {
				BusinessTripSegment::TYPE_ACTIVE_TRAVEL => $active += $minutes,
				BusinessTripSegment::TYPE_PASSIVE_TRAVEL => $passive += $minutes,
				BusinessTripSegment::TYPE_WORK_DURING_TRAVEL => $during += $minutes,
				BusinessTripSegment::TYPE_UNCLASSIFIED => $unclassified += $minutes,
				default => null,
			};
			if ($seg->getSegmentType() === BusinessTripSegment::TYPE_WORK_DURING_TRAVEL) {
				$duringWindows[] = [$s, $e];
			}
		}

		// Actual work from linked TimeEntries clipped to the day, minus any
		// overlap with work_during_travel segments — a minute never counts twice.
		$work = 0;
		foreach ($timeEntries as $entry) {
			$entryStart = $entry->getStartTime()?->getTimestamp();
			$entryEnd = $entry->getEndTime()?->getTimestamp();
			if ($entryStart === null || $entryEnd === null) {
				continue;
			}
			$s = max($entryStart, $winStart);
			$e = min($entryEnd, $winEnd);
			if ($e <= $s) {
				continue;
			}
			// The calculator clips the entry to the given window including
			// countable breaks — pass the trip∩day window directly so net
			// worked minutes are exact, not prorated.
			$hours = $this->workingHours->getEntryWorkingHoursOnCalendarDay(
				$entry,
				new \DateTime('@' . $s),
				new \DateTime('@' . $e),
			);
			$clippedMinutes = (int)round($hours * 60.0);
			foreach ($duringWindows as [$ds, $de]) {
				$overlap = max(0, min($e, $de) - max($s, $ds));
				$clippedMinutes -= intdiv($overlap, 60);
			}
			$work += max(0, $clippedMinutes);
		}

		$jurisdiction = (string)$ruleSet->getJurisdiction();
		$activeReleased = $this->configBool($config, 'activeTravelLegallyReleased');
		$passiveReleased = $this->configBool($config, 'passiveTravelLegallyReleased');
		$compliance = $this->classifier->classify(
			$jurisdiction, $work, $active, $passive, $during, $normalCommuteMinutes,
			$activeReleased, $passiveReleased,
		);

		// actual_accountable: integer basis points, rounded exactly once after
		// factor application (spec §9.5 step 6).
		$treatment = $config['accountTreatment'];
		$rawAccountable =
			$work * (int)$treatment['actualWorkFactorBps']
			+ $active * (int)$treatment['activeTravelFactorBps']
			+ $passive * (int)$treatment['passiveTravelFactorBps']
			+ $during * (int)$treatment['workDuringTravelFactorBps'];
		$rounding = $config['rounding'] ?? [];
		$resultIncrement = (int)($rounding['resultIncrement'] ?? 1);
		$mode = (string)($rounding['mode'] ?? 'nearest');
		$accountable = $this->roundInt(intdiv($rawAccountable, 10000), $resultIncrement, $mode);

		// dayBasis selection via dayKinds override.
		$dayKinds = $config['dayKinds'] ?? [];
		$basisName = $dayKinds[$dayKind] ?? $config['dayBasis'];
		$basis = match ($basisName) {
			'trip_presence' => $presence,
			'travel_duration' => $active + $passive,
			'actual_work' => $work,
			'active_plus_work' => $active + $work + $during,
			'classified_total' => $compliance['complianceMinutes'],
			default => $presence,
		};

		$compare = match ($config['compareAgainst'] ?? 'actual_accountable_minutes') {
			'actual_work_minutes' => $work,
			'zero' => 0,
			default => $accountable,
		};
		$accountTarget = self::accountTargetForBasis($config, $basis, $compare);
		$topUp = max(0, $accountTarget - $accountable);

		$export = $config['export'] ?? ['mode' => 'account_credit'];
		$exportMinutes = match ($export['mode'] ?? 'account_credit') {
			'compliance' => $compliance['complianceMinutes'],
			'actual_work' => $work,
			'separate_factors' => $this->roundInt(
				intdiv(
					// Actual work exports at 100 %; the factors apply to the
					// travel classes only (spec §9.4 export block).
					$work * 10000
					+ $active * (int)($export['activeTravelFactorBps'] ?? 10000)
					+ $passive * (int)($export['passiveTravelFactorBps'] ?? 10000)
					+ $during * (int)($export['workDuringTravelFactorBps'] ?? 10000),
					10000
				),
				$resultIncrement,
				$mode,
			),
			default => $topUp,
		};

		if ($unclassified > 0) {
			$warnings[] = 'unclassified_time_present';
		}
		if (($config['requiresClassification'] ?? false) && $unclassified > 0) {
			throw new BusinessTripException('TRIP_UNCLASSIFIED_TIME', 'Unclassified time must be classified before finalization.', 400, ['localDate' => $localDate]);
		}
		foreach ($compliance['uncertainties'] as $u) {
			$warnings[] = $u;
		}

		$result = [
			'localDate' => $localDate,
			'dayKind' => $dayKind,
			'ruleSetId' => (int)$ruleSet->getId(),
			'ruleConfigHash' => (string)$ruleSet->getConfigHash(),
			'presenceMinutes' => $presence,
			'actualWorkMinutes' => $work,
			'activeTravelMinutes' => $active,
			'passiveTravelMinutes' => $passive,
			'workDuringTravelMinutes' => $during,
			'unclassifiedMinutes' => $unclassified,
			'complianceMinutes' => $compliance['complianceMinutes'],
			'classificationRequired' => $compliance['classificationRequired'],
			'accountTargetMinutes' => $accountTarget,
			'accountTopUpMinutes' => $topUp,
			'exportMinutes' => $exportMinutes,
			'uncertainties' => $compliance['uncertainties'],
		];
		// No trip revision in the day hash: it binds the calculation facts
		// (window, rule config, minutes) — the revision CAS on the trip row
		// protects the write path. Mixing the revision in would make every
		// submit-frozen snapshot stale the moment the CAS bump lands.
		$result['snapshotHash'] = hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			. '|' . $ruleSet->getConfigHash());

		return $result;
	}

	/**
	 * Pure threshold → account-target evaluation (also used by the admin
	 * rule-preview endpoint, spec §7.4.7). The same code path runs in
	 * production calculation — previews can never drift from the engine.
	 */
	public static function accountTargetForBasis(array $config, int $basis, int $compare): int
	{
		$target = self::selectThresholdStatic((array)$config['thresholds'], $basis);
		$accountTarget = match ($config['operation'] ?? 'fixed') {
			'minimum' => max($compare, $target),
			'fixed' => $target,
			'maximum' => min($compare, $target),
			'additive' => $compare + $target,
			default => $target,
		};
		$cap = (int)($config['dailyTargetCapMinutes'] ?? 1440);
		return min($accountTarget, $cap);
	}

	/** Greatest fromMinutes <= basis wins; thresholds are pre-sorted. */
	private static function selectThresholdStatic(array $thresholds, int $basis): int
	{
		$target = 0;
		foreach ($thresholds as $threshold) {
			if ($threshold['fromMinutes'] <= $basis) {
				$target = (int)$threshold['targetMinutes'];
			} else {
				break;
			}
		}
		return $target;
	}

	private function roundInt(int $value, int $increment, string $mode): int
	{
		if ($increment <= 1) {
			return $value;
		}
		return match ($mode) {
			'floor' => intdiv($value, $increment) * $increment,
			'ceil' => (int)ceil($value / $increment) * $increment,
			default => (int)(round($value / $increment) * $increment),
		};
	}

	private function configBool(array $config, string $key): bool
	{
		return ($config[$key] ?? false) === true;
	}

	/** Persist a calculated day row; caller holds the transaction. */
	public function toEntity(BusinessTrip $trip, array $day): BusinessTripDayCalculation
	{
		$calc = new BusinessTripDayCalculation();
		$calc->setTripId((int)$trip->getId());
		$calc->setLocalDate(new \DateTime($day['localDate']));
		$calc->setRuleSetId($day['ruleSetId']);
		$calc->setRuleConfigHash($day['ruleConfigHash']);
		$calc->setPresenceMinutes($day['presenceMinutes']);
		$calc->setActualWorkMinutes($day['actualWorkMinutes']);
		$calc->setActiveTravelMinutes($day['activeTravelMinutes']);
		$calc->setPassiveTravelMinutes($day['passiveTravelMinutes']);
		$calc->setComplianceMinutes($day['complianceMinutes']);
		$calc->setAccountTargetMinutes($day['accountTargetMinutes']);
		$calc->setAccountTopUpMinutes($day['accountTopUpMinutes']);
		$calc->setExportMinutes($day['exportMinutes']);
		$calc->setResultJson(json_encode($day, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$calc->setSnapshotHash($day['snapshotHash']);
		return $calc;
	}
}
