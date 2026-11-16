<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationReportAggregator;
use PHPUnit\Framework\TestCase;

final class WorkLocationReportAggregatorTest extends TestCase
{
	private WorkLocationReportAggregator $aggregator;
	private \DateTimeZone $tz;

	protected function setUp(): void
	{
		$defMapper = $this->createMock(WorkLocationDefinitionMapper::class);
		$defMapper->method('findAllIndexed')->willReturn([]);
		$catalog = new WorkLocationCatalog($defMapper, new \Psr\Log\NullLogger());
		$this->aggregator = new WorkLocationReportAggregator($catalog);
		$this->tz = new \DateTimeZone('Europe/Berlin');
	}

	private function entry(string $start, ?string $end, ?string $location, ?string $breaksJson = null): TimeEntry
	{
		$e = new TimeEntry();
		$e->setStartTime(new \DateTime($start, new \DateTimeZone('UTC')));
		$e->setEndTime($end !== null ? new \DateTime($end, new \DateTimeZone('UTC')) : null);
		$e->setWorkLocation($location);
		$e->setBreaks($breaksJson);
		return $e;
	}

	public function testSingleEntryCountsOneDayAndMinutes(): void
	{
		// 08:00–12:00 UTC = 4h home
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', '2026-10-01 12:00:00', 'home')],
			$this->tz,
			new \DateTimeImmutable('2026-10-05 00:00:00')
		);
		$this->assertSame(1, $r['byCode']['home']['days']);
		$this->assertSame(240, $r['byCode']['home']['netMinutes']);
		$this->assertSame(240, $r['byCategory']['home']['netMinutes']);
		$this->assertSame(0, $r['mixedDays']);
	}

	public function testMidnightCrossingSplitsIntoTwoLocalDays(): void
	{
		// Berlin CEST is UTC+2: 22:00–02:00 local = 20:00–00:00 UTC.
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 20:00:00', '2026-10-02 00:00:00', 'external')],
			$this->tz
		);
		$this->assertSame(2, $r['byCode']['external']['days']);
		$this->assertSame(240, $r['byCode']['external']['netMinutes']);
		$this->assertSame(0, $r['mixedDays']);
	}

	public function testBreakIsDeductedFromTheSegmentItOverlaps(): void
	{
		// 20:00–00:00 UTC with a 30-min break 21:00–21:30 UTC (first local day).
		$breaks = json_encode([['start' => '2026-10-01 21:00:00', 'end' => '2026-10-01 21:30:00']]);
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 20:00:00', '2026-10-02 00:00:00', 'home', $breaks)],
			$this->tz
		);
		$this->assertSame(210, $r['byCode']['home']['netMinutes']);
		$this->assertSame(2, $r['byCode']['home']['days']);
	}

	public function testMixedDayCountsOncePerCodeAndInMixedDays(): void
	{
		$r = $this->aggregator->aggregate(
			[
				$this->entry('2026-10-01 06:00:00', '2026-10-01 10:00:00', 'office'),
				$this->entry('2026-10-01 12:00:00', '2026-10-01 15:00:00', 'home'),
			],
			$this->tz
		);
		$this->assertSame(1, $r['byCode']['office']['days']);
		$this->assertSame(1, $r['byCode']['home']['days']);
		$this->assertSame(1, $r['mixedDays']);
	}

	public function testNullLocationGoesToUnrecordedCategoryOnly(): void
	{
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', '2026-10-01 12:00:00', null)],
			$this->tz
		);
		$this->assertArrayNotHasKey('unrecorded', $r['byCode']);
		$this->assertSame(1, $r['byCategory']['unrecorded']['days']);
		$this->assertSame(240, $r['byCategory']['unrecorded']['netMinutes']);
		$this->assertSame(0, $r['mixedDays']);
	}

	public function testUnknownCodeAggregatesUnderOtherCategory(): void
	{
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', '2026-10-01 09:00:00', 'custom_plant_xy')],
			$this->tz
		);
		$this->assertSame('other', $r['byCode']['custom_plant_xy']['category']);
		$this->assertSame(60, $r['byCategory']['other']['netMinutes']);
	}

	public function testSplitEntryNetMinutesExcludeScheduledInterruption(): void
	{
		// FM-08: raw span 04:00–14:30 UTC (06:00–16:30 CEST, 10.5 h) with a
		// frozen split result excluding 10:00–13:30 local → 7.00 h net.
		$e = $this->entry('2024-06-10 04:00:00', '2024-06-10 14:30:00', 'office');
		$e->setWorkContextJson(json_encode([
			'v' => 1,
			'result' => [
				'state' => 'applied',
				'split' => true,
				'reason' => 'clock_out_after_cutoff',
				'excluded' => [['2024-06-10T10:00:00+02:00', '2024-06-10T13:30:00+02:00']],
				'segments' => [
					['2024-06-10T06:00:00+02:00', '2024-06-10T10:00:00+02:00'],
					['2024-06-10T13:30:00+02:00', '2024-06-10T16:30:00+02:00'],
				],
				'break_handling' => 'covered_by_interruption',
				'engine' => 1,
			],
		]));
		$e->setExcludedWorkSeconds(12600);

		$r = $this->aggregator->aggregate([$e], $this->tz);
		$this->assertSame(420, $r['byCode']['office']['netMinutes']);
		$this->assertSame(1, $r['byCode']['office']['days']);
	}

	public function testSplitEntryBreakOverlapWithInterruptionIsNotDoubleSubtracted(): void
	{
		// Same F1 span, plus a recorded 15-min break inside the interruption
		// window — merged non-work union subtracts it once, not twice.
		$breaks = json_encode([['start' => '2024-06-10 08:15:00', 'end' => '2024-06-10 08:30:00']]);
		$e = $this->entry('2024-06-10 04:00:00', '2024-06-10 14:30:00', 'home', $breaks);
		$e->setWorkContextJson(json_encode([
			'v' => 1,
			'result' => [
				'state' => 'applied',
				'split' => true,
				'reason' => 'clock_out_after_cutoff',
				'excluded' => [['2024-06-10T10:00:00+02:00', '2024-06-10T13:30:00+02:00']],
				'segments' => [
					['2024-06-10T06:00:00+02:00', '2024-06-10T10:00:00+02:00'],
					['2024-06-10T13:30:00+02:00', '2024-06-10T16:30:00+02:00'],
				],
				'break_handling' => 'standard',
				'engine' => 1,
			],
		]));
		$e->setExcludedWorkSeconds(12600);

		// 10.5 h gross − 3.5 h interruption (recorded break inside X adds nothing) = 420 min.
		$r = $this->aggregator->aggregate([$e], $this->tz);
		$this->assertSame(420, $r['byCode']['home']['netMinutes']);
	}

	public function testOpenEntryUsesAsOfHorizonInLiveView(): void
	{
		$asOf = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', null, 'home')],
			$this->tz,
			$asOf
		);
		$this->assertSame(120, $r['byCode']['home']['netMinutes']);
	}

	public function testOpenEntryExcludedWhenAsOfIsNull(): void
	{
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', null, 'home')],
			$this->tz,
			null
		);
		$this->assertSame([], $r['byCode']);
		$this->assertSame([], $r['byCategory']);
	}

	public function testDstTransitionUsesRealUtcDuration(): void
	{
		// 2026-10-25 CEST→CET: 00:00–04:00 UTC spans 02:00→05:00 local.
		// The local "day" gains an hour, but net minutes follow real elapsed
		// UTC time — never wall-clock subtraction.
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-25 00:00:00', '2026-10-25 04:00:00', 'office')],
			$this->tz
		);
		$this->assertSame(240, $r['byCode']['office']['netMinutes']);
		$this->assertSame(1, $r['byCode']['office']['days']);
	}

	public function testZeroAndNegativeDurationsAreSkipped(): void
	{
		$r = $this->aggregator->aggregate(
			[
				$this->entry('2026-10-01 08:00:00', '2026-10-01 08:00:00', 'home'),
				$this->entry('2026-10-01 10:00:00', '2026-10-01 09:00:00', 'home'),
			],
			$this->tz
		);
		$this->assertSame([], $r['byCode']);
	}

	public function testBreakLongerThanSegmentDoesNotGoNegative(): void
	{
		// 60-min entry with a (corrupt but possible) 90-min break record.
		$breaks = json_encode([['start' => '2026-10-01 08:00:00', 'end' => '2026-10-01 09:30:00']]);
		$r = $this->aggregator->aggregate(
			[$this->entry('2026-10-01 08:00:00', '2026-10-01 09:00:00', 'home', $breaks)],
			$this->tz
		);
		$this->assertSame(0, $r['byCode']['home']['netMinutes']);
	}
}
