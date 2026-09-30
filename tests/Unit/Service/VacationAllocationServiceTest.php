<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Support\VacationYearWindow;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
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
		?VacationEntitlementEngine $engine = null,
		?EntitlementSnapshotService $snapshotService = null,
		?VacationProrationService $prorationService = null,
		?VacationYearWindowResolver $yearWindowResolver = null,
	): VacationAllocationService {
		if ($engine === null) {
			$engine = $this->createMock(VacationEntitlementEngine::class);
		}
		if ($engine instanceof MockObject) {
			$engine->method('computeForDate')->willReturn([
				'days' => 25.0,
				'source' => 'manual',
				'ruleSetId' => null,
				'trace' => [],
			]);
		}
		$snapshotService = $snapshotService ?? $this->createMock(EntitlementSnapshotService::class);
		if ($prorationService === null) {
			$prorationService = $this->createMock(VacationProrationService::class);
		}
		if ($prorationService instanceof MockObject) {
			$prorationService->method('getConfiguredMethod')
				->willReturn(Constants::VACATION_PRORATION_METHOD_TWELFTHS);
			// Default: passthrough (no employment dates → full entitlement).
			$prorationService->method('prorateForYear')
				->willReturnCallback(static function (string $uid, int $year, float $full): array {
					return [
						'days' => $full,
						'full_days' => $full,
						'prorated' => false,
						'method' => Constants::VACATION_PRORATION_METHOD_TWELFTHS,
						'months_covered' => 12,
						'covered_days' => 365,
						'days_in_year' => 365,
						'covered_from' => sprintf('%04d-01-01', $year),
						'covered_to' => sprintf('%04d-12-31', $year),
						'employment_start' => null,
						'employment_end' => null,
						'employed_in_year' => true,
						'algorithm_version' => Constants::VACATION_PRORATION_ALGORITHM_VERSION,
					];
				});
		}
		if ($yearWindowResolver === null) {
			$modeConfig = $this->createMock(IConfig::class);
			$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_CALENDAR);
			$employment = $this->createMock(UserEmploymentSettingsService::class);
			$employment->method('getEmploymentStart')->willReturn(null);
			$yearWindowResolver = new VacationYearWindowResolver($modeConfig, $employment);
		}
		return new VacationAllocationService(
			$config,
			$absenceMapper,
			$userWtmMapper,
			$userSettingsMapper,
			$balanceMapper,
			$holiday,
			$engine,
			$snapshotService,
			$prorationService,
			$yearWindowResolver,
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

		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 5.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, $engine);
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

		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 2.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, $engine);
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

	public function testReadOnlyAllocationSkipsEntitlementSnapshotStore(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);
		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$holiday = $this->createMock(HolidayService::class);
		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 25.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);
		$snapshot = $this->createMock(EntitlementSnapshotService::class);
		$snapshot->expects($this->never())->method('store');
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, $engine, $snapshot);
		$s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-02-15'), null, false);
	}

	public function testDefaultAllocationStillPersistsEntitlementSnapshotStore(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);
		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$holiday = $this->createMock(HolidayService::class);
		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 25.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);
		$snapshot = $this->createMock(EntitlementSnapshotService::class);
		$snapshot->expects($this->once())->method('store');
		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, $engine, $snapshot);
		$s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-02-15'));
	}

	/**
	 * Regression for issue #23: a mid-year joiner must have the full annual
	 * entitlement reduced to the prorated amount in the allocation result.
	 */
	public function testProrationReducesAnnualEntitlementInAllocation(): void
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
		$settings->method('getIntegerSetting')->willReturn(30);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$holiday = $this->createMock(HolidayService::class);

		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 30.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);

		$proration = $this->createMock(VacationProrationService::class);
		$proration->method('getConfiguredMethod')->willReturn(Constants::VACATION_PRORATION_METHOD_TWELFTHS);
		$proration->method('prorateForYear')->willReturn([
			'days' => 20.0,
			'full_days' => 30.0,
			'prorated' => true,
			'method' => Constants::VACATION_PRORATION_METHOD_TWELFTHS,
			'months_covered' => 8,
			'covered_days' => 245,
			'days_in_year' => 365,
			'covered_from' => '2026-05-01',
			'covered_to' => '2026-12-31',
			'employment_start' => '2026-05-01',
			'employment_end' => null,
			'employed_in_year' => true,
			'algorithm_version' => Constants::VACATION_PRORATION_ALGORITHM_VERSION,
		]);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, $engine, null, $proration);
		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-06-01'));

		$this->assertEqualsWithDelta(30.0, $r['entitlement_full_year'], 0.001);
		$this->assertEqualsWithDelta(20.0, $r['total_remaining_for_new_requests'], 0.001);
		$this->assertEqualsWithDelta(20.0, $r['annual_remaining_after_approved'], 0.001);
		$this->assertTrue($r['proration']['prorated']);
		$this->assertEqualsWithDelta(20.0, $r['proration']['days'], 0.001);
		$this->assertSame(8, $r['proration']['months_covered']);
	}

	public function testAnniversaryModeSkipsCalendarProration(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, '', ''],
		]);

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingRange')->willReturn([]);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);

		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$holiday = $this->createMock(HolidayService::class);

		$proration = $this->createMock(VacationProrationService::class);
		$proration->expects($this->never())->method('prorateForYear');

		$modeConfig = $this->createMock(IConfig::class);
		$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_ANNIVERSARY);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturn(new \DateTimeImmutable('2026-07-01'));
		$resolver = new VacationYearWindowResolver($modeConfig, $employment);

		$s = $this->makeService(
			$config,
			$absenceMapper,
			$userWtm,
			$settings,
			$balance,
			$holiday,
			null,
			null,
			$proration,
			$resolver
		);
		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-08-04'), null, false);

		$this->assertSame(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $r['vacation_year_mode']);
		$this->assertSame('2026-07-01 – 2027-06-30', $r['vacation_year_label']);
		$this->assertEqualsWithDelta(25.0, $r['entitlement'], 0.001);
		$this->assertSame('anniversary_full', $r['proration']['method']);
		$this->assertFalse($r['proration']['prorated']);
	}

	public function testAnniversaryMissingStartYieldsZeroEntitlement(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, '', ''],
		]);

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$userWtm = $this->createMock(UserWorkingTimeModelMapper::class);
		$settings = $this->createMock(UserSettingsMapper::class);
		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$holiday = $this->createMock(HolidayService::class);

		$modeConfig = $this->createMock(IConfig::class);
		$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_ANNIVERSARY);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturn(null);
		$resolver = new VacationYearWindowResolver($modeConfig, $employment);

		$s = $this->makeService($config, $absenceMapper, $userWtm, $settings, $balance, $holiday, null, null, null, $resolver);
		$r = $s->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-08-04'), null, false);

		$this->assertSame(Constants::VAC_YEAR_MISSING_START, $r['vacation_year_error']);
		$this->assertEqualsWithDelta(0.0, $r['entitlement'], 0.001);
		$this->assertFalse($r['allocation_valid']);
	}

	public function testIsCarryoverUsableForNewRequestsCalendarBoundaries(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
		);

		// expiry for 2025 = 2025-03-31
		$this->assertTrue($s->isCarryoverUsableForNewRequests(2025, new \DateTime('2025-03-31')));
		$this->assertFalse($s->isCarryoverUsableForNewRequests(2025, new \DateTime('2025-04-01')));
	}

	public function testIsCarryoverUsableForNewRequestsAnniversaryUsesWindowExpiry(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3', '3'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31', '31'],
		]);
		$modeConfig = $this->createMock(IConfig::class);
		$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_ANNIVERSARY);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		// hired 2020-06-15 → balance year containing 2025-06-15..2026-06-14
		$employment->method('getEmploymentStart')->willReturn(new \DateTimeImmutable('2020-06-15'));
		$resolver = new VacationYearWindowResolver($modeConfig, $employment);

		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
			null, null, null, $resolver,
		);

		$this->assertTrue($s->isAnniversaryMode());
		// window expiry = anniversary start + 3 months (Mar 31 config ignored in
		// anniversary mode -> window-based expiry). Exact date aside, a date far
		// in the future is not usable and a date inside the grace period is.
		$window = $s->resolveWindowForUserYear('u1', 2025, new \DateTime('2026-01-15'));
		$this->assertFalse($window->missingEmploymentStart);
		$expiry = $s->getCarryoverExpiryDateForWindow($window);
		$this->assertTrue($s->isCarryoverUsableForNewRequests(2025, $expiry, 'u1'));
		$this->assertFalse($s->isCarryoverUsableForNewRequests(
			2025, $expiry->modify('+1 day'), 'u1'
		));
	}

	public function testGetAnnualEntitlementDaysFallsBackWhenEngineThrows(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('3');
		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willThrowException(new \RuntimeException('db gone'));
		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
			$engine,
		);
		$this->assertSame(
			round((float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR, 2),
			$s->getAnnualEntitlementDays('u1')
		);
	}

	public function testGetAnnualEntitlementDaysClampsOutOfRangeEngineResult(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('3');
		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn(['days' => 999.0]);
		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
			$engine,
		);
		$this->assertSame(366.0, $s->getAnnualEntitlementDays('u1'));
	}

	public function testResolveWindowForUserYearCalendarModeReturnsCalendarYear(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('3');
		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
		);
		$w = $s->resolveWindowForUserYear('u1', 2025);
		$this->assertSame(VacationYearWindow::MODE_CALENDAR, $w->mode);
		$this->assertSame(2025, $w->balanceYearKey);
		$this->assertSame('2025-01-01', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2026-01-01', $w->endExclusive->format('Y-m-d'));
	}

	public function testResolveWindowForUserYearAnniversaryMatchesAndMismatches(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('3');
		$modeConfig = $this->createMock(IConfig::class);
		$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_ANNIVERSARY);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturn(new \DateTimeImmutable('2020-06-15'));
		$resolver = new VacationYearWindowResolver($modeConfig, $employment);
		$s = $this->makeService(
			$config,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(HolidayService::class),
			null, null, null, $resolver,
		);

		// asOf inside the balance-year window -> same window object arm
		$asOf = new \DateTime('2025-08-01'); // inside 2025-06-15..2026-06-14 window
		$w = $s->resolveWindowForUserYear('u1', $resolver->resolveForUser('u1', $asOf)->balanceYearKey, $asOf);
		$this->assertSame(VacationYearWindow::MODE_ANNIVERSARY, $w->mode);
		$this->assertSame('2025-06-15', $w->startInclusive->format('Y-m-d'));

		// different balance year -> resolveAnniversaryForUserBalanceYear arm
		$w2 = $s->resolveWindowForUserYear('u1', 2023, $asOf);
		$this->assertSame(VacationYearWindow::MODE_ANNIVERSARY, $w2->mode);
		$this->assertSame(2023, $w2->balanceYearKey);
	}
}
