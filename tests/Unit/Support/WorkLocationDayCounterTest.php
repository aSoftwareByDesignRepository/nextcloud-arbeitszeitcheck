<?php

declare(strict_types=1);

/**
 * Tests for {@see WorkLocationDayCounter} (GH #43).
 *
 * Contract: a calendar day counts toward a location when at least one entry
 * starting that day (in the organisation timezone) carries it; mixed days
 * appear under both counters; office/NULL rows never count.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Support\WorkLocationDayCounter;
use PHPUnit\Framework\TestCase;

class WorkLocationDayCounterTest extends TestCase
{
	private function entry(string $start, ?string $location): TimeEntry
	{
		$e = new TimeEntry();
		$e->setStartTime(new \DateTime($start));
		$e->setWorkLocation($location);
		return $e;
	}

	public function testEmptyAndNonCountingEntriesYieldZero(): void
	{
		$tz = new \DateTimeZone('UTC');
		$this->assertSame(['home' => 0, 'external' => 0], WorkLocationDayCounter::countDays([], $tz));
		$this->assertSame(
			['home' => 0, 'external' => 0],
			WorkLocationDayCounter::countDays(
				[$this->entry('2026-01-15T09:00:00+00:00', 'office'),
				 $this->entry('2026-01-16T09:00:00+00:00', null)],
				$tz
			)
		);
	}

	public function testDistinctDaysPerLocation(): void
	{
		$tz = new \DateTimeZone('UTC');
		$entries = [
			$this->entry('2026-01-15T09:00:00+00:00', 'home'),
			$this->entry('2026-01-15T13:00:00+00:00', 'home'), // same day -> once
			$this->entry('2026-01-16T09:00:00+00:00', 'home'),
			$this->entry('2026-01-17T09:00:00+00:00', 'external'),
		];
		$this->assertSame(['home' => 2, 'external' => 1], WorkLocationDayCounter::countDays($entries, $tz));
	}

	public function testMixedLocationDayCountsUnderBoth(): void
	{
		$tz = new \DateTimeZone('UTC');
		$entries = [
			$this->entry('2026-01-15T09:00:00+00:00', 'home'),
			$this->entry('2026-01-15T14:00:00+00:00', 'external'),
		];
		$this->assertSame(['home' => 1, 'external' => 1], WorkLocationDayCounter::countDays($entries, $tz));
	}

	public function testDayBoundaryUsesOrgTimezoneNotUtc(): void
	{
		// 23:30 UTC on Jan 15 = 00:30 on Jan 16 in Europe/Berlin (CET, +1).
		$berlin = new \DateTimeZone('Europe/Berlin');
		$entries = [$this->entry('2026-01-15T23:30:00+00:00', 'home')];
		$utc = new \DateTimeZone('UTC');
		$this->assertSame(['home' => 1, 'external' => 0], WorkLocationDayCounter::countDays($entries, $utc));

		// Two entries 2h apart straddle the Berlin midnight: one day in UTC,
		// two days in Berlin.
		$pair = [
			$this->entry('2026-01-15T22:30:00+00:00', 'home'), // 23:30 Berlin Jan 15
			$this->entry('2026-01-15T23:30:00+00:00', 'home'), // 00:30 Berlin Jan 16
		];
		$this->assertSame(['home' => 1, 'external' => 0], WorkLocationDayCounter::countDays($pair, $utc));
		$this->assertSame(['home' => 2, 'external' => 0], WorkLocationDayCounter::countDays($pair, $berlin));
	}

	public function testEntryWithoutStartTimeIsSkipped(): void
	{
		$tz = new \DateTimeZone('UTC');
		$e = new TimeEntry();
		$e->setWorkLocation('home');
		$this->assertSame(['home' => 0, 'external' => 0], WorkLocationDayCounter::countDays([$e], $tz));
	}
}
