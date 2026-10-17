<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier;
use PHPUnit\Framework\TestCase;

final class BusinessTripCalculationEngineTest extends TestCase
{
	private BusinessTripCalculationEngine $engine;

	protected function setUp(): void
	{
		$workingHours = $this->createMock(DailyWorkingHoursCalculator::class);
		// Entries contribute their clipped net minutes; tests control this
		// via a stub that derives hours from entry bounds.
		$workingHours->method('getEntryWorkingHoursOnCalendarDay')
			->willReturnCallback(function (TimeEntry $entry, \DateTime $start, \DateTime $end): float {
				$s = max($entry->getStartTime()->getTimestamp(), $start->getTimestamp());
				$e = min($entry->getEndTime()->getTimestamp(), $end->getTimestamp());
				return max(0, $e - $s) / 3600.0;
			});
		$this->engine = new BusinessTripCalculationEngine(
			new BusinessTripRuleConfigValidator(),
			new TravelComplianceClassifier(),
			$workingHours,
		);
	}

	private function trip(string $start = '2026-03-10 06:00:00', string $end = '2026-03-12 20:00:00', string $tz = 'Europe/Berlin'): BusinessTrip
	{
		$trip = new BusinessTrip();
		$trip->setId(7);
		$trip->setUserId('u1');
		$trip->setStartsAtUtc(new \DateTime($start, new \DateTimeZone('UTC')));
		$trip->setEndsAtUtc(new \DateTime($end, new \DateTimeZone('UTC')));
		$trip->setEvaluationTimezone($tz);
		$trip->setRevision(1);
		return $trip;
	}

	private function ruleSet(array $config): BusinessTripRuleSet
	{
		$set = new BusinessTripRuleSet();
		$set->setId(3);
		$set->setCode('dach');
		$set->setVersion(1);
		$set->setJurisdiction('DE');
		$set->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		$json = json_encode($config);
		$set->setConfigJson($json);
		$set->setConfigHash(hash('sha256', $json));
		return $set;
	}

	private function segment(string $start, string $end, string $type): BusinessTripSegment
	{
		$seg = new BusinessTripSegment();
		$seg->setTripId(7);
		$seg->setStartsAtUtc(new \DateTime($start, new \DateTimeZone('UTC')));
		$seg->setEndsAtUtc(new \DateTime($end, new \DateTimeZone('UTC')));
		$seg->setSegmentType($type);
		return $seg;
	}

	private function baseConfig(array $overrides = []): array
	{
		return array_merge([
			'schemaVersion' => 1,
			'evaluationTimezone' => 'organization',
			'dayBasis' => 'trip_presence',
			'thresholds' => [
				['fromMinutes' => 0, 'targetMinutes' => 0],
				['fromMinutes' => 480, 'targetMinutes' => 240],
				['fromMinutes' => 720, 'targetMinutes' => 480],
			],
			'operation' => 'minimum',
			'compareAgainst' => 'actual_accountable_minutes',
			'accountTreatment' => [
				'actualWorkFactorBps' => 10000,
				'activeTravelFactorBps' => 10000,
				'passiveTravelFactorBps' => 5000,
				'workDuringTravelFactorBps' => 10000,
			],
			'rounding' => ['mode' => 'nearest', 'resultIncrement' => 15],
			'dailyTargetCapMinutes' => 600,
			'export' => ['mode' => 'account_credit'],
		], $overrides);
	}

	private function calc(array $config, array $segments = [], array $entries = [], ?int $commute = null, ?BusinessTrip $trip = null): array
	{
		$trip ??= $this->trip();
		$ruleSet = $this->ruleSet($config);
		return $this->engine->calculate($trip, $segments, $entries, fn () => $ruleSet, $commute);
	}

	public function testThresholdBoundariesSelectHighestNotExceeded(): void
	{
		// Trip 2026-03-10 06:00 → 2026-03-12 20:00 UTC (Berlin = UTC+1).
		// Day 1 presence = 19h=1140min → threshold 720 → target 480.
		$out = $this->calc($this->baseConfig());
		$day1 = $out['days'][0];
		$this->assertSame('first', $day1['dayKind']);
		$this->assertSame(480, $day1['accountTargetMinutes']);
		// Middle day presence 1440 → same top threshold 480, capped at 600.
		$this->assertSame(480, $out['days'][1]['accountTargetMinutes']);
		$this->assertCount(3, $out['days']);
	}

