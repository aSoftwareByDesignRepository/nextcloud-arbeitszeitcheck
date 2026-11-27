<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\TimeEntryClockPayloadBuilder;
use PHPUnit\Framework\TestCase;

class TimeEntryClockPayloadBuilderTest extends TestCase
{
	public function testBuildFromParamsReturnsNullWhenClockFieldsMissing(): void
	{
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([]));
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
		]));
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([
			'startTime' => '08:00',
			'endTime' => '16:00',
		]));
	}

	public function testBuildFromParamsRejectsInvalidTimes(): void
	{
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '25:00',
			'endTime' => '16:00',
		]));
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '8:00',
			'endTime' => '16:99',
		]));
	}

	public function testBuildFromParamsRejectsInvalidCalendarDate(): void
	{
		self::assertNull(TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '30.02.2026',
			'startTime' => '08:00',
			'endTime' => '16:00',
		]));
	}

	public function testBuildFromParamsAcceptsGermanDate(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
		]);
		self::assertNotNull($proposal);
		self::assertSame('2026-06-15T08:00:00', substr((string)$proposal['startTime'], 0, 19));
		self::assertSame('2026-06-15T16:30:00', substr((string)$proposal['endTime'], 0, 19));
		self::assertArrayNotHasKey('breaks', $proposal);
	}

	public function testBuildFromParamsAcceptsIsoDate(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '2026-06-15',
			'startTime' => '08:00',
			'endTime' => '16:00',
		]);
		self::assertNotNull($proposal);
		self::assertSame('2026-06-15T08:00:00', substr((string)$proposal['startTime'], 0, 19));
	}

	public function testNightShiftWrapsEndToNextDay(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '22:00',
			'endTime' => '06:00',
		]);
		self::assertNotNull($proposal);
		self::assertSame('2026-06-15T22:00:00', substr((string)$proposal['startTime'], 0, 19));
		self::assertSame('2026-06-16T06:00:00', substr((string)$proposal['endTime'], 0, 19));
	}

	public function testEqualStartEndIsTreatedAsNextDay(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '12:00',
			'endTime' => '12:00',
		]);
		self::assertNotNull($proposal);
		self::assertSame('2026-06-16T12:00:00', substr((string)$proposal['endTime'], 0, 19));
	}

	public function testValidBreaksAreNormalized(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				['start' => '12:00', 'end' => '12:30'],
				['start_time' => '14:00', 'end_time' => '14:15'],
			],
		]);
		self::assertNotNull($proposal);
		self::assertArrayHasKey('breaks', $proposal);
		self::assertCount(2, $proposal['breaks']);
		self::assertSame('2026-06-15T12:00:00', substr((string)$proposal['breaks'][0]['start'], 0, 19));
	}

	public function testTooShortBreakIsDropped(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				['start' => '12:00', 'end' => '12:10'],
				['start' => '14:00', 'end' => '14:30'],
			],
		]);
		self::assertNotNull($proposal);
		self::assertArrayHasKey('breaks', $proposal);
		self::assertCount(1, $proposal['breaks']);
	}

	public function testAzgTenMinuteBreaksAreKeptWhenFloorIsTen(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				['start' => '10:00', 'end' => '10:10'],
				['start' => '12:00', 'end' => '12:10'],
				['start' => '14:00', 'end' => '14:10'],
			],
		], 10);
		self::assertNotNull($proposal);
		self::assertArrayHasKey('breaks', $proposal);
		self::assertCount(3, $proposal['breaks']);
	}

	public function testMalformedBreakEntryThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				'not-an-array',
			],
		]);
	}

	public function testBreakEntryMissingEndThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				['start' => '12:00'],
			],
		]);
	}

	public function testBreakEntryWithInvalidTimeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => [
				['start' => '99:00', 'end' => '12:30'],
			],
		]);
	}

	public function testNonArrayBreaksParamThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:30',
			'breaks' => 'notanarray',
		]);
	}

	public function testMergeIntoProposalUsesClockFieldsWhenAvailable(): void
	{
		$merged = TimeEntryClockPayloadBuilder::mergeIntoProposal(
			[
				'date' => '15.06.2026',
				'startTime' => '08:00',
				'endTime' => '16:00',
				'description' => 'work',
			],
			['legacy' => 'kept']
		);
		self::assertSame('kept', $merged['legacy']);
		self::assertSame('2026-06-15T08:00:00', substr((string)$merged['startTime'], 0, 19));
		self::assertArrayNotHasKey('description', $merged); // description not added by builder
	}

	public function testMergeIntoProposalPassesThroughIsoStringsWhenClockFieldsAbsent(): void
	{
		$merged = TimeEntryClockPayloadBuilder::mergeIntoProposal(
			[
				'startTime' => '2026-06-15T08:00:00+02:00',
				'endTime' => '2026-06-15T16:00:00+02:00',
				'breaks' => [
					['start' => '2026-06-15T12:00:00+02:00', 'end' => '2026-06-15T12:30:00+02:00'],
				],
			],
			[]
		);
		self::assertSame('2026-06-15T08:00:00+02:00', $merged['startTime']);
		self::assertSame('2026-06-15T16:00:00+02:00', $merged['endTime']);
		self::assertCount(1, $merged['breaks']);
	}

	public function testEmptyBreaksClearProposal(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:00',
			'breaks' => [],
		]);
		self::assertNotNull($proposal);
		self::assertArrayHasKey('breaks', $proposal);
		self::assertSame([], $proposal['breaks']);
	}

	public function testOmittedBreaksLeaveProposalWithoutBreaksKey(): void
	{
		$proposal = TimeEntryClockPayloadBuilder::buildFromParams([
			'date' => '15.06.2026',
			'startTime' => '08:00',
			'endTime' => '16:00',
		]);
		self::assertNotNull($proposal);
		self::assertArrayNotHasKey('breaks', $proposal);
	}
}
