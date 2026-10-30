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

	/**
	 * Pin both gates explicitly: the sick opt-in and the leave-effects flag
	 * resolve through the same getAppValue call shape.
	 */
	private function flags(string $sickOptIn, string $leaveEffects): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($sickOptIn, $leaveEffects): string {
				return match ($key) {
					Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT => $sickOptIn,
					Constants::CONFIG_LEAVE_SOLL_EFFECTS => $leaveEffects,
					default => $default,
				};
			}
		);
	}

	public function testDisabledReturnsZeroWithoutLookingUpAbsences(): void
	{
		$this->flags('0', '0');
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

	public function testChildSickCreditsLikeSickLeave(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_CHILD_SICK);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09'));
		$absence->setEndDate(new \DateTime('2026-09-09'));
		$absence->setDays(1.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(8.0, $result['hours'], 0.001);
		$this->assertSame(1, $result['days_credited']);
	}

	public function testChildSickHalfDayCreditsHalfPlanned(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_CHILD_SICK);
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

	public function testUnapprovedChildSickNeverCredits(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		foreach ([Absence::STATUS_PENDING, Absence::STATUS_REJECTED, Absence::STATUS_CANCELLED] as $status) {
			$absence = new Absence();
			$absence->setType(Absence::TYPE_CHILD_SICK);
			$absence->setStatus($status);
			$absence->setStartDate(new \DateTime('2026-09-09'));
			$absence->setEndDate(new \DateTime('2026-09-09'));

			$mapper = $this->createMock(AbsenceMapper::class);
			$mapper->method('findByUserAndDateRange')->willReturn([$absence]);
			$service = new PaidAbsencePlannedHoursCreditService(
				$this->config,
				$mapper,
				$this->userModelMapper,
				$this->modelMapper,
				$this->holidayService,
				$this->rotation,
			);

			$result = $service->creditHoursForRange(
				'alice',
				new \DateTime('2026-09-09'),
				new \DateTime('2026-09-09'),
			);
			$this->assertSame(0.0, $result['hours'], "status $status must not credit");
		}
	}

	public function testOvertimeCompNeverCredits(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		// Approved Freizeitausgleich must NOT credit planned hours — the whole
		// point is that the day's Soll debits the overtime balance instead.
		$absence = new Absence();
		$absence->setType(Absence::TYPE_OVERTIME_COMP);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09'));
		$absence->setEndDate(new \DateTime('2026-09-09'));
		$absence->setDays(1.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testMidRangeModelChangeUsesEffectiveContractPerDay(): void
	{
		$this->config->method('getAppValue')->willReturn('1');
		$this->rotation->method('isEnabledForOrg')->willReturn(false);

		// Model A: 8h/day through Wed; model B: 4h/day from Thu. The assignment
		// in force *today* is B — a current-only resolver would mis-credit Wed.
		$assignA = new UserWorkingTimeModel();
		$assignA->setWorkingTimeModelId(11);
		$assignB = new UserWorkingTimeModel();
		$assignB->setWorkingTimeModelId(12);
		$this->userModelMapper->method('findByUserAndDate')->willReturnCallback(
			static function (string $uid, \DateTime $date) use ($assignA, $assignB) {
				return $date->format('Y-m-d') <= '2026-09-09' ? $assignA : $assignB;
			}
		);
		$this->userModelMapper->method('findCurrentByUser')->willReturn($assignB);

		$modelA = new WorkingTimeModel();
		$modelA->setId(11);
		$modelA->setName('Full');
		$modelA->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$modelA->setDailyHours(8.0);
		$modelB = new WorkingTimeModel();
		$modelB->setId(12);
		$modelB->setName('Part');
		$modelB->setType(WorkingTimeModel::TYPE_PART_TIME);
		$modelB->setDailyHours(4.0);
		$this->modelMapper->method('find')->willReturnCallback(
			static function (int $id) use ($modelA, $modelB) {
				return $id === 11 ? $modelA : $modelB;
			}
		);

		$absence = new Absence();
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('2026-09-09')); // Wed
		$absence->setEndDate(new \DateTime('2026-09-10'));   // Thu
		$absence->setDays(2.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-10'),
		);

		// 8h under model A + 4h under model B — not 2×4 (current-only bug).
		$this->assertEqualsWithDelta(12.0, $result['hours'], 0.001);
		$this->assertSame(2, $result['days_credited']);
	}

	// ---------------------------------------------------------------
	// Leave-effects matrix (default on): paid leave credits, suspensions
	// reduce Soll — the vacation-Minusstunden fix.
	// ---------------------------------------------------------------

	private function approvedAbsence(string $type, string $start, string $end, float $days): Absence
	{
		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType($type);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime($start));
		$absence->setEndDate(new \DateTime($end));
		$absence->setDays($days);

		return $absence;
	}

	public function testVacationCreditsPlannedHoursUnderLeaveEffectsByDefault(): void
	{
		// Sick opt-in OFF, leave effects ON — the customer case: approved
		// vacation must credit its planned hours without any admin opt-in.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-07', '2026-09-11', 5.0),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-07'),
			new \DateTime('2026-09-11'),
		);

		$this->assertEqualsWithDelta(40.0, $result['hours'], 0.001);
		$this->assertSame(5, $result['days_credited']);
	}

	public function testVacationNotCreditedWhenLeaveEffectsDisabled(): void
	{
		$this->flags('1', '0');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 1.0),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testSickLeaveStillRequiresOptInWhenLeaveEffectsOn(): void
	{
		// Leave effects ON alone must not silently enable Entgeltausfall —
		// that stays an explicit org decision.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_SICK_LEAVE, '2026-09-09', '2026-09-09', 1.0),
			$this->approvedAbsence(Absence::TYPE_CHILD_SICK, '2026-09-10', '2026-09-10', 1.0),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-10'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testPersonalAndSpecialLeaveCreditUnderLeaveEffects(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_PERSONAL_LEAVE, '2026-09-09', '2026-09-09', 1.0),
			$this->approvedAbsence(Absence::TYPE_SPECIAL_LEAVE, '2026-09-10', '2026-09-10', 1.0),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-10'),
		);

		$this->assertEqualsWithDelta(16.0, $result['hours'], 0.001);
		$this->assertSame(2, $result['days_credited']);
	}

	public function testOvertimeCompAndWorkedAwayTypesNeverCreditUnderLeaveEffects(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_OVERTIME_COMP, '2026-09-09', '2026-09-09', 1.0),
			$this->approvedAbsence(Absence::TYPE_HOME_OFFICE, '2026-09-10', '2026-09-10', 1.0),
			$this->approvedAbsence(Absence::TYPE_BUSINESS_TRIP, '2026-09-11', '2026-09-11', 1.0),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-11'),
		);

		$this->assertSame(0.0, $result['hours']);
		$this->assertSame(0, $result['days_credited']);
	}

	public function testPendingVacationNeverCredits(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = $this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 1.0);
		$absence->setStatus(Absence::STATUS_PENDING);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertSame(0.0, $result['hours']);
	}

	public function testHalfDayVacationWithWorkedOtherHalfNetsZero(): void
	{
		// Half-day vacation (0.5) + 4 h worked must top the day up to the full
		// 8 h Soll — not leave a −4 h hole. The credit is min(absent fraction,
		// planned − worked): here min(4, 4) = 4.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 0.5),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
			['2026-09-09' => 4.0],
		);

		$this->assertEqualsWithDelta(4.0, $result['hours'], 0.001);
		$this->assertSame(1, $result['days_credited']);
	}

	public function testHalfDayVacationWithNoWorkCreditsAbsentFraction(): void
	{
		// Half-day vacation, nothing worked: the employee still owes the other
		// half — credit is the absent fraction only (4 h), leaving −4 h to work.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 0.5),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(4.0, $result['hours'], 0.001);
	}

	public function testHoursModeVacationCreditsDurationNotFullDay(): void
	{
		// Hours-mode: days column is only calendar weight (1.0) while
		// duration_hours=4 is the authoritative absent amount — crediting the
		// full 8 h planned would invent 4 paid hours.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = $this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 1.0);
		$absence->setDurationHours(4.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(4.0, $result['hours'], 0.001);
	}

	public function testHoursModeVacationSpreadsPartialRatioOverRange(): void
	{
		// Two planned days à 8 h, duration_hours=8 → ratio 0.5 → 4 h absent per
		// day (same proportional spread the vacation debit uses), total 8 h.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = $this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-10', 2.0);
		$absence->setDurationHours(8.0);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-10'),
		);

		$this->assertEqualsWithDelta(8.0, $result['hours'], 0.001);
		$this->assertSame(2, $result['days_credited']);
	}

	public function testOvertimeOnHalfVacationDayIsNotClawedBack(): void
	{
		// Worked 6 h on a half-vacation day: credit tops up to planned only —
		// min(4, 2) = 2, so the day nets +2 h instead of being capped at 4.
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_VACATION, '2026-09-09', '2026-09-09', 0.5),
		]);

		$result = $this->service()->creditHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
			['2026-09-09' => 6.0],
		);

		$this->assertEqualsWithDelta(2.0, $result['hours'], 0.001);
	}

	// ---------------------------------------------------------------
	// Suspension Soll reduction (unpaid / parental leave).
	// ---------------------------------------------------------------

	public function testUnpaidLeaveReducesSollInsteadOfCrediting(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_UNPAID_LEAVE, '2026-09-09', '2026-09-11', 3.0),
		]);

		$start = new \DateTime('2026-09-09');
		$end = new \DateTime('2026-09-11');

		$credit = $this->service()->creditHoursForRange('alice', $start, $end);
		$this->assertSame(0.0, $credit['hours'], 'unpaid leave must never invent paid hours');

		$reduction = $this->service()->sollReductionHoursForRange('alice', $start, $end);
		$this->assertEqualsWithDelta(24.0, $reduction['hours'], 0.001);
		$this->assertSame(3, $reduction['days_reduced']);
	}

	public function testParentalLeaveReducesSoll(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_PARENTAL_LEAVE, '2026-09-09', '2026-09-09', 1.0),
		]);

		$reduction = $this->service()->sollReductionHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(8.0, $reduction['hours'], 0.001);
		$this->assertSame(1, $reduction['days_reduced']);
	}

	public function testPendingSuspensionDoesNotReduce(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$absence = $this->approvedAbsence(Absence::TYPE_UNPAID_LEAVE, '2026-09-09', '2026-09-09', 1.0);
		$absence->setStatus(Absence::STATUS_PENDING);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$reduction = $this->service()->sollReductionHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertSame(0.0, $reduction['hours']);
		$this->assertSame(0, $reduction['days_reduced']);
	}

	public function testReductionFreeWeekendInventsNothing(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(false);
		$this->userModelMapper->method('findCurrentByUser')->willReturn(null);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_UNPAID_LEAVE, '2026-09-12', '2026-09-13', 0.0),
		]);

		$reduction = $this->service()->sollReductionHoursForRange(
			'alice',
			new \DateTime('2026-09-12'), // Saturday
			new \DateTime('2026-09-13'), // Sunday
		);

		$this->assertSame(0.0, $reduction['hours']);
		$this->assertSame(0, $reduction['days_reduced']);
	}

	public function testHalfDayUnpaidLeaveReducesHalfPlanned(): void
	{
		$this->flags('0', '1');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_UNPAID_LEAVE, '2026-09-09', '2026-09-09', 0.5),
		]);

		$reduction = $this->service()->sollReductionHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertEqualsWithDelta(4.0, $reduction['hours'], 0.001);
	}

	public function testReductionDisabledWhenLeaveEffectsOff(): void
	{
		$this->flags('1', '0');
		$this->rotation->method('isEnabledForOrg')->willReturn(true);
		$this->rotation->method('dayNetHoursForUser')->willReturn(8.0);

		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([
			$this->approvedAbsence(Absence::TYPE_UNPAID_LEAVE, '2026-09-09', '2026-09-09', 1.0),
		]);

		$reduction = $this->service()->sollReductionHoursForRange(
			'alice',
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
		);

		$this->assertSame(0.0, $reduction['hours']);
		$this->assertSame(0, $reduction['days_reduced']);
	}
}
