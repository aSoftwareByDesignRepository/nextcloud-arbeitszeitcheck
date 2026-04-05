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
		// - 11:00–11:10 (10m) should be ignored (min 15m)
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T10:00:00+00:00', 'end' => '2024-01-01T10:20:00+00:00'],
			['start' => '2024-01-01T10:10:00+00:00', 'end' => '2024-01-01T10:30:00+00:00'],
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001); // 30m = 0.5h
		$this->assertEqualsWithDelta(7.5, $entry->getDurationHours(), 0.0001); // 8h total - 0.5h break
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
}

