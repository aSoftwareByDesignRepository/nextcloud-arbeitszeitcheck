<?php

declare(strict_types=1);

/**
 * Balance-level invariant: an approved Mon–Fri absence on a 40 h contract
 * must produce exactly the net effect the type matrix promises — through the
 * real OvertimeService + real PaidAbsencePlannedHoursCreditService, no
 * service-level mocks between them. This is the executable "never again" for
 * the vacation-Minusstunden class: any future type, flag, or formula change
 * that lets an excused day create (or erase) balance fails here.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AbsenceSollInvariantTest extends TestCase
{
	private const WEEK_START = '2026-09-07'; // Monday
	private const WEEK_END = '2026-09-11';   // Friday

	private function overtimeService(string $type, string $sickFlag, string $leaveFlag): OvertimeService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'paid_absence_planned_hours_credit' => $sickFlag,
				'leave_soll_effects' => $leaveFlag,
				default => $default,
			}
		);

		$absence = new Absence();
		$absence->setUserId('alice');
		$absence->setType($type);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime(self::WEEK_START));
		$absence->setEndDate(new \DateTime(self::WEEK_END));
		$absence->setDays(5.0);

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findByUserAndDateRange')->willReturn([$absence]);

		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$userModelMapper->method('findByUserAndDate')->willReturn(null);
		$userModelMapper->method('findCurrentByUser')->willReturn(null);
		$modelMapper = $this->createMock(WorkingTimeModelMapper::class);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('getHolidayWeightForUser')->willReturn(0.0);
		$holiday->method('computeWorkingDaysForUser')->willReturn(5.0);

		$rotation = $this->createMock(DutyRotationSollProvider::class);
		$rotation->method('isEnabledForOrg')->willReturn(false);

		$creditService = new PaidAbsencePlannedHoursCreditService(
			$config,
			$absenceMapper,
			$userModelMapper,
			$modelMapper,
			$holiday,
			$rotation,
		);

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$settings = $this->createMock(UserOvertimeSettingsService::class);
		$settings->method('getTrackingFrom')->willReturn(null);
		$settings->method('getOpeningBalanceHours')->willReturn(0.0);
		$settings->method('resolveEffectiveYearStart')->willReturnCallback(
			static fn (string $u, int $y): \DateTime => new \DateTime("$y-01-01")
		);

		return new OvertimeService(
			$timeEntryMapper,
			$modelMapper,
			$userModelMapper,
			$this->createMock(IL10N::class),
			$holiday,
			$settings,
			null,
			$creditService,
		);
	}

	/**
	 * @return array<string, array{0: string, 1: float, 2: float, 3: float}>
	 *         type => [expected overtime_hours, expected credit, expected reduction]
	 *         on a 40 h Mon–Fri absence week with both gates on.
	 */
	public static function matrixInvariantProvider(): array
	{
		return [
			'vacation nets zero' => [Absence::TYPE_VACATION, 0.0, 40.0, 0.0],
			'personal leave nets zero' => [Absence::TYPE_PERSONAL_LEAVE, 0.0, 40.0, 0.0],
			'special leave nets zero' => [Absence::TYPE_SPECIAL_LEAVE, 0.0, 40.0, 0.0],
			'sick leave nets zero' => [Absence::TYPE_SICK_LEAVE, 0.0, 40.0, 0.0],
			'child sick nets zero' => [Absence::TYPE_CHILD_SICK, 0.0, 40.0, 0.0],
			'unpaid leave removes Soll' => [Absence::TYPE_UNPAID_LEAVE, 0.0, 0.0, 40.0],
			'parental leave removes Soll' => [Absence::TYPE_PARENTAL_LEAVE, 0.0, 0.0, 40.0],
			// Freizeitausgleich: the −40 h Soll gap IS the intended debit.
			'comp day debits the balance' => [Absence::TYPE_OVERTIME_COMP, -40.0, 0.0, 0.0],
			// Worked-away types: expected to be filled by stamping.
			'home office stays full Soll' => [Absence::TYPE_HOME_OFFICE, -40.0, 0.0, 0.0],
			'business trip stays full Soll' => [Absence::TYPE_BUSINESS_TRIP, -40.0, 0.0, 0.0],
		];
	}

	/**
	 * @dataProvider matrixInvariantProvider
	 */
	public function testAbsenceDeliversItsMatrixEffectThroughTheBalance(
		string $type,
		float $expectedOvertime,
		float $expectedCredit,
		float $expectedReduction,
	): void {
		$service = $this->overtimeService($type, '1', '1');
		$result = $service->calculateOvertime(
			'alice',
			new \DateTime(self::WEEK_START),
			new \DateTime(self::WEEK_END),
		);

		$this->assertEqualsWithDelta(
			$expectedOvertime,
			$result['overtime_hours'],
			0.01,
			"$type produced wrong balance delta",
		);
		$this->assertEqualsWithDelta($expectedCredit, $result['absence_credit_hours'], 0.01, "$type credit");
		$this->assertEqualsWithDelta($expectedReduction, $result['soll_reduction_hours'], 0.01, "$type reduction");
	}

	/**
	 * The sick opt-in stays an explicit org decision: with only the leave flag
	 * on, sick leave still shows the raw −40 h while vacation is covered.
	 */
	public function testSickGateOffLeavesVacationWorking(): void
	{
		$sick = $this->overtimeService(Absence::TYPE_SICK_LEAVE, '0', '1')
			->calculateOvertime('alice', new \DateTime(self::WEEK_START), new \DateTime(self::WEEK_END));
		$this->assertEqualsWithDelta(-40.0, $sick['overtime_hours'], 0.01);

		$vacation = $this->overtimeService(Absence::TYPE_VACATION, '0', '1')
			->calculateOvertime('alice', new \DateTime(self::WEEK_START), new \DateTime(self::WEEK_END));
		$this->assertEqualsWithDelta(0.0, $vacation['overtime_hours'], 0.01);
	}
}
