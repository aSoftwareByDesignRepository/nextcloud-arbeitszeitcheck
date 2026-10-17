<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator;
use OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier;
use PHPUnit\Framework\TestCase;

final class BusinessTripDomainServiceTest extends TestCase
{
	private BusinessTripRuleConfigValidator $rules;
	private BusinessTripTimelineValidator $timeline;
	private TravelComplianceClassifier $classifier;

	protected function setUp(): void
	{
		$this->rules = new BusinessTripRuleConfigValidator();
		$this->timeline = new BusinessTripTimelineValidator();
		$this->classifier = new TravelComplianceClassifier();
	}

	private function validConfig(array $overrides = []): array
	{
		return array_merge([
			'schemaVersion' => 1,
			'dayBasis' => 'trip_presence',
			'operation' => 'minimum',
			'thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 480]],
		], $overrides);
	}

	// ---------- Rule config validator ----------

	public function testValidConfigPasses(): void
	{
		$out = $this->rules->validate(json_encode($this->validConfig()));
		$this->assertSame('minimum', $out['operation']);
	}

	public function testUnknownTopLevelKeyRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig(['evil' => 'exec me'])));
	}

	public function testInvalidJsonRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate('{not json');
	}

	public function testNonObjectJsonRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate('[1,2,3]');
	}

	public function testUnknownOperationRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig(['operation' => 'multiply'])));
	}

	public function testThresholdsMustStartAtZero(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig([
			'thresholds' => [['fromMinutes' => 60, 'targetMinutes' => 120]],
		])));
	}

	public function testThresholdsMustStrictlyIncrease(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig([
			'thresholds' => [
				['fromMinutes' => 0, 'targetMinutes' => 120],
				['fromMinutes' => 0, 'targetMinutes' => 240],
			],
		])));
	}

	public function testThresholdTargetBoundedByDay(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig([
			'thresholds' => [['fromMinutes' => 0, 'targetMinutes' => 1441]],
		])));
	}

	public function testTooManyThresholdsRejected(): void
	{
		$thresholds = [['fromMinutes' => 0, 'targetMinutes' => 0]];
		for ($i = 1; $i <= 32; $i++) {
			$thresholds[] = ['fromMinutes' => $i * 10, 'targetMinutes' => 0];
		}
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig(['thresholds' => $thresholds])));
	}

	public function testFactorBpsBounds(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig([
			'accountTreatment' => ['actualWorkFactorBps' => 10001],
		])));
	}

	public function testNonBooleanReleaseFlagRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig(['activeTravelLegallyReleased' => 'yes'])));
	}

	public function testInvalidRoundingIncrementRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->rules->validate(json_encode($this->validConfig([
			'rounding' => ['mode' => 'nearest', 'resultIncrement' => 7],
		])));
	}

	// ---------- Timeline validator ----------

	private function seg(string $s, string $e, string $type = 'active_travel'): array
	{
		return [
			'startsAtUtc' => new \DateTime($s, new \DateTimeZone('UTC')),
			'endsAtUtc' => new \DateTime($e, new \DateTimeZone('UTC')),
			'segmentType' => $type,
			'transportMode' => null,
		];
	}

	public function testValidTimelinePasses(): void
	{
		$this->timeline->validate(
			[$this->seg('2026-03-10 08:00', '2026-03-10 10:00'), $this->seg('2026-03-10 10:00', '2026-03-10 12:00', 'passive_travel')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
		$this->assertTrue(true);
	}

	public function testOverlapRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->expectExceptionMessageMatches('/overlap/i');
		$this->timeline->validate(
			[$this->seg('2026-03-10 08:00', '2026-03-10 10:00'), $this->seg('2026-03-10 09:30', '2026-03-10 11:00')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
	}

	public function testUnsortedSegmentsStillDetectOverlap(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->timeline->validate(
			[$this->seg('2026-03-10 09:30', '2026-03-10 11:00'), $this->seg('2026-03-10 08:00', '2026-03-10 10:00')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
	}

	public function testOutsideTripWindowRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->timeline->validate(
			[$this->seg('2026-03-10 05:00', '2026-03-10 08:00')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
	}

	public function testInvertedSegmentRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->timeline->validate(
			[$this->seg('2026-03-10 10:00', '2026-03-10 08:00')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
	}

	public function testUnknownTypeRejected(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->timeline->validate(
			[$this->seg('2026-03-10 08:00', '2026-03-10 09:00', 'teleport')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
	}

	public function testGapsAreLegal(): void
	{
		$this->timeline->validate(
			[$this->seg('2026-03-10 08:00', '2026-03-10 09:00'), $this->seg('2026-03-10 15:00', '2026-03-10 16:00')],
			new \DateTime('2026-03-10 06:00', new \DateTimeZone('UTC')),
			new \DateTime('2026-03-10 20:00', new \DateTimeZone('UTC')),
		);
		$this->assertTrue(true);
	}

	// ---------- DACH classifier ----------

	public function testDeCountsWorkAndWorkDuringTravel(): void
	{
		$out = $this->classifier->classify('DE', 300, 120, 60, 45);
		$this->assertSame(345, $out['complianceMinutes']);
		// Active + passive unclassified → uncertainty visible, never zeroed silently.
		$this->assertTrue($out['classificationRequired']);
		$this->assertContains('active_travel_unclassified', $out['uncertainties']);
		$this->assertContains('passive_travel_unclassified', $out['uncertainties']);
	}

	public function testDeReleasedTravelCounts(): void
	{
		$out = $this->classifier->classify('DE', 300, 120, 60, 0, null, true, true);
		$this->assertSame(480, $out['complianceMinutes']);
		$this->assertFalse($out['classificationRequired']);
	}

	public function testAtCountsAllTravel(): void
	{
		$out = $this->classifier->classify('AT', 300, 120, 60, 45);
		$this->assertSame(525, $out['complianceMinutes']);
		$this->assertContains('passive_travel_remuneration_unsettled', $out['uncertainties']);
	}

	public function testChWithoutCommuteBlocks(): void
	{
		$out = $this->classifier->classify('CH', 300, 120, 60, 0, null);
		$this->assertSame(300, $out['complianceMinutes']);
		$this->assertTrue($out['classificationRequired']);
		$this->assertContains('normal_commute_missing', $out['uncertainties']);
	}

	public function testChOnlyExcessTravelCounts(): void
	{
		// 120 travel − 30 normal commute = 90 count.
		$out = $this->classifier->classify('CH', 300, 120, 0, 0, 30);
		$this->assertSame(390, $out['complianceMinutes']);
	}

	public function testChCommuteGreaterThanTravelCountsNothingExtra(): void
	{
		$out = $this->classifier->classify('CH', 300, 20, 0, 0, 60);
		$this->assertSame(300, $out['complianceMinutes']);
		$this->assertFalse($out['classificationRequired']);
	}

	public function testCustomOnlyCountsWork(): void
	{
		$out = $this->classifier->classify('custom', 300, 120, 60, 30);
		$this->assertSame(330, $out['complianceMinutes']);
		$this->assertContains('custom_profile_travel_unclassified', $out['uncertainties']);
	}
}
