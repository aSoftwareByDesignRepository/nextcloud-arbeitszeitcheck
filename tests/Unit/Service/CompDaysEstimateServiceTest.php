<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\CompDaysEstimateService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use PHPUnit\Framework\TestCase;

class CompDaysEstimateServiceTest extends TestCase
{
	private function makeService(float $dailyHours, string $basis, float $balance): CompDaysEstimateService
	{
		$overtimeService = $this->createMock(OvertimeService::class);
		$overtimeService->method('getAverageDailyPlannedHours')->willReturn([
			'daily_hours' => $dailyHours,
			'basis' => $basis,
		]);
		$displayService = $this->createMock(OvertimeDisplayService::class);
		$displayService->method('getYearToDateBalanceForTrafficLight')->willReturn($balance);

		return new CompDaysEstimateService($overtimeService, $displayService);
	}

	public function testFloorsBalanceByScheduleAwareDailyHours(): void
	{
		// 20 h balance ÷ 8 h/day → 2 full days (not 2.5 rounded up).
		$estimate = $this->makeService(8.0, 'weekday_schedule', 20.0)->estimate('u1');
		$this->assertNotNull($estimate);
		$this->assertSame(2, $estimate['days']);
		$this->assertSame(8.0, $estimate['daily_hours']);
		$this->assertSame(20.0, $estimate['balance_hours']);
		$this->assertSame('weekday_schedule', $estimate['basis']);
	}

	public function testPartialDayBalanceYieldsZeroDaysNotNull(): void
	{
		// 4 h on an 8 h day → 0 takeable days, but the estimate is valid.
		$estimate = $this->makeService(8.0, 'weekly_contract', 4.0)->estimate('u1');
		$this->assertNotNull($estimate);
		$this->assertSame(0, $estimate['days']);
	}

	public function testNegativeBalanceYieldsZeroDays(): void
	{
		$estimate = $this->makeService(8.0, 'weekly_contract', -12.0)->estimate('u1');
		$this->assertNotNull($estimate);
		$this->assertSame(0, $estimate['days']);
	}

	public function testOnDemandBasisReturnsNull(): void
	{
		// Arbeit auf Abruf: no contractual Soll → no day estimate, hide affordance.
		$this->assertNull($this->makeService(0.0, 'on_demand', 40.0)->estimate('u1'));
	}

	public function testZeroDailyHoursReturnsNull(): void
	{
		$this->assertNull($this->makeService(0.0, 'degraded', 40.0)->estimate('u1'));
	}

	public function testImpossibleDailyHoursFailsClosedToNull(): void
	{
		// A resolved divisor above 24 h/day is corrupt contract data, never a
		// real schedule — dividing a true balance by it printed "≈ 1 day" for
		// a +133 h user (daily_hours column held 78). Fail closed: hide the
		// affordance instead of lying.
		$this->assertNull($this->makeService(78.0, 'weekly_contract', 133.0)->estimate('u1'));
		$this->assertNull($this->makeService(24.01, 'weekday_schedule', 133.0)->estimate('u1'));
		$this->assertNull($this->makeService(INF, 'weekly_contract', 133.0)->estimate('u1'));
	}

	public function testUpperBoundaryDailyHoursStillEstimates(): void
	{
		// 24 h/day is the physical ceiling, not a corruption marker.
		$estimate = $this->makeService(24.0, 'weekly_contract', 133.0)->estimate('u1');
		$this->assertNotNull($estimate);
		$this->assertSame(5, $estimate['days']);
	}

	public function testServiceFailureFailsClosedToNull(): void
	{
		$overtimeService = $this->createMock(OvertimeService::class);
		$overtimeService->method('getAverageDailyPlannedHours')
			->willThrowException(new \RuntimeException('boom'));
		$displayService = $this->createMock(OvertimeDisplayService::class);

		$this->assertNull(
			(new CompDaysEstimateService($overtimeService, $displayService))->estimate('u1')
		);
	}
}