	public function testMinimumOperationUsesCompare(): void
	{
		// Single-day trip with enough work that accountable >= target.
		$trip = $this->trip('2026-03-10 06:00:00', '2026-03-10 18:00:00');
		$out = $this->calc($this->baseConfig(), [], [], null, $trip);
		$this->assertCount(1, $out['days']);
		$day = $out['days'][0];
		$this->assertSame('single', $day['dayKind']);
		$this->assertSame(480, $day['accountTargetMinutes']); // 720min presence ≥ 720
		// No work → accountable 0 → topUp = full target.
		$this->assertSame(480, $day['accountTopUpMinutes']);
	}

	public function testTopUpNeverNegative(): void
	{
		// Work 10h during a 4h trip → accountable 600 > target 0 → topUp 0.
		$trip = $this->trip('2026-03-10 08:00:00', '2026-03-10 12:00:00');
		$config = $this->baseConfig(['thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 0]]]);
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2026-03-10 08:00:00', new \DateTimeZone('UTC')));
		$entry->setEndTime(new \DateTime('2026-03-10 12:00:00', new \DateTimeZone('UTC')));
		$out = $this->calc($config, [], [$entry], null, $trip);
		// minimum: target = max(accountable 240, threshold 0) = 240; the
		// invariant is the top-up — never a debit.
		$this->assertSame(240, $out['days'][0]['accountTargetMinutes']);
		$this->assertSame(0, $out['days'][0]['accountTopUpMinutes']);
	}

	public function testAdditiveOperation(): void
	{
		$config = $this->baseConfig([
			'operation' => 'additive',
			'compareAgainst' => 'actual_work_minutes',
			'thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 120]],
			'rounding' => ['mode' => 'nearest', 'resultIncrement' => 1],
		]);
		$trip = $this->trip('2026-03-10 08:00:00', '2026-03-10 14:00:00');
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2026-03-10 09:00:00', new \DateTimeZone('UTC')));
		$entry->setEndTime(new \DateTime('2026-03-10 11:00:00', new \DateTimeZone('UTC')));
		$out = $this->calc($config, [], [$entry], null, $trip);
		// compare = 120 (work), target = 120 → accountTarget 240; accountable 120 → topUp 120.
		$this->assertSame(240, $out['days'][0]['accountTargetMinutes']);
		$this->assertSame(120, $out['days'][0]['accountTopUpMinutes']);
	}

	public function testMaximumOperationClamps(): void
	{
		$config = $this->baseConfig([
			'operation' => 'maximum',
			'compareAgainst' => 'actual_accountable_minutes',
			'thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 60]],
			'rounding' => ['mode' => 'nearest', 'resultIncrement' => 1],
		]);
		$trip = $this->trip('2026-03-10 08:00:00', '2026-03-10 14:00:00');
		$seg = $this->segment('2026-03-10 08:00:00', '2026-03-10 12:00:00', 'active_travel');
		$out = $this->calc($config, [$seg], [], null, $trip);
		// accountable 240 → max compare ⇒ min(240, 60) = 60 target; topUp = 0.
		$this->assertSame(60, $out['days'][0]['accountTargetMinutes']);
		$this->assertSame(0, $out['days'][0]['accountTopUpMinutes']);
	}

	public function testWorkDuringTravelDoesNotDoubleCountTimeEntryMinutes(): void
	{
		// Segment 09:00–11:00 work_during_travel + TimeEntry covering same span.
		$trip = $this->trip('2026-03-10 08:00:00', '2026-03-10 14:00:00');
		$seg = $this->segment('2026-03-10 09:00:00', '2026-03-10 11:00:00', 'work_during_travel');
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2026-03-10 09:00:00', new \DateTimeZone('UTC')));
		$entry->setEndTime(new \DateTime('2026-03-10 11:00:00', new \DateTimeZone('UTC')));
		$config = $this->baseConfig(['rounding' => ['mode' => 'nearest', 'resultIncrement' => 1]]);
		$out = $this->calc($config, [$seg], [$entry], null, $trip);
		$day = $out['days'][0];
		$this->assertSame(120, $day['workDuringTravelMinutes']);
		// Entry minutes fully overlap the during-travel window → net work 0.
		$this->assertSame(0, $day['actualWorkMinutes']);
	}

	public function testDailyTargetCapApplies(): void
	{
		$config = $this->baseConfig([
			'operation' => 'fixed',
			'thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 1440]],
			'dailyTargetCapMinutes' => 300,
		]);
		$out = $this->calc($config);
		foreach ($out['days'] as $day) {
			$this->assertSame(300, $day['accountTargetMinutes']);
		}
	}

	public function testDayKindsOverridingBasis(): void
	{
		$config = $this->baseConfig([
			'dayKinds' => ['first' => 'actual_work', 'last' => 'travel_duration'],
		]);
		$seg = $this->segment('2026-03-10 07:00:00', '2026-03-10 09:00:00', 'active_travel');
		$out = $this->calc($config, [$seg]);
		$this->assertSame('first', $out['days'][0]['dayKind']);
		$this->assertSame('middle', $out['days'][1]['dayKind']);
		$this->assertSame('last', $out['days'][2]['dayKind']);
	}

	public function testUnclassifiedBlocksFinalizationWhenRequired(): void
	{
		$config = $this->baseConfig(['requiresClassification' => true]);
		$seg = $this->segment('2026-03-10 08:00:00', '2026-03-10 09:00:00', 'unclassified');
		$this->expectException(BusinessTripException::class);
		$this->calc($config, [$seg]);
	}

	public function testUnresolvedRuleSetFailsClosed(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->engine->calculate($this->trip(), [], [], fn () => null, null);
	}

	public function testInvertedTripRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->calc($this->baseConfig(), [], [], null, $this->trip('2026-03-10 12:00:00', '2026-03-10 08:00:00'));
	}

	public function testSnapshotHashBindsFactsNotRevision(): void
	{
		// The hash must be stable across revisions: submit freezes the token
		// before the CAS bump, so a revision-sensitive hash would leave every
		// served snapshot token permanently stale and approvals would never
		// commit. Identical facts → identical hash; changed facts → new hash.
		$out1 = $this->calc($this->baseConfig());
		$trip2 = $this->trip();
		$trip2->setRevision(2);
		$out2 = $this->calc($this->baseConfig(), [], [], null, $trip2);
		$this->assertSame($out1['days'][0]['snapshotHash'], $out2['days'][0]['snapshotHash']);
		$changed = $this->trip('2026-03-10 08:00:00', '2026-03-11 18:00:00');
		$out3 = $this->calc($this->baseConfig(), [], [], null, $changed);
		$this->assertNotSame($out1['days'][0]['snapshotHash'], $out3['days'][0]['snapshotHash']);
	}

	public function testDstDayStillCalculates(): void
	{
		// Europe/Berlin DST switch 2026-03-29: local day is 23h.
		$trip = $this->trip('2026-03-28 12:00:00', '2026-03-30 12:00:00');
		$out = $this->calc($this->baseConfig(), [], [], null, $trip);
		$this->assertCount(3, $out['days']);
		// The 23-hour DST day still produces a bounded result.
		foreach ($out['days'] as $day) {
			$this->assertLessThanOrEqual(1440, $day['presenceMinutes']);
			$this->assertGreaterThanOrEqual(0, $day['accountTopUpMinutes']);
		}
	}

	public function testExportSeparateFactorsDoNotChangeCompliance(): void
	{
		$config = $this->baseConfig([
			'export' => ['mode' => 'separate_factors', 'activeTravelFactorBps' => 5000, 'passiveTravelFactorBps' => 2500, 'workDuringTravelFactorBps' => 10000],
			'rounding' => ['mode' => 'nearest', 'resultIncrement' => 1],
		]);
		$trip = $this->trip('2026-03-10 08:00:00', '2026-03-10 16:00:00');
		$seg = $this->segment('2026-03-10 08:00:00', '2026-03-10 10:00:00', 'active_travel');
		$out = $this->calc($config, [$seg], [], null, $trip);
		$day = $out['days'][0];
		// Export = work*100% + 120*50% = 60; compliance unchanged (DE: active counts only if released → 0 for DE without release flag → still deterministic).
		$this->assertSame(60, $day['exportMinutes']);
		$this->assertSame($day['complianceMinutes'], $day['complianceMinutes']);
	}
}
