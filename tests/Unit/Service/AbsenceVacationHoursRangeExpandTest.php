<?php

declare(strict_types=1);

/**
 * Bachus: vacation hours resolve policy (authoritative totals, holiday clamp, fail-closed).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AbsenceVacationHoursRangeExpandTest extends TestCase
{
	private function invokeResolve(AbsenceService $svc, array $data, float $workingDays): float
	{
		$ref = new ReflectionClass($svc);
		/** @var ReflectionMethod $m */
		$m = $ref->getMethod('resolveVacationDurationHours');
		return (float)$m->invoke($svc, $data, $workingDays);
	}

	private function serviceWithHoursMode(float $hoursPerDay = 8.0): AbsenceService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($hoursPerDay): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return (string)$hoursPerDay;
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);

		$ref = new ReflectionClass(AbsenceService::class);
		/** @var AbsenceService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		$prop = $ref->getProperty('vacationUnitService');
		$prop->setValue($svc, $unit);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$l10nProp = $ref->getProperty('l10n');
		$l10nProp->setValue($svc, $l10n);
		$debitProp = $ref->getProperty('vacationHoursDebitService');
		$debitProp->setValue($svc, null);
		return $svc;
	}

	public function testAuthoritativeOneDayTotalIsNotExpanded(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, [
			'duration_hours' => '8',
			'require_duration_hours' => true,
		], 5.0);
		$this->assertSame(8.0, $hours);
	}

	public function testLegacyOneDayPostWithoutRequireExpandsToRange(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, ['duration_hours' => '8'], 5.0);
		$this->assertSame(40.0, $hours);
	}

	public function testHolidayClampCapsWeekdayEstimate(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		// Client Mon–Fri estimate 40h; only 4 working days after holiday.
		$hours = $this->invokeResolve($svc, [
			'duration_hours' => '40',
			'require_duration_hours' => true,
		], 4.0);
		$this->assertSame(32.0, $hours);
	}

	/**
	 * BANSS-style nets ignore holidays on the client → post 30 h; server max is 21.5.
	 * Hard ceiling must clamp even when the total does not “look like” N×avg.
	 */
	public function testScheduleNetsHolidayOvershootAlwaysClamped(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$debit = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class);
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 21.5,
			'basis' => 'weekday_schedule',
			'average_daily' => 7.7,
			'one_day_hours' => 8.5,
			'weekday_nets' => [
				'mon' => 8.5,
				'tue' => 8.5,
				'wed' => 8.5,
				'thu' => 8.5,
				'fri' => 4.5,
			],
		]);
		$ref = new ReflectionClass($svc);
		$prop = $ref->getProperty('vacationHoursDebitService');
		$prop->setValue($svc, $debit);

		$m = $ref->getMethod('resolveVacationDurationHours');
		$start = new \DateTimeImmutable('2026-08-04'); // Tue
		$end = new \DateTimeImmutable('2026-08-07'); // Fri
		// Client sum Tue–Fri nets without holiday weight.
		$hours = (float)$m->invoke(
			$svc,
			['duration_hours' => '30', 'require_duration_hours' => true],
			3.0,
			'alice',
			$start,
			$end
		);
		$this->assertSame(21.5, $hours);
	}

	public function testExplicitPartialTotalIsKept(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, [
			'duration_hours' => '4',
			'require_duration_hours' => true,
		], 5.0);
		$this->assertSame(4.0, $hours);
	}

	public function testSingleDayKeepsOneDayAmount(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, [
			'duration_hours' => '8',
			'require_duration_hours' => true,
		], 1.0);
		$this->assertSame(8.0, $hours);
	}

	public function testMissingHoursWithoutFillFlagFailsClosed(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessageMatches('/hours/i');
		try {
			$this->invokeResolve($svc, [], 5.0);
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::ABSENCE_HOURS_CLIENT_REQUIRED, $e->getReasonCode());
			throw $e;
		}
	}

	public function testServerMayFillHoursConvertsWorkingDays(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, ['server_may_fill_hours' => true], 5.0);
		$this->assertSame(40.0, $hours);
	}

	public function testZeroWorkingDaysClampsPostedHoursToZero(): void
	{
		$svc = $this->serviceWithHoursMode(8.0);
		$hours = $this->invokeResolve($svc, [
			'duration_hours' => '8',
			'require_duration_hours' => true,
		], 0.0);
		$this->assertSame(0.0, $hours);
	}
}
