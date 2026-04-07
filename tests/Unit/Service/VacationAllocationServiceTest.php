<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class VacationAllocationServiceTest extends TestCase
{
	private function makeService(
		IConfig $config,
		AbsenceMapper $absenceMapper,
		UserWorkingTimeModelMapper $userWtmMapper,
		UserSettingsMapper $userSettingsMapper,
		VacationYearBalanceMapper $balanceMapper,
		HolidayService $holiday,
	): VacationAllocationService {
		return new VacationAllocationService(
			$config,
			$absenceMapper,
			$userWtmMapper,
			$userSettingsMapper,
			$balanceMapper,
			$holiday
		);
	}

	public function testGetCarryoverExpiryDateNormalizesInvalidDayForMonth(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnMap([
				['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '2'],
				['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
			]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$holiday = $this->createMock(HolidayService::class);
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$d = $s->getCarryoverExpiryDateForYear(2026);
		$this->assertSame('2026-02-28', $d->format('Y-m-d'));
	}

	public function testSplitDelegatesToHolidayService(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$holiday = $this->createMock(HolidayService::class);
		$holiday->expects($this->exactly(2))
			->method('computeWorkingDaysForUser')
			->willReturnCallback(function (string $uid, \DateTime $start, \DateTime $end) {
				if ($start->format('Y-m-d') === '2026-02-01' && $end->format('Y-m-d') === '2026-03-31') {
					return 5.0;
				}
				if ($start->format('Y-m-d') === '2026-04-01' && $end->format('Y-m-d') === '2026-04-10') {
					return 2.0;
				}
				return 0.0;
			});
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$start = new \DateTime('2026-02-01');
		$end = new \DateTime('2026-04-10');
		$split = $s->splitWorkingDaysForYearBeforeAfterExpiry('u1', $start, $end, 2026);
		$this->assertEqualsWithDelta(5.0, $split['before'], 0.001);
		$this->assertEqualsWithDelta(2.0, $split['after'], 0.001);
	}

	public function testFifoConsumesCarryoverBeforeAnnualForBeforeExpiryPortion(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$a1 = new Absence();
		$a1->setId(1);
		$a1->setStartDate(new \DateTime('2026-02-01'));
		$a1->setEndDate(new \DateTime('2026-02-10'));

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([$a1]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(25);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(5.0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(5.0);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-02-15'));
		$this->assertTrue($r['allocation_valid']);
		$this->assertEqualsWithDelta(0.0, $r['carryover_remaining_after_approved'], 0.001);
		// 5 wd taken from carryover pool only; annual entitlement untouched
		$this->assertEqualsWithDelta(25.0, $r['annual_remaining_after_approved'], 0.001);
	}

	public function testProspectiveRequestFailsWhenInsufficient(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(5);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(10.0);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$r = $s->computeYearAllocation(
			'u1',
			2026,
			null,
			new \DateTime('2026-06-01'),
			new \DateTime('2026-06-20'),
			new \DateTime('2026-02-15')
		);
		$this->assertFalse($r['allocation_valid']);
		$this->assertGreaterThan(0.0, $r['shortfall']);
	}

	public function testCarryoverNotUsableAfterExpiryForNewRequests(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(25);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(3.0);

		$holiday = $this->createMock(HolidayService::class);
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);

		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-04-15'));
		$this->assertEqualsWithDelta(0.0, $r['carryover_usable_for_new_requests'], 0.001);
		$this->assertEqualsWithDelta(25.0, $r['total_remaining_for_new_requests'], 0.001);
	}

	public function testProspectiveAfterDeadlineWithoutGrandfatheringCannotUseCarryover(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(2);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(10.0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(4.0);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$r = $s->computeYearAllocation(
			'u1',
			2026,
			null,
			new \DateTime('2026-02-02'),
			new \DateTime('2026-02-06'),
			new \DateTime('2026-04-15'),
			null
		);
		$this->assertFalse($r['allocation_valid']);
		$this->assertGreaterThan(0.0, $r['shortfall']);
	}

	public function testProspectiveAfterDeadlineWithGrandfatheringMayUseCarryover(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(0);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(10.0);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(4.0);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$r = $s->computeYearAllocation(
			'u1',
			2026,
			null,
			new \DateTime('2026-02-02'),
			new \DateTime('2026-02-06'),
			new \DateTime('2026-04-15'),
			new \DateTime('2026-02-01')
		);
		$this->assertTrue($r['allocation_valid']);
		$this->assertEqualsWithDelta(6.0, $r['carryover_remaining_after_approved'], 0.001);
	}

	public function testMaxCarryoverCapClampsOpeningBalance(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, '', '5'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtm->method('findCurrentByUser')->willReturn(null);
		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->method('getIntegerSetting')->willReturn(25);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(20.0);

		$holiday = $this->createMock(HolidayService::class);
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday);
		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-02-15'));
		$this->assertEqualsWithDelta(5.0, $r['carryover_opening'], 0.001);
	}
}
