<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use PHPUnit\Framework\TestCase;

class WeekdayScheduleTest extends TestCase
{
	public function testBanssPresetNets(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$this->assertSame(8.5, $schedule->netHoursForWeekday('mon'));
		$this->assertSame(8.5, $schedule->netHoursForWeekday('thu'));
		$this->assertSame(4.5, $schedule->netHoursForWeekday('fri'));
		$this->assertSame(0.0, $schedule->netHoursForWeekday('sat'));
		$this->assertSame(38.5, $schedule->weeklyNetHours());
		$this->assertSame(5.0, $schedule->workDaysPerWeek());
		$this->assertEqualsWithDelta(7.7, $schedule->averageDailyNetHours(), 0.0001);
	}

	public function testRequiredHoursWeekNoHolidays(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		// Mon 2026-08-03 .. Fri 2026-08-07
		$start = new \DateTime('2026-08-03');
		$end = new \DateTime('2026-08-07');
		$hours = $schedule->requiredHoursForDateRange($start, $end, static fn (): float => 0.0);
		$this->assertSame(38.5, $hours);
	}

	public function testHalfDayFridayHoliday(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$start = new \DateTime('2026-08-07'); // Friday
		$end = new \DateTime('2026-08-07');
		$hours = $schedule->requiredHoursForDateRange(
			$start,
			$end,
			static function (\DateTime $d): float {
				return $d->format('Y-m-d') === '2026-08-07' ? 0.5 : 0.0;
			}
		);
		$this->assertSame(2.25, $hours);
	}

	public function testFullHolidayZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$start = new \DateTime('2026-08-03');
		$end = new \DateTime('2026-08-03');
		$hours = $schedule->requiredHoursForDateRange($start, $end, static fn (): float => 1.0);
		$this->assertSame(0.0, $hours);
	}

	public function testValidateRejectsBreakOutsideSpan(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['breaks'] = [
			['start' => '06:00', 'end' => '07:00', 'paid' => false],
		];
		$errors = WeekdaySchedule::validate($raw);
		$this->assertContains('SCHEDULE_INVALID_BREAK', $errors);
	}

	public function testTryFromBreakRulesIgnoresCorrupt(): void
	{
		$this->assertNull(WeekdaySchedule::tryFromBreakRules(null));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules([]));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules(['break_policy' => 'flex']));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules([
			WeekdaySchedule::KEY => ['days' => ['mon' => ['work' => true]]],
		]));
	}

	public function testTryFromBreakRulesPreservesLegacyKeys(): void
	{
		$merged = WeekdaySchedule::mergeIntoBreakRules(
			['break_policy' => 'flex', 'auto_fallback_minutes' => 120],
			WeekdaySchedule::banssPreset()
		);
		$this->assertSame('flex', $merged['break_policy']);
		$this->assertSame(120, $merged['auto_fallback_minutes']);
		$schedule = WeekdaySchedule::tryFromBreakRules($merged);
		$this->assertNotNull($schedule);
		$this->assertSame(38.5, $schedule->weeklyNetHours());
	}

	public function testPaidBreakDoesNotReduceNet(): void
	{
		$raw = [
			'version' => 1,
			'days' => [
				'mon' => [
					'work' => true,
					'start' => '08:00',
					'end' => '16:00',
					'breaks' => [
						['start' => '12:00', 'end' => '13:00', 'paid' => true],
					],
				],
				'tue' => ['work' => false],
				'wed' => ['work' => false],
				'thu' => ['work' => false],
				'fri' => ['work' => false],
				'sat' => ['work' => false],
				'sun' => ['work' => false],
			],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(8.0, $schedule->netHoursForWeekday('mon'));
	}

	public function testStoredStringFalseWorkIsNotAWorkday(): void
	{
		// "work":"false" (form-encoded string) must never become a workday.
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['sun'] = [
			'work' => 'false',
			'start' => '08:00',
			'end' => '16:00',
			'breaks' => [],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertFalse($schedule->isWorkDay('sun'));
		$this->assertSame(0.0, $schedule->netHoursForWeekday('sun'));
	}

	public function testStoredStringFalsePaidStaysUnpaid(): void
	{
		// "paid":"false" must not turn an unpaid break into a paid one.
		$raw = [
			'version' => 1,
			'days' => [
				'mon' => [
					'work' => true,
					'start' => '08:00',
					'end' => '16:00',
					'breaks' => [
						['start' => '12:00', 'end' => '13:00', 'paid' => 'false'],
					],
				],
				'tue' => ['work' => false],
				'wed' => ['work' => false],
				'thu' => ['work' => false],
				'fri' => ['work' => false],
				'sat' => ['work' => false],
				'sun' => ['work' => false],
			],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		// unpaid 60-min break is deducted → 7h net, not 8h
		$this->assertSame(7.0, $schedule->netHoursForWeekday('mon'));
		$this->assertFalse($schedule->toArray()['days']['mon']['breaks'][0]['paid']);
	}

	public function testValidateRejectsEndBeforeStart(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['end'] = '06:00';
		$this->assertContains('SCHEDULE_END_BEFORE_START', WeekdaySchedule::validate($raw));
	}

	public function testValidateRejectsMissingDaysKey(): void
	{
		$this->assertSame(['SCHEDULE_MISSING_DAYS'], WeekdaySchedule::validate(['version' => 1]));
	}

	public function testValidateRejectsEmptyWorkdayTimes(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['start'] = '';
		$this->assertContains('SCHEDULE_EMPTY_WORKDAY', WeekdaySchedule::validate($raw));
	}

	public function testRequiredHoursInvertedRangeIsZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$hours = $schedule->requiredHoursForDateRange(
			new \DateTime('2026-08-07'),
			new \DateTime('2026-08-03'),
			static fn (): float => 0.0
		);
		$this->assertSame(0.0, $hours);
	}

	public function testWeekendOnlyRangeIsZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$hours = $schedule->requiredHoursForDateRange(
			new \DateTime('2026-08-08'),
			new \DateTime('2026-08-09'),
			static fn (): float => 0.0
		);
		$this->assertSame(0.0, $hours);
	}

	public function testToArrayRoundTrip(): void
	{
		$preset = WeekdaySchedule::banssPreset();
		$schedule = WeekdaySchedule::fromValidated($preset);
		$again = WeekdaySchedule::fromValidated($schedule->toArray());
		$this->assertSame(38.5, $again->weeklyNetHours());
		$this->assertSame(WeekdaySchedule::VERSION, $again->toArray()['version']);
	}
}
