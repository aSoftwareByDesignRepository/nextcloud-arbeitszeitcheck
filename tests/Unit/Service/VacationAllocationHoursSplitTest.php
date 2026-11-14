<?php

declare(strict_types=1);

/**
 * Carryover hour split must use schedule nets when duration_hours is set (BANSS).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class VacationAllocationHoursSplitTest extends TestCase
{
	public function testDurationHoursSplitUsesScheduleWeightsNotDayRatio(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);

		$debit = $this->createMock(VacationHoursDebitService::class);
		$debit->method('estimateForUserRange')->willReturnCallback(
			static function (string $userId, \DateTimeInterface $start, \DateTimeInterface $end): array {
				unset($userId);
				// Mo–We long (25.5) vs Thu–Fri short (13.0) — not 3/5 vs 2/5 day ratio.
				$s = $start->format('Y-m-d');
				$e = $end->format('Y-m-d');
				if ($s === '2026-08-03' && $e === '2026-08-05') {
					return ['hours' => 25.5, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
				}
				if ($s === '2026-08-06' && $e === '2026-08-07') {
					return ['hours' => 13.0, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
				}
				return ['hours' => 0.0, 'basis' => 'weekday_schedule', 'average_daily' => 7.7, 'one_day_hours' => 8.5, 'weekday_nets' => null];
			}
		);

		$ref = new ReflectionClass(VacationAllocationService::class);
		/** @var VacationAllocationService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		foreach ([
			'vacationUnitService' => $unit,
			'vacationHoursDebitService' => $debit,
			'holidayCalendarService' => $this->createMock(\OCA\ArbeitszeitCheck\Service\HolidayService::class),
		] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setValue($svc, $val);
		}
		$holiday = $ref->getProperty('holidayCalendarService')->getValue($svc);
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

		$absence = new Absence();
		$absence->setDurationHours(38.5);

		$m = $ref->getMethod('splitConsumptionForRangeSegment');
		$split = $m->invoke(
			$svc,
			'alice',
			$absence,
			new \DateTime('2026-08-03'),
			new \DateTime('2026-08-07'),
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31'),
			new \DateTimeImmutable('2026-08-05'),
			true
		);

		// 25.5/38.5 of 38.5 = 25.5; 13/38.5 of 38.5 = 13 — not day-ratio 23.1/15.4.
		$this->assertSame(25.5, $split['before']);
		$this->assertSame(13.0, $split['after']);
	}

	public function testZeroWorkingDaysDoesNotAssignFullHoursToBefore(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);

		$ref = new ReflectionClass(VacationAllocationService::class);
		/** @var VacationAllocationService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$p = $ref->getProperty('vacationUnitService');
		$p->setValue($svc, $unit);
		$debitP = $ref->getProperty('vacationHoursDebitService');
		$debitP->setValue($svc, null);
		$h = $ref->getProperty('holidayCalendarService');
		$holiday = $this->createMock(\OCA\ArbeitszeitCheck\Service\HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(0.0);
		$h->setValue($svc, $holiday);

		$absence = new Absence();
		$absence->setDurationHours(8.0);
		$m = $ref->getMethod('splitConsumptionForRangeSegment');
		$split = $m->invoke(
			$svc,
			'alice',
			$absence,
			new \DateTime('2026-08-08'),
			new \DateTime('2026-08-09'),
			new \DateTime('2026-01-01'),
			new \DateTime('2026-12-31'),
			new \DateTimeImmutable('2026-03-31'),
			true
		);
		$this->assertSame(0.0, $split['before']);
		$this->assertSame(0.0, $split['after']);
	}
}
