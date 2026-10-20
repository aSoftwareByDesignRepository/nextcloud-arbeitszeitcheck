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
		$bank->method('getBankStatusForUsers')->willReturn([
			'user1' => [
				'enabled' => true,
				'effective_balance' => 120.0,
				'bank_state' => 'payout_eligible',
				'bank_fill_percent' => 100.0,
				'payout_eligible_hours' => 20.0,
			],
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$traffic = new OvertimeTrafficLightService($config);
		$service = new OvertimeDisplayService($overtime, $bank, $traffic);

		$snapshot = $service->buildManagerMemberSnapshot('user1');
		$this->assertTrue($snapshot['needs_attention']);
		$this->assertContains('payout_eligible', $snapshot['attention_reasons']);
	}

	public function testBuildManagerTeamAlertsFiltersSortsAndLimits(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default) {
			return in_array($key, ['overtime_traffic_light_enabled', 'overtime_bank_enabled'], true) ? '1' : $default;
		});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getBankStatusForUsers')->willReturnCallback(
			static function (array $uids): array {
				$map = array_fill_keys($uids, [
					'enabled' => true,
					'effective_balance' => 50.0,
					'bank_state' => 'payout_eligible',
					'bank_fill_percent' => 80.0,
					'payout_eligible_hours' => 10.0,
				]);
				$map['clean'] = [
					'enabled' => true,
					'effective_balance' => 0.0,
					'bank_state' => 'bank_green',
					'bank_fill_percent' => 0.0,
					'payout_eligible_hours' => 0.0,
				];
				return $map;
			}
		);

		$overtime = $this->createMock(OvertimeService::class);
		$traffic = new OvertimeTrafficLightService($config);
		$service = new OvertimeDisplayService($overtime, $bank, $traffic);

		$alerts = $service->buildManagerTeamAlerts(['clean', 'b-user', 'a-user']);
		$this->assertCount(2, $alerts);
		$this->assertSame(['a-user', 'b-user'], array_column($alerts, 'user_id'));
		$this->assertSame('payout_eligible', $alerts[0]['bank_state']);

		$this->assertCount(1, $service->buildManagerTeamAlerts(['clean', 'b-user', 'a-user'], 1));
	}

	public function testBuildManagerMemberSnapshotsComputesOneBankStatusPerUser(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default) {
			return $key === 'overtime_traffic_light_enabled' ? '1' : $default;
		});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->expects($this->once())
			->method('getBankStatusForUsers')
			->with(['u1', 'u2'])
			->willReturn([
				'u1' => ['enabled' => true, 'effective_balance' => 120.0, 'bank_state' => 'payout_eligible', 'bank_fill_percent' => 100.0, 'payout_eligible_hours' => 20.0],
				'u2' => ['enabled' => true, 'effective_balance' => 0.0, 'bank_state' => 'bank_green', 'bank_fill_percent' => 0.0, 'payout_eligible_hours' => 0.0],
			]);
		// The batched path must never fall back to the per-user status.
		$bank->expects($this->never())->method('getBankStatus');

		$service = new OvertimeDisplayService(
			$this->createMock(OvertimeService::class),
			$bank,
			new OvertimeTrafficLightService($config),
		);

		$snapshots = $service->buildManagerMemberSnapshots(['u1', 'u2']);

		$this->assertSame(['u1', 'u2'], array_keys($snapshots));
		$this->assertSame(120.0, $snapshots['u1']['balance']);
		$this->assertSame('payout_eligible', $snapshots['u1']['bank_state']);
		$this->assertTrue($snapshots['u1']['needs_attention']);
		$this->assertContains('payout_eligible', $snapshots['u1']['attention_reasons']);
		$this->assertSame(0.0, $snapshots['u2']['balance']);
		$this->assertFalse($snapshots['u2']['needs_attention']);
	}

	public function testBuildManagerMemberSnapshotsEmptyInputDoesNotQuery(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->expects($this->never())->method('getBankStatusForUsers');
		$service = new OvertimeDisplayService(
			$this->createMock(OvertimeService::class),
			$bank,
			new OvertimeTrafficLightService($this->createMock(IConfig::class)),
		);
		$this->assertSame([], $service->buildManagerMemberSnapshots([]));
	}
}