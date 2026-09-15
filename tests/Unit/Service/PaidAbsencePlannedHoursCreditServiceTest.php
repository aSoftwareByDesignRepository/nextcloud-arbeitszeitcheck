<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PaidAbsencePlannedHoursCreditServiceTest extends TestCase
{
	/** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var AbsenceMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;
	/** @var UserWorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userModelMapper;
	/** @var WorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $modelMapper;
	/** @var HolidayService&\PHPUnit\Framework\MockObject\MockObject */
	private $holidayService;
	/** @var DutyRotationSollProvider&\PHPUnit\Framework\MockObject\MockObject */
	private $rotation;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->modelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);
		$this->holidayService->method('getHolidayWeightForUser')->willReturn(0.0);
		$this->rotation = $this->createMock(DutyRotationSollProvider::class);
	}

	private function service(): PaidAbsencePlannedHoursCreditService
	{
		return new PaidAbsencePlannedHoursCreditService(
			$this->config,
			$this->absenceMapper,
			$this->userModelMapper,
			$this->modelMapper,
			$this->holidayService,
			$this->rotation,
		);
	}

	public function testDisabledReturnsZeroWithoutLookingUpAbsences(): void
	{
		$this->config->method('getAppValue')
			->with('arbeitszeitcheck', Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT, '0')
			->willReturn('0');
		$this->absenceMapper->expects($this->never())->method('findByUserAndDateRange');

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-12'),
			new \DateTime('2026-09-14'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testSickOnPlannedSaturdayCreditsRotationHours(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturnCallback(
			static function (string $userId, \DateTimeInterface $date): ?float {
				return $date->format('Y-m-d') === '2026-09-12' ? 10.0 : 0.0;
			}
		);

		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-12'));
		$absence->setEndDate(new \DateTime('2026-09-12'));
		$absence->setDays(1.0);

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-12'),
			new \DateTime('2026-09-13'),
		);

		$this->assertEqualsWithDelta(10.0, $result['hours'], 0.001);
		$this->assertSame(1, $result['days_credited']);
	}

	public function testFreeWeekendSickDoesNotInventHours(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(false);
		$this->userModelMapper->method('findCurrentByUser')->willReturn(null);

		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-12')); // Saturday
		$absence->setEndDate(new \DateTime('2026-09-13')); // Sunday
		$absence->setDays(0.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-12'),
			new \DateTime('2026-09-13'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testWeekdaySickCreditsScheduleNetAndNeutralizesSaldoShape(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(false);

		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$userModel = new UserWorkingTimeModel();
		$userModel->setWorkingTimeModelId(7);
		$this->userModelMapper->method('findCurrentByUser')->willReturn($userModel);

		$model = new WorkingTimeModel();
		$model->setId(7);
		$model->setName('BANSS');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(38.5);
		$model->setDailyHours(8.5);
		$model->setBreakRulesArray(WeekdaySchedule::mergeIntoBreakRules(null, WeekdaySchedule::banssPreset()));
		$this->modelMapper->method('find')->with(7)->willReturn($model);

		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09')); // Wednesday
		$absence->setEndDate(new \DateTime('2026-09-09'));
		$absence->setDays(1.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(8.5, $result['hours'], 0.001);
	}

	public function testDoubleCountGuardSubtractsWorkedHours(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09'));
		$absence->setEndDate(new \DateTime('2026-09-09'));
		$absence->setDays(1.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
			['2026-09-09' => 3.0],
		);

		$this->assertEqualsWithDelta(5.0, $result['hours'], 0.001);
	}

	public function testUnpaidAndPendingNeverCredit(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$unpaid = new Absence();
		$unpaid->setType(Absence::TYPE_UNPAID_LEAVE);
		$unpaid->setStatus(Absence::STATUS_APPROVED);
		$unpaid->setStartDate(new \DateTime('2026-09-09'));
		$unpaid->setEndDate(new \DateTime('2026-09-09'));

		$pending = new Absence();
		$pending->setType(Absence::TYPE_SICK_LEAVE);
		$pending->setStatus(Absence::STATUS_PENDING);
		$pending->setStartDate(new \DateTime('2026-09-10'));
		$pending->setEndDate(new \DateTime('2026-09-10'));

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$unpaid, $pending]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-10'),
		);

		$this->assertSame(0.0, $result['hours']);
	}

	public function testHalfDayCreditsHalfPlanned(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09'));
		$absence->setEndDate(new \DateTime('2026-09-09'));
		$absence->setDays(0.5);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(4.0, $result['hours'], 0.001);
	}
}
