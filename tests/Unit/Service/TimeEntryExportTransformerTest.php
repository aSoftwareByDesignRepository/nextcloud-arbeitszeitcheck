<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TimeEntryExportTransformerTest extends TestCase
{
	private TimeEntryExportTransformer $transformer;

	protected function setUp(): void
	{
		parent::setUp();
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('Europe/Berlin');
		$this->transformer = new TimeEntryExportTransformer($config);
	}

	public function testNoSplitWhenDisabled(): void
	{
		$entry = $this->makeEntry(
			1,
			new \DateTime('2024-06-10 22:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-11 06:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertCount(1, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
	}

	public function testSplitOverMidnightProducesTwoRows(): void
	{
		$entry = $this->makeEntry(
			2,
			new \DateTime('2024-06-10 22:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-11 06:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToExportRows($entry, true);
		$this->assertCount(2, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
		$this->assertSame('22:00:00', $rows[0]['start_time']);
		$this->assertStringStartsWith('23:59:', $rows[0]['end_time']);
		$this->assertSame('2024-06-11', $rows[1]['date']);
		$this->assertSame('00:00:00', $rows[1]['start_time']);
		$this->assertSame('06:00:00', $rows[1]['end_time']);
	}

	public function testMultiMidnightSpanProducesThreeRows(): void
	{
		$entry = $this->makeEntry(
			3,
			new \DateTime('2024-06-10 20:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-12 04:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToExportRows($entry, true);
		$this->assertCount(3, $rows);
		$this->assertSame('2024-06-10', $rows[0]['date']);
		$this->assertSame('2024-06-11', $rows[1]['date']);
		$this->assertSame('2024-06-12', $rows[2]['date']);
	}

	public function testWideDailyUsesGermanDateAndMidnightDisplay(): void
	{
		$long = [
			[
				'user_id' => 'u1',
				'display_name' => 'Test',
				'date' => '2024-06-10',
				'start_time' => '09:00:00',
				'end_time' => '12:00:00',
			],
			[
				'user_id' => 'u1',
				'display_name' => 'Test',
				'date' => '2024-06-10',
				'start_time' => '13:00:00',
				'end_time' => '23:59:59',
			],
		];
		$wide = $this->transformer->longExportRowsToWideDaily($long, static fn (string $d): string => 'Mon');
		$this->assertCount(1, $wide);
		$this->assertSame('10.06.2024', $wide[0]['date']);
		$this->assertSame('9:00', $wide[0]['von_1']);
		$this->assertSame('12:00', $wide[0]['bis_1']);
		$this->assertSame('13:00', $wide[0]['von_2']);
		$this->assertSame('0:00', $wide[0]['bis_2']);
	}

	public function testBreaksExportFromJsonAndLegacyFields(): void
	{
		$entry = $this->makeEntry(
			9,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 17:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$entry->setBreaks(json_encode([
			['start' => '2024-06-10 12:00:00', 'end' => '2024-06-10 12:30:00'],
			['start' => 'bogus-date', 'end' => 'still-bogus'],
			['start' => '2024-06-10 15:00:00'], // missing end -> skipped
		]));
		$entry->setBreakStartTime(new \DateTime('2024-06-10 09:30:00', new \DateTimeZone('Europe/Berlin')));
		$entry->setBreakEndTime(new \DateTime('2024-06-10 09:45:00', new \DateTimeZone('Europe/Berlin')));

		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertCount(1, $rows);
		// JSON break times are naive (UTC-parsed) -> +2h Europe/Berlin in June
		$this->assertSame('14:00:00', $rows[0]['break_start']);
		$this->assertSame('14:30:00', $rows[0]['break_end']);
	}

	public function testBreaksExportLegacyFieldsOnly(): void
	{
		$entry = $this->makeEntry(
			10,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 17:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$entry->setBreakStartTime(new \DateTime('2024-06-10 12:15:00', new \DateTimeZone('Europe/Berlin')));
		$entry->setBreakEndTime(new \DateTime('2024-06-10 12:45:00', new \DateTimeZone('Europe/Berlin')));
		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertSame('12:15:00', $rows[0]['break_start']);
		$this->assertSame('12:45:00', $rows[0]['break_end']);
	}

	public function testPaidBreakColumnsAreExportedOnSingleRow(): void
	{
		$entry = $this->makeEntry(
			11,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 17:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$entry->setBreaks(json_encode([
			['start' => '2024-06-10 12:00:00', 'end' => '2024-06-10 13:00:00'],
		]));
		$entry->setPaidBreakSeconds(1800); // 0.5h paid break snapshot

		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertCount(1, $rows);
		$row = $rows[0];
		// net = 9h wall - 1h break = 8h; paid = 8 + 0.5 = 8.5h
		$this->assertEqualsWithDelta(8.5, (float)$row['working_hours'], 1e-4);
		$this->assertEqualsWithDelta(8.0, (float)$row['net_working_hours'], 1e-4);
		$this->assertEqualsWithDelta(0.5, (float)$row['paid_break_hours'], 1e-4);
		// working_hours is the paid basis: net + paid break component.
		$this->assertEqualsWithDelta(
			(float)$row['net_working_hours'] + (float)$row['paid_break_hours'],
			(float)$row['working_hours'],
			1e-4
		);
	}

	public function testPaidBreakColumnsAreProratedOnMidnightSplit(): void
	{
		$entry = $this->makeEntry(
			12,
			new \DateTime('2024-06-10 22:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-11 06:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$entry->setBreaks(json_encode([
			['start' => '2024-06-10 23:00:00', 'end' => '2024-06-11 00:00:00'],
		]));
		$entry->setPaidBreakSeconds(1800);

		$rows = $this->transformer->entryToExportRows($entry, true);
		$this->assertCount(2, $rows);

		foreach ($rows as $row) {
			$this->assertArrayHasKey('net_working_hours', $row);
			$this->assertArrayHasKey('paid_break_hours', $row);
			// Per-segment consistency: paid basis = net part + paid-break part.
			$this->assertEqualsWithDelta(
				(float)$row['net_working_hours'] + (float)$row['paid_break_hours'],
				(float)$row['working_hours'],
				1e-4
			);
		}
		// Segments re-assemble the entry totals (one boundary second is lost
		// by design in the split, hence the 0.01 tolerance).
		$this->assertEqualsWithDelta(7.5, array_sum(array_column($rows, 'working_hours')), 0.01);
		$this->assertEqualsWithDelta(7.0, array_sum(array_column($rows, 'net_working_hours')), 0.01);
		$this->assertEqualsWithDelta(0.5, array_sum(array_column($rows, 'paid_break_hours')), 0.01);
	}

	public function testDefaultPaidBreakIsZeroNotNull(): void
	{
		$entry = $this->makeEntry(
			13,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 12:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertSame(0.0, $rows[0]['paid_break_hours']);
		$this->assertEqualsWithDelta(4.0, (float)$rows[0]['working_hours'], 1e-4);
		$this->assertEqualsWithDelta(4.0, (float)$rows[0]['net_working_hours'], 1e-4);
	}

	/**
	 * Golden regression: a legacy (no work_context) row must keep every
	 * pre-split-day column byte-identical; the additive trailing columns
	 * exist but stay empty.
	 */
	public function testLegacyRowGoldenColumnsByteIdentical(): void
	{
		$entry = $this->makeEntry(
			42,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 17:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToExportRows($entry, false);
		$this->assertCount(1, $rows);
		$row = $rows[0];

		$golden = [
			'id' => 42,
			'date' => '2024-06-10',
			'start_time' => '08:00:00',
			'end_time' => '17:00:00',
			'break_start' => '',
			'break_end' => '',
			'break_periods' => '',
			'duration_hours' => 9.0,
			'break_duration_hours' => 0.0,
			'working_hours' => 9.0,
			'net_working_hours' => 9.0,
			'paid_break_hours' => 0.0,
			'description' => '',
			'work_location' => '',
			'work_location_label' => '',
			'work_location_category' => '',
			'status' => 'completed',
			'ended_reason' => '',
			'policy_applied' => '',
			'is_manual_entry' => 'No',
			'project_id' => '',
		];
		foreach ($golden as $key => $expected) {
			$this->assertSame($expected, $row[$key], "column $key changed for legacy row");
		}
		// Trailing additive columns are present but empty for legacy entries.
		$this->assertSame('', $row['excluded_minutes']);
		$this->assertSame('', $row['partial_services']);
		// ... and the trailing columns are really last in the row order.
		$this->assertSame(
			['excluded_minutes', 'partial_services'],
			array_slice(array_keys($row), -2)
		);
	}

	/**
	 * Spec F1: 06:00–16:30 with applied split context (X = 10:00–13:30,
	 * covered interruption) → excluded_minutes 210, two partial-service
	 * ranges, and corrected 7.00 h net in the authoritative columns.
	 */
	public function testSplitEntryAppendsExcludedMinutesAndPartialServices(): void
	{
		$rows = $this->transformer->entryToExportRows($this->makeSplitF1Entry(7), false);
		$this->assertCount(1, $rows);
		$row = $rows[0];

		$this->assertSame('210', $row['excluded_minutes']);
		$this->assertSame('06:00-10:00; 13:30-16:30', $row['partial_services']);
		$this->assertEqualsWithDelta(7.0, (float)$row['net_working_hours'], 1e-4);
		$this->assertEqualsWithDelta(10.5, (float)$row['duration_hours'], 1e-4);
	}

	public function testUnsplitContextRowHasEmptyPartialServices(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = $this->makeEntry(
			21,
			new \DateTime('2024-06-10 08:00:00', $tz),
			new \DateTime('2024-06-10 16:00:00', $tz)
		);
		$entry->setWorkContextJson(json_encode([
			'v' => 1,
			'result' => [
				'state' => 'applied',
				'split' => false,
				'reason' => 'not_after_cutoff',
				'excluded' => [],
				'segments' => [],
				'break_handling' => 'standard',
				'engine' => 1,
			],
		]));

		$row = $this->transformer->entryToExportRows($entry, false)[0];
		$this->assertSame('0', $row['excluded_minutes']);
		$this->assertSame('', $row['partial_services']);
	}

	/**
	 * `segments=rows`: the F1 split entry emits exactly one row per partial
	 * service segment — the scheduled interruption never becomes a row.
	 */
	public function testEntryToSegmentRowsProducesOneRowPerSegment(): void
	{
		$rows = $this->transformer->entryToSegmentRows($this->makeSplitF1Entry(7), false);
		$this->assertCount(2, $rows);

		$this->assertSame(7, $rows[0]['parent_entry_id']);
		$this->assertSame(1, $rows[0]['segment_no']);
		$this->assertSame('2024-06-10', $rows[0]['date']);
		$this->assertSame('06:00:00', $rows[0]['start_time']);
		$this->assertSame('10:00:00', $rows[0]['end_time']);
		$this->assertEqualsWithDelta(4.0, (float)$rows[0]['net_working_hours'], 1e-4);

		$this->assertSame(7, $rows[1]['parent_entry_id']);
		$this->assertSame(2, $rows[1]['segment_no']);
		$this->assertSame('13:30:00', $rows[1]['start_time']);
		$this->assertSame('16:30:00', $rows[1]['end_time']);
		$this->assertEqualsWithDelta(3.0, (float)$rows[1]['net_working_hours'], 1e-4);

		// Segment net sums to the corrected entry net (7.00 h).
		$this->assertEqualsWithDelta(
			7.0,
			(float)$rows[0]['net_working_hours'] + (float)$rows[1]['net_working_hours'],
			1e-4
		);
	}

	public function testEntryToSegmentRowsKeepsDefaultShapeForLegacyEntry(): void
	{
		$entry = $this->makeEntry(
			30,
			new \DateTime('2024-06-10 08:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2024-06-10 16:00:00', new \DateTimeZone('Europe/Berlin'))
		);
		$rows = $this->transformer->entryToSegmentRows($entry, false);
		$this->assertCount(1, $rows);
		$this->assertSame('', $rows[0]['parent_entry_id']);
		$this->assertSame('', $rows[0]['segment_no']);
		$this->assertSame('08:00:00', $rows[0]['start_time']);
	}

	/**
	 * Spec F1 split fixture: entry 06:00–16:30 Europe/Berlin, scheduled
	 * interruption 10:00–13:30 excluded → corrected net 7.00 h.
	 */
	private function makeSplitF1Entry(int $id): TimeEntry
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$e = $this->makeEntry(
			$id,
			new \DateTime('2024-06-10 06:00:00', $tz),
			new \DateTime('2024-06-10 16:30:00', $tz)
		);
		$e->setWorkContextJson(json_encode([
			'v' => 1,
			'model_id' => 7,
			'timezone' => 'Europe/Berlin',
			'day' => ['blocks' => [['06:00', '10:00'], ['13:30', '16:30']]],
			'split' => ['automatic' => true, 'cutoff' => '16:00', 'break_cover' => true],
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
		$e->setExcludedWorkSeconds(12600); // 3.5 h scheduled interruption
		return $e;
	}

	private function makeEntry(int $id, \DateTime $start, \DateTime $end): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId($id);
		$e->setUserId('user1');
		$e->setStartTime($start);
		$e->setEndTime($end);
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setIsManualEntry(false);
		$e->setDescription('');
		return $e;
	}
}
