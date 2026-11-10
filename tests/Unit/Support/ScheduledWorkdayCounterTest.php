<?php

declare(strict_types=1);

/**
 * ScheduledWorkdayCounter — single authority for absence day counting.
 * spec: planning/features/arbeitszeitcheck/absence-day-accuracy-pack-2026-11
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScheduledWorkdayCounterTest extends TestCase
{
	private UserWorkingTimeModelMapper&MockObject $userModelMapper;
	private WorkingTimeModelMapper&MockObject $modelMapper;
	private HolidayService&MockObject $holidayService;
	private ScheduledWorkdayCounter $counter;

	protected function setUp(): void
	{
		$this->userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->modelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);
		$this->counter = new ScheduledWorkdayCounter(
			$this->userModelMapper,
			$this->modelMapper,
			$this->holidayService
		);
	}

	/** @param array<string,bool> $work map mon..sun → work */
	private function modelWithSchedule(array $work): WorkingTimeModel
	{
		$days = [];
		foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
			$days[$d] = ($work[$d] ?? false)
				? ['work' => true, 'start' => '08:00', 'end' => '16:00', 'breaks' => []]
				: ['work' => false];
		}
		$model = new WorkingTimeModel();
		$model->setBreakRulesArray([WeekdaySchedule::KEY => ['days' => $days]]);
		return $model;
	}

	private function withModel(?WorkingTimeModel $model): void
	{
		$assignment = new UserWorkingTimeModel();
		$assignment->setWorkingTimeModelId(7);
		$this->userModelMapper->method('findCurrentByUser')->willReturn(
			$model === null ? null : $assignment
		);
		if ($model === null) {
			$this->modelMapper->method('find')->willThrowException(new \RuntimeException('n/a'));
		} else {
			$this->modelMapper->method('find')->with(7)->willReturn($model);
		}
	}

	// 2026-11-16 is a Monday, 20.11 Friday, 22.11 Sunday.

	public function testFourDayScheduleCountsFourForMonFriWeek(): void
	{
		// Complimant case: Mo–Do schedule, Fr/Sa/So free.
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')->willReturn([]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(4.0, $days);
	}

	public function testFreeWeekdaysExcludedFromSevenDayRange(): void
	{
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')->willReturn([]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-22'));
		$this->assertSame(4.0, $days);
	}

	public function testWeekendWorkDayCounts(): void
	{
		// Six-day schedule incl. Saturday → Mo–Sa = 6 (may exceed Mo–Fr weight).
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
			'fri' => true, 'sat' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')->willReturn([]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-21'));
		$this->assertSame(6.0, $days);
	}

	public function testFullHolidayOnWorkdayCountsZero(): void
	{
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')
			->willReturn(['2026-11-18' => 1.0]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(3.0, $days);
	}

	public function testHalfHolidayOnWorkdayCountsHalf(): void
	{
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')
			->willReturn(['2026-11-18' => 0.5]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(3.5, $days);
	}

	public function testHolidayOnNonWorkdayDoesNotChangeCount(): void
	{
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')
			->willReturn(['2026-11-20' => 1.0]); // Friday — not a work day anyway

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(4.0, $days);
	}

	public function testNoScheduleFallsBackToMonFriService(): void
	{
		$this->withModel(null);
		$this->holidayService->expects($this->once())
			->method('computeWorkingDaysForUser')
			->willReturn(5.0);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(5.0, $days);
	}

	public function testModelWithoutScheduleFallsBackToMonFriService(): void
	{
		$model = new WorkingTimeModel(); // no break_rules.weekday_schedule
		$this->withModel($model);
		$this->holidayService->expects($this->once())
			->method('computeWorkingDaysForUser')
			->willReturn(5.0);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(5.0, $days);
	}

	public function testModelLookupFailureFallsBackSafely(): void
	{
		$this->userModelMapper->method('findCurrentByUser')
			->willThrowException(new \RuntimeException('db gone'));
		$this->holidayService->expects($this->once())
			->method('computeWorkingDaysForUser')
			->willReturn(5.0);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));
		$this->assertSame(5.0, $days);
	}

	public function testReversedRangeIsNormalized(): void
	{
		$this->withModel($this->modelWithSchedule(['mon' => true, 'fri' => true]));
		$this->holidayService->method('getHolidayWeightMapForUser')->willReturn([]);

		$days = $this->counter->daysForUser('u1', new \DateTime('2026-11-20'), new \DateTime('2026-11-16'));
		$this->assertSame(2.0, $days); // Mon + Fri are work days
	}

	public function testDaysPerYearSplitsAtYearBoundary(): void
	{
		$this->withModel($this->modelWithSchedule([
			'mon' => true, 'tue' => true, 'wed' => true, 'thu' => true, 'fri' => true,
		]));
		$this->holidayService->method('getHolidayWeightMapForUser')->willReturn([]);

		// Wed 30.12.2026 – Fri 01.01.2027 → 2 days in 2026, 1 in 2027
		$perYear = $this->counter->daysPerYearForUser(
			'u1', new \DateTime('2026-12-30'), new \DateTime('2027-01-01'));
		$this->assertSame(2.0, $perYear[2026]);
		$this->assertSame(1.0, $perYear[2027]);
	}

	public function testBasisReportsScheduleVsFallback(): void
	{
		$this->withModel($this->modelWithSchedule(['mon' => true]));
		$this->assertSame('weekday_schedule', $this->counter->basisForUser('u1'));

		$this->withModel(null);
		$this->assertSame('weekdays_mon_fri', $this->counter->basisForUser('u1'));
	}
}
