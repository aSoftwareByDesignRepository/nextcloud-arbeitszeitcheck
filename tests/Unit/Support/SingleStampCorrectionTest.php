<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Support\TimeEntryClockPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Single-stamp correction: unfinished sessions admit correction requests and
 * partial clock payloads (endTime-only) resolve onto the session's start day.
 */
class SingleStampCorrectionTest extends TestCase
{
	private function entryWithStatus(string $status): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-10-28 08:00:00'));
		$entry->setStatus($status);
		return $entry;
	}

	public function testActiveSessionMayRequestCorrection(): void
	{
		$this->assertTrue($this->entryWithStatus(TimeEntry::STATUS_ACTIVE)->canRequestCorrection());
	}

	public function testBreakSessionMayNotRequestCorrection(): void
	{
		$this->assertFalse($this->entryWithStatus(TimeEntry::STATUS_BREAK)->canRequestCorrection());
	}

	public function testPendingSessionMayNotRequestCorrection(): void
	{
		$this->assertFalse($this->entryWithStatus(TimeEntry::STATUS_PENDING_APPROVAL)->canRequestCorrection());
	}

	public function testCompletedAndPausedStillCorrectable(): void
	{
		$this->assertTrue($this->entryWithStatus(TimeEntry::STATUS_COMPLETED)->canRequestCorrection());
		$this->assertTrue($this->entryWithStatus(TimeEntry::STATUS_PAUSED)->canRequestCorrection());
	}

	public function testEndOnlyProposalResolvesOnSessionDay(): void
	{
		$result = TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'endTime' => '17:30'],
			15,
			new \DateTime('2026-10-28 08:00:00'),
		);
		$this->assertNotNull($result);
		$this->assertArrayNotHasKey('startTime', $result);
		$this->assertSame('2026-10-28T17:30:00+00:00', $result['endTime']);
	}

	public function testEndOnlyProposalOvernightShiftsNextDay(): void
	{
		// End 06:15 < session start 22:00 → +1 day.
		$result = TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'endTime' => '06:15'],
			15,
			new \DateTime('2026-10-28 22:00:00'),
		);
		$this->assertNotNull($result);
		$this->assertSame('2026-10-29T06:15:00+00:00', $result['endTime']);
	}

	public function testStartAndEndPairKeepsExistingOvernightRule(): void
	{
		$result = TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'startTime' => '22:00', 'endTime' => '06:15'],
			15,
		);
		$this->assertSame('2026-10-28T22:00:00+00:00', $result['startTime']);
		$this->assertSame('2026-10-29T06:15:00+00:00', $result['endTime']);
	}

	public function testStartOnlyProposalIsAllowed(): void
	{
		$result = TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'startTime' => '07:45'],
			15,
		);
		$this->assertSame('2026-10-28T07:45:00+00:00', $result['startTime']);
		$this->assertArrayNotHasKey('endTime', $result);
	}

	public function testNoClockFieldsReturnsNull(): void
	{
		$this->assertNull(TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28'], 15
		));
		$this->assertNull(TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['endTime' => '17:30'], 15 // no date → cannot resolve
		));
		$this->assertNull(TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'endTime' => 'not-a-time'], 15
		));
	}

	public function testBreaksNormalizedOnSameDay(): void
	{
		$result = TimeEntryClockPayloadBuilder::buildPartialFromParams(
			['date' => '2026-10-28', 'endTime' => '17:30', 'breaks' => [['start' => '12:00', 'end' => '12:30'], ['start' => 'x', 'end' => 'y']]],
			15,
			new \DateTime('2026-10-28 08:00:00'),
		);
		$this->assertCount(1, $result['breaks']);
		$this->assertSame('2026-10-28T12:00:00+00:00', $result['breaks'][0]['start']);
	}

	public function testEndedReasonConstantDistinct(): void
	{
		$this->assertSame('approved_clock_out', TimeEntry::ENDED_REASON_APPROVED_CLOCK_OUT);
		$this->assertNotSame(TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT, TimeEntry::ENDED_REASON_APPROVED_CLOCK_OUT);
	}
}
