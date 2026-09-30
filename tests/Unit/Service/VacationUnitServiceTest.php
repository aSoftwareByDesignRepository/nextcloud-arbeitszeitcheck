<?php

declare(strict_types=1);

/**
 * Unit tests for VacationUnitService (Q3=A / Q8).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class VacationUnitServiceTest extends TestCase
{
	private function make(array $appValues = []): VacationUnitService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($appValues): string {
				return array_key_exists($key, $appValues) ? (string)$appValues[$key] : $default;
			}
		);
		return new VacationUnitService($config);
	}

	public function testDefaultsToDays(): void
	{
		$s = $this->make();
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $s->getUnit());
		$this->assertTrue($s->isDaysMode());
		$this->assertFalse($s->isHoursMode());
	}

	public function testHoursModeAndConversion(): void
	{
		$s = $this->make([
			Constants::CONFIG_VACATION_UNIT => 'hours',
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8.25',
		]);
		$this->assertTrue($s->isHoursMode());
		$this->assertSame(8.25, $s->getHoursPerDay());
		$this->assertSame(33.0, $s->daysToHours(4.0));
		$this->assertSame(4.0, $s->hoursToDays(33.0));
	}

	public function testClampEntitlementDiffersByUnit(): void
	{
		$days = $this->make();
		$this->assertSame(366.0, $days->clampEntitlement(999.0));

		$hours = $this->make([Constants::CONFIG_VACATION_UNIT => 'hours']);
		$this->assertSame(4000.0, $hours->clampEntitlement(9999.0));
		$this->assertSame(200.0, $hours->clampEntitlement(200.0));
	}

	public function testAdminDaysInputConvertsInHoursMode(): void
	{
		$s = $this->make([
			Constants::CONFIG_VACATION_UNIT => 'hours',
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
		]);
		$this->assertSame(200.0, $s->adminDaysToStoredAmount(25.0));
		$this->assertSame(25.0, $s->storedAmountToAdminDays(200.0));
	}

	public function testAdminDaysInputIdentityInDaysMode(): void
	{
		$s = $this->make();
		$this->assertSame(25.0, $s->adminDaysToStoredAmount(25.0));
		$this->assertSame(25.0, $s->storedAmountToAdminDays(25.0));
	}

	public function testGetStatusExposesMigratedAt(): void
	{
		$s = $this->make([Constants::CONFIG_VACATION_UNIT_MIGRATED_AT => '2026-09-01T12:00:00Z']);
		$status = $s->getStatus();
		$this->assertSame('2026-09-01T12:00:00Z', $status['migrated_at']);
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $status['unit']);

		$this->assertNull($this->make()->getStatus()['migrated_at']);
	}

	public function testClientConfirmedFlag(): void
	{
		$off = $this->make();
		$this->assertFalse($off->isClientConfirmedForHours());
		$on = $this->make([Constants::CONFIG_VACATION_UNIT_CLIENT_CONFIRMED => '1']);
		$this->assertTrue($on->isClientConfirmedForHours());
	}

	public function testInvalidHoursPerDayFallsBack(): void
	{
		$s = $this->make([Constants::CONFIG_VACATION_HOURS_PER_DAY => '99']);
		$this->assertSame(Constants::DEFAULT_VACATION_HOURS_PER_DAY, $s->getHoursPerDay());
	}

	public function testStoredAmountCeilingDependsOnUnit(): void
	{
		$this->assertSame(366.0, $this->make()->storedAmountCeiling());
		$this->assertSame(4000.0, $this->make([Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_HOURS])->storedAmountCeiling());
	}
}