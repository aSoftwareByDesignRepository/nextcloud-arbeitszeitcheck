<?php

declare(strict_types=1);

/**
 * ADR-01 + SEC-01: days-mode allocation honors trusted stored days (half-day).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class VacationAllocationHalfDayDaysModeTest extends TestCase
{
	public function testIsTrustedStoredVacationDaysMatrix(): void
	{
		$single = new Absence();
		$single->setStartDate(new \DateTime('2026-08-12'));
		$single->setEndDate(new \DateTime('2026-08-12'));

		$this->assertTrue(VacationAllocationService::isTrustedStoredVacationDays($single, 0.5, 1.0));
		$this->assertTrue(VacationAllocationService::isTrustedStoredVacationDays($single, 1.0, 1.0));
		$this->assertFalse(VacationAllocationService::isTrustedStoredVacationDays($single, 0.01, 1.0));
		// calendar half-day weight with matching stored days is trusted (not under-debit);
		// write-path still rejects booking half vacation when w < 0.999.
		$this->assertTrue(VacationAllocationService::isTrustedStoredVacationDays($single, 0.5, 0.5));
		$this->assertFalse(VacationAllocationService::isTrustedStoredVacationDays($single, 0.5, 0.4));

		$multi = new Absence();
		$multi->setStartDate(new \DateTime('2026-08-12'));
		$multi->setEndDate(new \DateTime('2026-08-14'));
		$this->assertTrue(VacationAllocationService::isTrustedStoredVacationDays($multi, 3.0, 3.0));
		$this->assertFalse(VacationAllocationService::isTrustedStoredVacationDays($multi, 0.5, 3.0)); // AC-G14 forge
	}

	public function testSplitConsumptionTrustedHalfDayUsesStoredDays(): void
	{
		$svc = $this->makeSplitService();
		$absence = new Absence();
		$absence->setStartDate(new \DateTime('2026-08-12'));
		$absence->setEndDate(new \DateTime('2026-08-12'));
		$absence->setDays(0.5);

		$ref = new ReflectionClass(VacationAllocationService::class);
		$m = $ref->getMethod('splitConsumptionForRangeSegment');
		$split = $m->invoke(
			$svc,
			'u1',
			$absence,
			new \DateTime('2026-08-12'),
			new \DateTime('2026-08-12'),
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31'),
			new \DateTimeImmutable('2026-03-31'),
			false
		);

		$this->assertEqualsWithDelta(0.5, $split['before'] + $split['after'], 0.011);
	}

	public function testSplitConsumptionUntrustedMultiDayFallsBackToCalendar(): void
	{
		$svc = $this->makeSplitService();
		$absence = new Absence();
		$absence->setStartDate(new \DateTime('2026-08-10'));
		$absence->setEndDate(new \DateTime('2026-08-12'));
		$absence->setDays(0.5); // forged under-debit

		$ref = new ReflectionClass(VacationAllocationService::class);
		$m = $ref->getMethod('splitConsumptionForRangeSegment');
		$split = $m->invoke(
			$svc,
			'u1',
			$absence,
			new \DateTime('2026-08-10'),
			new \DateTime('2026-08-12'),
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31'),
			new \DateTimeImmutable('2026-03-31'),
			false
		);

		// Mon–Wed = 3 calendar working days (fallback)
		$this->assertEqualsWithDelta(3.0, $split['before'] + $split['after'], 0.011);
	}

	public function testProspectiveHalfDayDecreasesRemainingByHalf(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				if ($key === Constants::CONFIG_VACATION_YEAR_MODE) {
					return Constants::VACATION_YEAR_MODE_CALENDAR;
				}
				if ($key === 'vacation_carryover_expiry_month') {
					return '3';
				}
				if ($key === 'vacation_carryover_expiry_day') {
					return '31';
				}
				return $default;
			}
		);

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('findVacationApprovedOverlappingYear')->willReturn([]);
		$absenceMapper->method('findVacationApprovedOverlappingRange')->willReturn([]);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturnCallback(
			static function (string $userId, \DateTime $start, \DateTime $end): float {
				unset($userId);
				$n = 0.0;
				$cur = (clone $start)->setTime(0, 0, 0);
				$last = (clone $end)->setTime(0, 0, 0);
				while ($cur <= $last) {
					if ((int)$cur->format('N') <= 5) {
						$n += 1.0;
					}
					$cur->modify('+1 day');
				}
				return $n;
			}
		);

		$engine = $this->createMock(VacationEntitlementEngine::class);
		$engine->method('computeForDate')->willReturn([
			'days' => 25.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);

		$proration = $this->createMock(VacationProrationService::class);
		$proration->method('prorateForYear')->willReturnCallback(
			static function (string $uid, int $year, float $full): array {
				unset($uid, $year);
				return [
					'days' => $full,
					'full_days' => $full,
					'prorated' => false,
					'method' => Constants::VACATION_PRORATION_METHOD_TWELFTHS,
					'months_covered' => 12,
					'covered_days' => 365,
					'days_in_year' => 365,
					'covered_from' => '2026-01-01',
					'covered_to' => '2026-12-31',
					'employment_start' => null,
					'employment_end' => null,
					'employed_in_year' => true,
					'algorithm_version' => Constants::VACATION_PRORATION_ALGORITHM_VERSION,
				];
			}
		);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturn(0.0);
		$balance->method('getCarryoverAmount')->willReturn(0.0);

		$modeConfig = $this->createMock(IConfig::class);
		$modeConfig->method('getAppValue')->willReturn(Constants::VACATION_YEAR_MODE_CALENDAR);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturn(null);
		$yearResolver = new VacationYearWindowResolver($modeConfig, $employment);

		$unit = new VacationUnitService($config);

		$svc = new VacationAllocationService(
			$config,
			$absenceMapper,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$balance,
			$holiday,
			$engine,
			$this->createMock(EntitlementSnapshotService::class),
			$proration,
			$yearResolver,
			$unit,
			null,
		);

		$before = $svc->computeYearAllocation('u1', 2026, null, null, null, new \DateTime('2026-08-12'), null, false);
		$after = $svc->computeYearAllocation(
			'u1',
			2026,
			null,
			new \DateTime('2026-08-12'),
			new \DateTime('2026-08-12'),
			new \DateTime('2026-08-12'),
			null,
			false,
			null,
			0.5
		);

		$delta = (float)$before['total_remaining_for_new_requests'] - (float)$after['total_remaining_for_new_requests'];
		$this->assertEqualsWithDelta(0.5, $delta, 0.01, 'AC-G5b: prospective half must debit 0.5 not 1.0');
		$this->assertTrue($after['allocation_valid']);
	}

	private function makeSplitService(): VacationAllocationService
	{
		$ref = new ReflectionClass(VacationAllocationService::class);
		/** @var VacationAllocationService $svc */
		$svc = $ref->newInstanceWithoutConstructor();

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturnCallback(
			static function (string $userId, \DateTime $start, \DateTime $end): float {
				unset($userId);
				$n = 0.0;
				$cur = (clone $start)->setTime(0, 0, 0);
				$last = (clone $end)->setTime(0, 0, 0);
				while ($cur <= $last) {
					if ((int)$cur->format('N') <= 5) {
						$n += 1.0;
					}
					$cur->modify('+1 day');
				}
				return $n;
			}
		);

		foreach ([
			'vacationUnitService' => $unit,
			'vacationHoursDebitService' => null,
			'holidayCalendarService' => $holiday,
		] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setValue($svc, $val);
		}

		return $svc;
	}
}
