<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class OvertimeDisplayServiceTest extends TestCase
{
	public function testUsesEffectiveBalanceWhenBankEnabled(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn([
			'enabled' => true,
			'effective_balance' => 42.5,
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->expects($this->never())->method('calculateOvertime');

		$traffic = new OvertimeTrafficLightService($this->createMock(IConfig::class));
		$service = new OvertimeDisplayService($overtime, $bank, $traffic);

		$this->assertSame(42.5, $service->getYearToDateBalanceForTrafficLight('user1'));
	}

	public function testUsesBankEffectiveBalanceWhenBankDisabledIncludingAdjustments(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn([
			'enabled' => false,
			'effective_balance' => 7.25,
			'total_adjustments_ytd' => -2.0,
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->expects($this->never())->method('calculateOvertime');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		$traffic = new OvertimeTrafficLightService($config);
		$service = new OvertimeDisplayService($overtime, $bank, $traffic);

		$this->assertSame(7.25, $service->getYearToDateBalanceForTrafficLight('user1'));
	}

	public function testManagerSnapshotFlagsAttention(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default) {
			if ($key === 'overtime_traffic_light_enabled') {
				return '1';
			}
			if ($key === 'overtime_bank_enabled') {
				return '1';
			}
			return $default;
		});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getBankStatus')->willReturn([
			'enabled' => true,
			'effective_balance' => 120.0,
			'bank_state' => 'payout_eligible',
			'bank_fill_percent' => 100.0,
			'payout_eligible_hours' => 20.0,
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$traffic = new OvertimeTrafficLightService($config);
		$service = new OvertimeDisplayService($overtime, $bank, $traffic);

		$snapshot = $service->buildManagerMemberSnapshot('user1');
		$this->assertTrue($snapshot['needs_attention']);
		$this->assertContains('payout_eligible', $snapshot['attention_reasons']);
	}
}
