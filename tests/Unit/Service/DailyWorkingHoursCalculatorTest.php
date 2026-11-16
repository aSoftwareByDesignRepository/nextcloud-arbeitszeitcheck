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

	public function testSumWorkingHoursForUsersInRangeMatchesPerDayPathPerUser(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');

		$overnightA = new TimeEntry();
		$overnightA->setId(10);
		$overnightA->setUserId('a');
		$overnightA->setStatus(TimeEntry::STATUS_COMPLETED);
		$overnightA->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$overnightA->setEndTime(new \DateTime('2026-05-20 08:00:00', $tz));
		$overnightA->setBreaks(json_encode([]));

		$dayShiftB = new TimeEntry();
		$dayShiftB->setId(11);
		$dayShiftB->setUserId('b');
		$dayShiftB->setStatus(TimeEntry::STATUS_COMPLETED);
		$dayShiftB->setStartTime(new \DateTime('2026-05-20 09:00:00', $tz));
		$dayShiftB->setEndTime(new \DateTime('2026-05-20 17:00:00', $tz));
		$dayShiftB->setBreaks(json_encode([
			['start' => '2026-05-20T12:00:00+02:00', 'end' => '2026-05-20T12:30:00+02:00'],
		]));

		$this->timeEntryMapper->method('findOverlappingForUsers')->willReturn([
			'a' => [$overnightA],
			'b' => [$dayShiftB],
		]);
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			static fn (string $uid): array => $uid === 'a' ? [$overnightA] : [$dayShiftB]
		);

		$rangeStart = new \DateTime('2026-05-19 00:00:00', $tz);
		$rangeEnd = new \DateTime('2026-05-21 00:00:00', $tz);

		$totals = $this->calculator->sumWorkingHoursForUsersInRange(['a', 'b', 'c'], $rangeStart, $rangeEnd);

		// a: 2h on 19th + 8h on 20th; b: 8h - 0.5h break; c: no entries → 0.
		$this->assertEqualsWithDelta(10.0, $totals['a'], 0.05);
		$this->assertEqualsWithDelta(7.5, $totals['b'], 0.05);
		$this->assertSame(0.0, $totals['c']);

		// Parity: identical to the per-user-per-day path.
		$this->assertEqualsWithDelta(
			$this->calculator->sumWorkingHoursForCalendarDaysInRange('a', $rangeStart, $rangeEnd),
			$totals['a'],
			0.001
		);
		$this->assertEqualsWithDelta(
			$this->calculator->sumWorkingHoursForCalendarDaysInRange('b', $rangeStart, $rangeEnd),
			$totals['b'],
			0.001
		);
	}

	public function testSumWorkingHoursForUsersInRangeEmptyAndInvertedRange(): void
	{
		$this->timeEntryMapper->expects($this->never())->method('findOverlappingForUsers');
		$this->assertSame([], $this->calculator->sumWorkingHoursForUsersInRange(
			[], new \DateTime('2026-05-19'), new \DateTime('2026-05-20')));

		$totals = $this->calculator->sumWorkingHoursForUsersInRange(
			['a'], new \DateTime('2026-05-20'), new \DateTime('2026-05-19'));
		$this->assertSame(['a' => 0.0], $totals);
	}

	public function testSumWorkingHoursForUsersInRangeLiveEntryCountsOnceAcrossDays(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');

		// Active overnight session — must clip per day and never double count.
		$active = new TimeEntry();
		$active->setId(20);
		$active->setUserId('a');
		$active->setStatus(TimeEntry::STATUS_ACTIVE);
		$active->setStartTime(new \DateTime('2026-05-19 22:00:00', $tz));
		$active->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlappingForUsers')->willReturn(['a' => [$active]]);

		$rangeStart = new \DateTime('2026-05-19 00:00:00', $tz);
		$rangeEnd = new \DateTime('2026-05-21 00:00:00', $tz);
		$totals = $this->calculator->sumWorkingHoursForUsersInRange(['a'], $rangeStart, $rangeEnd);

		// 2h on the 19th + (now - 00:00 on the 20th, capped at 24h) — whatever
		// "now" is, the total must be ≥ 2h and the per-day clip holds.
		$this->assertGreaterThanOrEqual(2.0, $totals['a']);
		$this->assertLessThanOrEqual(26.0, $totals['a']);
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

	// ------------------------------------------------------------------
	// Split-service days (spec §9.9): frozen context.result.segments are
	// the credited work intervals; result.excluded (X) is never work and a
	// recorded break inside X is never deducted twice (NN-02).
	// ------------------------------------------------------------------

	/**
	 * Entry carrying a frozen split context via the entity accessor. The
	 * anonymous subclass declares the accessors the sibling change adds to
	 * TimeEntry; when they land on the entity itself, this stays compatible.
	 */
	private function newContextEntry(?array $context, bool $invalidContext = false): TimeEntry
	{
		return new class($context, $invalidContext) extends TimeEntry {
			public function __construct(
				private ?array $splitContext,
				private bool $invalidContext,
			) {
				parent::__construct();
			}

			public function getWorkContext(): ?array
			{
				return $this->splitContext;
			}

			public function hasInvalidWorkContext(): bool
			{
				return $this->invalidContext;
			}
		};
	}

	/**
	 * Entry exposing only the raw work_context_json column — exercises the
	 * defensive fallback used until the typed entity accessor exists.
	 */
	private function newRawContextEntry(?string $json): TimeEntry
	{
		return new class($json) extends TimeEntry {
			public function __construct(private ?string $json)
			{
				parent::__construct();
				if (property_exists($this, 'workContextJson')) {
					$this->workContextJson = $json;
				}
			}

			public function getWorkContextJson(): ?string
			{
				return $this->json;
			}
		};
	}

	private function fixtureF1Context(): array
	{
		return [
			'v' => 1,
			'model_id' => 123,
			'timezone' => 'Europe/Berlin',
			'result' => [
				'state' => 'applied',
				'split' => true,
				'reason' => 'clock_out_after_cutoff',
				'excluded' => [['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']],
				'segments' => [
					['2026-11-16T06:00:00+01:00', '2026-11-16T10:00:00+01:00'],
					['2026-11-16T13:30:00+01:00', '2026-11-16T16:30:00+01:00'],
				],
				'break_handling' => 'covered_by_interruption',
				'engine' => 1,
			],
		];
	}

	private function fixtureF1Entry(?array $context, bool $invalidContext = false): TimeEntry
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = $this->newContextEntry($context, $invalidContext);
		$entry->setId(60);
		$entry->setUserId('alex');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2026-11-16 06:00:00', $tz));
		$entry->setEndTime(new \DateTime('2026-11-16 16:30:00', $tz));
		$entry->setBreaks(json_encode([]));
		return $entry;
	}

	public function testSplitServiceEntryCountsFrozenSegmentsNotRawSpan(): void
	{
		// Contract fixture F1 (spec §9.9): 06:00–16:30 Berlin with frozen
		// exclusion 10:00–13:30. Credited work = 06:00–10:00 + 13:30–16:30
		// = 7:00 (25200 s), never the raw 10.5h span.
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = $this->fixtureF1Entry($this->fixtureF1Context());
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$now = new \DateTime('2026-11-16 18:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);

		$this->assertEqualsWithDelta(7.0, $hours, 0.01, 'F1: excluded interruption must not count as work.');
		$this->assertLessThan(8.0, $hours, 'Raw [start,end) accounting would yield ~10.5h.');
	}

	public function testSplitServiceEntryBreakInsideExclusionIsNotDoubleDeducted(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = $this->fixtureF1Entry($this->fixtureF1Context());
		// 10:30–11:00 sits inside X (already non-work — must NOT deduct);
		// 14:00–14:30 sits inside the afternoon segment (effective break).
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T10:30:00+01:00', 'end' => '2026-11-16T11:00:00+01:00'],
			['start' => '2026-11-16T14:00:00+01:00', 'end' => '2026-11-16T14:30:00+01:00'],
		]));
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$now = new \DateTime('2026-11-16 18:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(6.5, $hours, 0.01, 'Only the 30 min break outside X may deduct (NN-02).');

		$breakMinutes = $this->calculator->getBreakMinutesForCalendarDay('alex', $dayStart, $dayEnd, $now);
		$this->assertEqualsWithDelta(30.0, $breakMinutes, 0.01, 'Break inside X is not a countable break (B \\ X).');

		$portions = $this->calculator->countableBreakPortionsOnCalendarDay('alex', $dayStart, $dayEnd, $entry, 15);
		$this->assertSame([30], $portions, 'Only the outside-X portion is a countable break portion.');
	}

	public function testMixedDayLegacyAndSplitEntriesEachUseOwnAccounting(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$split = $this->fixtureF1Entry($this->fixtureF1Context());

		$legacy = new TimeEntry();
		$legacy->setId(61);
		$legacy->setUserId('alex');
		$legacy->setStatus(TimeEntry::STATUS_COMPLETED);
		$legacy->setStartTime(new \DateTime('2026-11-16 17:00:00', $tz));
		$legacy->setEndTime(new \DateTime('2026-11-16 19:00:00', $tz));
		$legacy->setBreaks(json_encode([]));

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$split, $legacy]);

		$now = new \DateTime('2026-11-16 20:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(9.0, $hours, 0.01, 'Split 7:00 + legacy 2:00 — each entry keeps its own accounting (§9.6).');
	}

	public function testLegacyEntrySameSpanWithoutContextUnchanged(): void
	{
		$entry = $this->fixtureF1Entry(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$now = new \DateTime('2026-11-16 18:00:00', new \DateTimeZone('Europe/Berlin'));
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(10.5, $hours, 0.01, 'NULL context keeps byte-identical legacy math.');
	}

	public function testSplitServiceEntryInvalidOrMissingContextFallsBackToLegacySpan(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTime('2026-11-16 18:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$variants = [
			'no result key' => ['v' => 1, 'timezone' => 'Europe/Berlin'],
			'missing segments' => ['v' => 1, 'result' => ['state' => 'applied', 'excluded' => [['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']]]],
			'empty segments' => ['v' => 1, 'result' => ['state' => 'applied', 'segments' => [], 'excluded' => []]],
			'malformed segment pair' => ['v' => 1, 'result' => ['state' => 'applied', 'segments' => [['not-a-date', '2026-11-16T10:00:00+01:00']], 'excluded' => []]],
			'degenerate segment' => ['v' => 1, 'result' => ['state' => 'applied', 'segments' => [['2026-11-16T16:30:00+01:00', '2026-11-16T06:00:00+01:00']], 'excluded' => []]],
			'malformed excluded' => ['v' => 1, 'result' => ['state' => 'applied', 'segments' => [['2026-11-16T06:00:00+01:00', '2026-11-16T16:30:00+01:00']], 'excluded' => [['x', 'y']]]],
			'unknown state' => ['v' => 1, 'result' => ['state' => 'pending', 'segments' => [['2026-11-16T06:00:00+01:00', '2026-11-16T16:30:00+01:00']], 'excluded' => []]],
		];

		$entries = [];
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			static function () use (&$entries): array {
				return $entries;
			}
		);

		foreach ($variants as $label => $context) {
			$entries = [$this->fixtureF1Entry($context)];

			$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
			$this->assertEqualsWithDelta(10.5, $hours, 0.01, "Variant '$label' must keep legacy accounting.");
		}

		// Entity-flagged invalid context → legacy as well.
		$entries = [$this->fixtureF1Entry($this->fixtureF1Context(), true)];
		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(10.5, $hours, 0.01, 'hasInvalidWorkContext() must keep legacy accounting.');
	}

	public function testSplitServiceEntryEpochPairSegments(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$ts = static fn (string $local): int => (new \DateTime($local, $tz))->getTimestamp();
		$context = [
			'v' => 1,
			'result' => [
				'state' => 'applied',
				'split' => true,
				'excluded' => [[$ts('2026-11-16 10:00:00'), $ts('2026-11-16 13:30:00')]],
				'segments' => [
					[$ts('2026-11-16 06:00:00'), $ts('2026-11-16 10:00:00')],
					[$ts('2026-11-16 13:30:00'), $ts('2026-11-16 16:30:00')],
				],
			],
		];
		$entry = $this->fixtureF1Entry($context);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$now = new \DateTime('2026-11-16 18:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(7.0, $hours, 0.01, 'Epoch-pair segments must be honoured identically.');
	}

	public function testSplitServiceEntryReviewStateUnsplitSegmentsMatchLegacy(): void
	{
		// review entries are accounted unsplit — the stored result carries a
		// single full-span segment and no exclusion (spec §9.6 closure rule).
		$context = $this->fixtureF1Context();
		$context['result']['state'] = 'review';
		$context['result']['split'] = false;
		$context['result']['excluded'] = [];
		$context['result']['segments'] = [['2026-11-16T06:00:00+01:00', '2026-11-16T16:30:00+01:00']];

		$entry = $this->fixtureF1Entry($context);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$now = new \DateTime('2026-11-16 18:00:00', new \DateTimeZone('Europe/Berlin'));
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(10.5, $hours, 0.01, 'review = conservative unsplit accounting, identical to legacy.');
	}

	public function testGetEntryWorkingHoursOnCalendarDayUsesFrozenSegments(): void
	{
		$entry = $this->fixtureF1Entry($this->fixtureF1Context());
		$now = new \DateTime('2026-11-16 18:00:00', new \DateTimeZone('Europe/Berlin'));
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getEntryWorkingHoursOnCalendarDay($entry, $dayStart, $dayEnd, $now);
		$this->assertEqualsWithDelta(7.0, $hours, 0.01);
	}

	public function testSplitServiceEntryRawJsonColumnFallback(): void
	{
		// Until the typed entity accessor lands, the calculator reads the raw
		// work_context_json column defensively.
		$entry = $this->newRawContextEntry(json_encode($this->fixtureF1Context()));
		$entry->setId(62);
		$entry->setUserId('alex');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry->setStartTime(new \DateTime('2026-11-16 06:00:00', $tz));
		$entry->setEndTime(new \DateTime('2026-11-16 16:30:00', $tz));
		$entry->setBreaks(json_encode([]));

		$entries = [$entry];
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			static function () use (&$entries): array {
				return $entries;
			}
		);
		$now = new \DateTime('2026-11-16 18:00:00', $tz);
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($now);

		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(7.0, $hours, 0.01, 'Raw JSON context must drive segment accounting.');

		// Unparseable JSON → legacy.
		$broken = $this->newRawContextEntry('{not json');
		$broken->setId(63);
		$broken->setUserId('alex');
		$broken->setStatus(TimeEntry::STATUS_COMPLETED);
		$broken->setStartTime(new \DateTime('2026-11-16 06:00:00', $tz));
		$broken->setEndTime(new \DateTime('2026-11-16 16:30:00', $tz));
		$broken->setBreaks(json_encode([]));
		$entries = [$broken];
		$hours = $this->calculator->getWorkingHoursForCalendarDay('alex', $dayStart, $dayEnd, null, $now);
		$this->assertEqualsWithDelta(10.5, $hours, 0.01, 'Unparseable context JSON must keep legacy accounting.');
	}

	public function testSplitServiceEntryPresenceTimerStaysRawSpan(): void
	{
		// current_session_duration is a presence timer (spec §9.9): it counts
		// clocked-in span, not credited segment time.
		$entry = $this->fixtureF1Entry($this->fixtureF1Context());
		$now = new \DateTime('2026-11-16 18:00:00', new \DateTimeZone('Europe/Berlin'));

		$seconds = $this->calculator->getLiveSessionSecondsOnCalendarDay($entry, $now);
		$this->assertSame(37800, $seconds, 'Presence span 06:00–16:30 stays 10.5h for split entries.');
	}
}