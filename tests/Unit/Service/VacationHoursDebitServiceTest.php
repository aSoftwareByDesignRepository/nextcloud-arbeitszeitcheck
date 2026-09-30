<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class VacationHoursDebitServiceTest extends TestCase
{
	private function unitService(float $hpd = 8.0): VacationUnitService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($hpd): string {
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return (string)$hpd;
				}
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				return $default;
			}
		);

		return new VacationUnitService($config);
	}

	private function holidayNone(): HolidayService
	{
		$h = $this->createMock(HolidayService::class);
		$h->method('getHolidayWeightForUser')->willReturn(0.0);
		$h->method('computeWorkingDaysForUser')->willReturnCallback(
			static function (string $userId, \DateTime $start, \DateTime $end): float {
				unset($userId);
				$n = 0.0;
				$cur = (clone $start)->setTime(0, 0, 0);
				$last = (clone $end)->setTime(0, 0, 0);
				while ($cur <= $last) {
					$dow = (int)$cur->format('N');
					if ($dow <= 5) {
						$n += 1.0;
					}
					$cur->modify('+1 day');
				}
				return $n;
			}
		);

		return $h;
	}

	private function banssModel(int $id = 7): WorkingTimeModel
	{
		$model = new WorkingTimeModel();
		$model->setId($id);
		$model->setDailyHours(8.0);
		$model->setBreakRulesArray(
			WeekdaySchedule::mergeIntoBreakRules(null, WeekdaySchedule::banssPreset())
		);

		return $model;
	}

	private function assignment(int $modelId): UserWorkingTimeModel
	{
		$asn = new UserWorkingTimeModel();
		$asn->setUserId('alice');
		$asn->setWorkingTimeModelId($modelId);

		return $asn;
	}

	public function testBanssWeekUsesScheduleNetsNotFlatEight(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->with('alice')->willReturn($this->assignment(7));
		$modelMap->method('find')->with(7)->willReturn($this->banssModel(7));

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('weekday_schedule', $est['basis']);
		$this->assertSame(38.5, $est['hours']);
		$this->assertSame(8.5, $est['one_day_hours']);
		$this->assertIsArray($est['weekday_nets']);
		$this->assertSame(4.5, $est['weekday_nets']['fri']);
	}

	public function testFridayOneDayUsesShortNet(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(7));
		$modelMap->method('find')->willReturn($this->banssModel(7));

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-07'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame(4.5, $est['hours']);
		$this->assertSame(4.5, $est['one_day_hours']);
	}

	public function testMondayOneDayUsesLongNet(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(7));
		$modelMap->method('find')->willReturn($this->banssModel(7));

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-03')
		);
		$this->assertSame(8.5, $est['hours']);
		$this->assertSame(8.5, $est['one_day_hours']);
	}

	public function testNoModelFallsBackToOrgHoursPerDay(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn(null);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(7.5)
		);

		$est = $svc->estimateForUserRange(
			'bob',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('org_hours_per_day', $est['basis']);
		$this->assertSame(37.5, $est['hours']);
	}

	public function testModelDailyWithoutSchedule(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(3));

		$model = new WorkingTimeModel();
		$model->setId(3);
		$model->setDailyHours(6.0);
		$model->setBreakRulesArray(null);
		$modelMap->method('find')->willReturn($model);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'carol',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('model_daily', $est['basis']);
		$this->assertSame(30.0, $est['hours']);
	}

	public function testHolidayZerosScheduleDay(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(7));
		$modelMap->method('find')->willReturn($this->banssModel(7));

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('getHolidayWeightForUser')->willReturnCallback(
			static function (string $userId, \DateTime $d): float {
				unset($userId);
				return $d->format('Y-m-d') === '2026-08-05' ? 1.0 : 0.0;
			}
		);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$holiday,
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame(30.0, $est['hours']);
	}

	public function testHalfDayFridayHolidayDebitsQuarterOfLongWeekNet(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(7));
		$modelMap->method('find')->willReturn($this->banssModel(7));

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('getHolidayWeightForUser')->willReturnCallback(
			static function (string $userId, \DateTime $d): float {
				unset($userId);
				// Half-day Firmenfeiertag on short Friday → 4.5 × 0.5 = 2.25
				return $d->format('Y-m-d') === '2026-08-07' ? 0.5 : 0.0;
			}
		);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$holiday,
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-07'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('weekday_schedule', $est['basis']);
		$this->assertSame(2.25, $est['hours']);
	}

	public function testFullFridayHolidayDebitsZero(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn($this->assignment(7));
		$modelMap->method('find')->willReturn($this->banssModel(7));

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('getHolidayWeightForUser')->willReturnCallback(
			static function (string $userId, \DateTime $d): float {
				unset($userId);
				return $d->format('Y-m-d') === '2026-08-07' ? 1.0 : 0.0;
			}
		);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$holiday,
			$this->unitService(8.0)
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-07'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame(0.0, $est['hours']);
	}

	public function testWeekendOnlyRangeReturnsZeroHoursNotInventedDay(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn(null);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(0.0);

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$holiday,
			$this->unitService(8.0)
		);

		// Saturday–Sunday: must be 0 h, never fabricate 8 h via safeDays=1.
		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-08'),
			new \DateTime('2026-08-09')
		);
		$this->assertSame('org_hours_per_day', $est['basis']);
		$this->assertSame(0.0, $est['hours']);
	}

	public function testRotationEstimateUsedWhenRotationEnabled(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$rotation = $this->createMock(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class);
		$rotation->method('isEnabledForOrg')->willReturn(true);
		// Mon..Fri, 7h each
		$rotation->method('dayNetHoursForUser')->willReturn(7.0);
		$rotation->method('getWeekTargetBasis')->willReturn('published_roster');

		$svc = new VacationHoursDebitService(
			$userMap,
			$modelMap,
			$this->holidayNone(),
			$this->unitService(8.0),
			$rotation,
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('published_roster', $est['basis']);
		$this->assertSame(35.0, $est['hours']);
		$this->assertSame(7.0, $est['average_daily']);
		$this->assertNull($est['weekday_nets']);
		$this->assertSame(7.0, $est['one_day_hours']);
	}

	public function testRotationEstimateAbortsOnUnresolvableDay(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		// no model assignment -> falls through to workingDays path
		$userMap->method('findCurrentByUser')->willReturn(null);
		$rotation = $this->createMock(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class);
		$rotation->method('isEnabledForOrg')->willReturn(true);
		$rotation->method('dayNetHoursForUser')->willReturnCallback(
			static fn (string $u, \DateTime $d) => $d->format('N') === '3' ? null : 8.0
		);
		$rotation->method('getWeekTargetBasis')->willReturn('rotation_pattern');

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(3.0);

		$svc = new VacationHoursDebitService(
			$userMap, $modelMap, $holiday, $this->unitService(8.0), $rotation,
		);

		// Wed day returns null -> rotation aborts -> model/org fallback
		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('org_hours_per_day', $est['basis']);
		$this->assertSame(24.0, $est['hours']); // 3 working days * 8h org
	}

	public function testRotationEstimateSwallowsProviderFailure(): void
	{
		$userMap = $this->createMock(UserWorkingTimeModelMapper::class);
		$modelMap = $this->createMock(WorkingTimeModelMapper::class);
		$userMap->method('findCurrentByUser')->willReturn(null);
		$rotation = $this->createMock(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class);
		$rotation->method('isEnabledForOrg')->willReturn(true);
		$rotation->method('dayNetHoursForUser')->willThrowException(new \RuntimeException('rotation API down'));

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(5.0);

		$svc = new VacationHoursDebitService(
			$userMap, $modelMap, $holiday, $this->unitService(8.0), $rotation,
		);

		$est = $svc->estimateForUserRange(
			'alice',
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07')
		);
		$this->assertSame('org_hours_per_day', $est['basis']);
		$this->assertSame(40.0, $est['hours']);
	}
}