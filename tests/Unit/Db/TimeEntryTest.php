<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use PHPUnit\Framework\TestCase;

class TimeEntryTest extends TestCase
{
	public function testGetBreakDurationHoursMergesOverlapsAndIgnoresShortBreaksByDefault(): void
	{
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		// Breaks:
		// - 10:00–10:20 (20m)
		// - 10:10–10:30 (20m) overlaps -> merged 10:00–10:30 (30m)
		// - 11:00–11:10 (10m) should be ignored (min 15m DE default)
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T10:00:00+00:00', 'end' => '2024-01-01T10:20:00+00:00'],
			['start' => '2024-01-01T10:10:00+00:00', 'end' => '2024-01-01T10:30:00+00:00'],
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001); // 30m = 0.5h
		$this->assertEqualsWithDelta(7.5, $entry->getDurationHours(), 0.0001); // 8h total - 0.5h break
	}

	public function testAzgTenMinutePortionsCountWhenFloorIsTen(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('at-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setCountableMinBreakMinutes(10);
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'],
			['start' => '2024-01-01T13:00:00+00:00', 'end' => '2024-01-01T13:10:00+00:00'],
			['start' => '2024-01-01T15:00:00+00:00', 'end' => '2024-01-01T15:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001);
		$this->assertSame([], $entry->validate(10));
	}

	public function testValidateRejectsTenMinuteBreakOnGermanFloor(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setCountableMinBreakMinutes(15);
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T12:00:00+00:00', 'end' => '2024-01-01T12:10:00+00:00'],
		]));

		$errors = $entry->validate(15);
		$this->assertArrayHasKey('breaks', $errors);
		$this->assertStringContainsString('15 minutes', $errors['breaks']);
	}

	public function testCanDeleteManualCompletedEntry(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('-30 days'));

		$this->assertTrue($entry->canDelete());
	}

	public function testCanDeleteStampedEntryWithinEditWindow(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('-3 days 09:00:00'));
		$entry->setEndTime(new \DateTime('-3 days 17:00:00'));

		$this->assertTrue($entry->canDelete());
	}

	public function testCannotDeleteStampedEntryOutsideEditWindow(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		$this->assertFalse($entry->canDelete());
	}

	public function testCannotDeleteActiveStampedSession(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-1 hour'));

		$this->assertFalse($entry->canDelete());
	}

	public function testGetBreakDurationHoursCanIncludeShortBreaksWhenConfigured(): void
	{
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'], // 10m
		]));

		$this->assertEqualsWithDelta((10 / 60), $entry->getBreakDurationHours(false), 0.0001);
	}

	public function testPendingApprovalWithoutEndOccupiesLikePaused(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$updated = new \DateTime('2026-08-30T10:00:00+00:00');

		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);
		$entry->setUpdatedAt($updated);

		$end = $entry->occupancyEnd($now);
		$this->assertNotNull($end);
		$this->assertSame('2026-08-30T10:00:00+00:00', $end->format('c'));
	}

	public function testPendingApprovalWithEndUsesRealEnd(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(new \DateTime('2026-08-30T16:00:00+00:00'));

		$end = $entry->occupancyEnd($now);
		$this->assertSame('2026-08-30T16:00:00+00:00', $end->format('c'));
	}

	public function testCompletedWithoutEndDoesNotOccupy(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);

		$this->assertNull($entry->occupancyEnd($now));
	}

	public function testActiveWithoutEndOccupiesUntilNow(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);

		$end = $entry->occupancyEnd($now);
		$this->assertSame('2026-08-30T12:00:00+00:00', $end->format('c'));
	}
}
