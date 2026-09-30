<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DailyWorkingHoursCalculatorTest extends TestCase
{
	private DailyWorkingHoursCalculator $calculator;
	private TimeEntryMapper $timeEntryMapper;
	private TimeZoneService $timeZoneService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		$this->calculator = new DailyWorkingHoursCalculator(
			$this->timeEntryMapper,
			$this->timeZoneService,
		);
	}

	public function testOvernightActiveSessionOnlyCountsTodayPortionAfterMidnight(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		// Tuesday 22:00 – still running; "now" is Wednesday 01:33.
		$start = new \DateTime('2026-05-19 22:00:00', $tz);
		$now = new \DateTime('2026-05-20 01:33:00', $tz);
		[$wedStart, $wedEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$active = new TimeEntry();
		$active->setId(99);
		$active->setUserId($userId);
		$active->setStatus(TimeEntry::STATUS_ACTIVE);
		$active->setStartTime($start);
		$active->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$active]);

		$hoursOnWednesday = $this->calculator->getWorkingHoursForCalendarDay(
			$userId,
			$wedStart,
			$wedEnd,
			$active,
			$now,
		);

		$this->assertEqualsWithDelta(1.55, $hoursOnWednesday, 0.05, 'Only 00:00–01:33 on Wednesday must count.');
	}

	public function testMorningTailOfPreviousNightShiftCountsOnTodayNotOnClockInDay(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		// Monday 22:00 – Tuesday 08:00 (completed).
		$completed = new TimeEntry();
		$completed->setId(1);
		$completed->setUserId($userId);
		$completed->setStatus(TimeEntry::STATUS_COMPLETED);
		$completed->setStartTime(new \DateTime('2026-05-18 22:00:00', $tz));
		$completed->setEndTime(new \DateTime('2026-05-19 08:00:00', $tz));
		$completed->setBreaks(json_encode([]));

		// Tuesday 22:00 – active; now Wednesday 01:33.
		$active = new TimeEntry();
		$active->setId(2);
		$active->setUserId($userId);
		$active->setStatus(TimeEntry::STATUS_ACTIVE);
		$active->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$active->setBreaks(json_encode([]));

		$now = new \DateTime('2026-05-20 01:33:00', $tz);
		[$wedStart, $wedEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$completed, $active]);

		$otherHours = $this->calculator->getWorkingHoursForCalendarDay(
			$userId,
			$wedStart,
			$wedEnd,
			null,
			$now,
			$active->getId(),
		);

		$totalHours = $this->calculator->getWorkingHoursForCalendarDay(
			$userId,
			$wedStart,
			$wedEnd,
			$active,
			$now,
		);

		$this->assertEqualsWithDelta(0.0, $otherHours, 0.05, 'Completed shift ended Tuesday; nothing on Wednesday morning from it.');
		$this->assertLessThan(10.0, $totalHours, 'Must not falsely hit the daily maximum around 01:33.');
	}

	public function testGetWorkingHoursForTodayMatchesOvernightWachdienstRegression(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		$completed = new TimeEntry();
		$completed->setId(1);
		$completed->setUserId($userId);
		$completed->setStatus(TimeEntry::STATUS_COMPLETED);
		$completed->setStartTime(new \DateTime('2026-05-18 22:00:00', $tz));
		$completed->setEndTime(new \DateTime('2026-05-19 08:00:00', $tz));
		$completed->setBreaks(json_encode([]));

		$active = new TimeEntry();
		$active->setId(2);
		$active->setUserId($userId);
		$active->setStatus(TimeEntry::STATUS_ACTIVE);
		$active->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$active->setBreaks(json_encode([]));

		$now = new \DateTime('2026-05-20 01:33:00', $tz);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$completed, $active]);

		$todayHours = $this->calculator->getWorkingHoursForToday($userId, $now);

		$this->assertLessThan(10.0, $todayHours, 'getTodayHours path must stay below §3 max around 01:33.');
		$this->assertEqualsWithDelta(1.55, $todayHours, 0.05);
	}

	public function testFindCalendarDayExceedingMaximumDetectsOverloadedDay(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		$existing = new TimeEntry();
		$existing->setId(1);
		$existing->setUserId($userId);
		$existing->setStatus(TimeEntry::STATUS_COMPLETED);
		$existing->setStartTime(new \DateTime('2026-05-20 06:00:00', $tz));
		$existing->setEndTime(new \DateTime('2026-05-20 16:30:00', $tz));
		$existing->setBreaks(json_encode([]));

		$proposed = new TimeEntry();
		$proposed->setId(2);
		$proposed->setUserId($userId);
		$proposed->setStatus(TimeEntry::STATUS_COMPLETED);
		$proposed->setStartTime(new \DateTime('2026-05-20 16:30:00', $tz));
		$proposed->setEndTime(new \DateTime('2026-05-20 21:00:00', $tz));
		$proposed->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$existing, $proposed]);

		$violation = $this->calculator->findCalendarDayExceedingMaximum($userId, $proposed, 10.0, $proposed->getId());

		$this->assertNotNull($violation);
		$this->assertSame('2026-05-20', $violation['date']);
		$this->assertGreaterThan(10.0, $violation['hours']);
	}

	public function testSumWorkingHoursForCalendarDaysInRangeSplitsOvernightAcrossDays(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		$overnight = new TimeEntry();
		$overnight->setId(10);
		$overnight->setUserId($userId);
		$overnight->setStatus(TimeEntry::STATUS_COMPLETED);
		$overnight->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$overnight->setEndTime(new \DateTime('2026-05-20 08:00:00', $tz));
		$overnight->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$overnight]);

		$rangeStart = new \DateTime('2026-05-19 00:00:00', $tz);
		$rangeEnd = new \DateTime('2026-05-21 00:00:00', $tz);

		$total = $this->calculator->sumWorkingHoursForCalendarDaysInRange($userId, $rangeStart, $rangeEnd);

		$this->assertEqualsWithDelta(10.0, $total, 0.05, '2h on 19th + 8h on 20th, not 10h lumped on start day.');
	}

	public function testFindAllCalendarDaysExceedingMaximumEmptyForLegalOvernightShift(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		$overnight = new TimeEntry();
		$overnight->setId(10);
		$overnight->setUserId($userId);
		$overnight->setStatus(TimeEntry::STATUS_COMPLETED);
		$overnight->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$overnight->setEndTime(new \DateTime('2026-05-20 08:00:00', $tz));
		$overnight->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$overnight]);

		$days = $this->calculator->findAllCalendarDaysExceedingMaximum($userId, $overnight, 10.0);

		$this->assertSame([], $days, '22:00–08:00 must not produce a false §3 violation.');
	}

	public function testFindAllCalendarDaysExceedingMaximumDetectsOverloadedDay(): void
	{
		$userId = 'guard';
		$tz = new \DateTimeZone('Europe/Berlin');

		$longDay = new TimeEntry();
		$longDay->setId(11);
		$longDay->setUserId($userId);
		$longDay->setStatus(TimeEntry::STATUS_COMPLETED);
		$longDay->setStartTime(new \DateTime('2026-05-20 05:00:00', $tz));
		$longDay->setEndTime(new \DateTime('2026-05-20 17:00:00', $tz));
		$longDay->setBreaks(json_encode([
			['start' => '2026-05-20T12:00:00+02:00', 'end' => '2026-05-20T13:00:00+02:00'],
		]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$longDay]);

		$days = $this->calculator->findAllCalendarDaysExceedingMaximum($userId, $longDay, 10.0);

		$this->assertCount(1, $days);
		$this->assertSame('2026-05-20', $days[0]['date']);
		$this->assertGreaterThan(10.0, $days[0]['hours']);
	}

	public function testCompletedEntryWithFutureEndIsClippedToEvaluationInstant(): void
	{
		$userId = 'alex';
		$tz = new \DateTimeZone('Europe/Berlin');

		// Planned/mistaken completed row: today 09:30–17:00 while "now" is 11:45.
		$planned = new TimeEntry();
		$planned->setId(42);
		$planned->setUserId($userId);
		$planned->setStatus(TimeEntry::STATUS_COMPLETED);
		$planned->setStartTime(new \DateTime('2026-07-18 09:30:00', $tz));
		$planned->setEndTime(new \DateTime('2026-07-18 17:00:00', $tz));
		$planned->setBreaks(json_encode([]));

		$now = new \DateTime('2026-07-18 11:45:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$planned]);

		$hours = $this->calculator->getWorkingHoursForCalendarDay(
			$userId,
			$dayStart,
			$dayEnd,
			null,
			$now,
		);

		$this->assertEqualsWithDelta(
			2.25,
			$hours,
			0.05,
			'Only 09:30–11:45 must count; the future tail until 17:00 must not inflate today hours.'
		);
	}

	public function testCountableBreakPortionsIncludeLiveEntryAndIgnoreSubMinBreaks(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$morning = new TimeEntry();
		$morning->setId(7);
		$morning->setUserId('alex');
		$morning->setStatus(TimeEntry::STATUS_COMPLETED);
		$morning->setStartTime(new \DateTime('2026-05-02 08:00:00', $tz));
		$morning->setEndTime(new \DateTime('2026-05-02 12:00:00', $tz));
		$morning->setBreaks(json_encode([[
			'start' => '2026-05-02T10:00:00+02:00',
			'end' => '2026-05-02T10:10:00+02:00',
		]]));

		$afternoon = new TimeEntry();
		$afternoon->setId(8);
		$afternoon->setUserId('alex');
		$afternoon->setStatus(TimeEntry::STATUS_COMPLETED);
		$afternoon->setStartTime(new \DateTime('2026-05-02 13:00:00', $tz));
		$afternoon->setEndTime(new \DateTime('2026-05-02 17:00:00', $tz));
		$afternoon->setBreaks(json_encode([[
			'start' => '2026-05-02T15:00:00+02:00',
			'end' => '2026-05-02T15:30:00+02:00',
		]]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$morning, $afternoon]);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage(new \DateTime('2026-05-02 12:00:00', $tz));

		$portions = $this->calculator->countableBreakPortionsOnCalendarDay(
			'alex',
			$dayStart,
			$dayEnd,
			$afternoon,
			15,
			8,
		);

		$this->assertSame([30], $portions, '10-minute morning break is below the DE floor; afternoon 30 minutes counts.');
	}

	public function testGetLiveSessionSecondsOnCalendarDay(): void
	{
		// active session started 2h ago -> ~7200s
		$now = new \DateTime('2026-03-11 10:00:00', new \DateTimeZone('UTC'));
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setStartTime((clone $now)->modify('-2 hours'));
		$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE);
		$this->assertSame(7200, $this->calculator->getLiveSessionSecondsOnCalendarDay($entry, $now));

		// completed entry with future end is clipped to the reference instant
		$done = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$done->setStartTime((clone $now)->modify('-1 hour'));
		$done->setEndTime((clone $now)->modify('+2 hours'));
		$done->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$this->assertSame(3600, $this->calculator->getLiveSessionSecondsOnCalendarDay($done, $now));

		// entry on another day -> 0
		$other = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$other->setStartTime(new \DateTime('2026-03-10 08:00:00', new \DateTimeZone('UTC')));
		$other->setEndTime(new \DateTime('2026-03-10 09:00:00', new \DateTimeZone('UTC')));
		$other->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$this->assertSame(0, $this->calculator->getLiveSessionSecondsOnCalendarDay($other, $now));

		// entry without start -> 0
		$broken = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$broken->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$this->assertSame(0, $this->calculator->getLiveSessionSecondsOnCalendarDay($broken, $now));
	}
}