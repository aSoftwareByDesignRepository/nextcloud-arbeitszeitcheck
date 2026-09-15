<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class OvertimeBankServiceTest extends TestCase
{
	public function testClassifyBankFillPayoutEligible(): void
	{
		$service = $this->makeService(enabled: true, maxHours: 100);
		$this->assertSame('payout_eligible', $service->classifyBankFill(100.0, 5.0, 105.0));
	}

	public function testClassifyBankFillUndertime(): void
	{
		$service = $this->makeService(enabled: true, maxHours: 100);
		$this->assertSame('undertime', $service->classifyBankFill(0.0, 0.0, -3.0));
	}

	public function testGetBankStatusComputesBankedAndEligible(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0', '1'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)OvertimeBankService::DEFAULT_BANK_MAX_HOURS, '100'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80', '80'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95', '95'],
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn([
			'cumulative_balance' => 110.0,
		]);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYearThroughMonth')->willReturn(0.0);

		$service = new OvertimeBankService($config, $overtime, $payoutMapper);
		$status = $service->getBankStatus('user1');

		$this->assertTrue($status['enabled']);
		$this->assertSame(110.0, $status['raw_balance']);
		$this->assertSame(110.0, $status['effective_balance']);
		$this->assertSame(100.0, $status['banked_hours']);
		$this->assertSame(10.0, $status['payout_eligible_hours']);
		$this->assertSame('payout_eligible', $status['bank_state']);
	}

	public function testGetBankStatusSubtractsPayoutsFromEffectiveBalance(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0', '1'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)OvertimeBankService::DEFAULT_BANK_MAX_HOURS, '100'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80', '80'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95', '95'],
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn([
			'cumulative_balance' => 110.0,
		]);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYearThroughMonth')->willReturn(10.0);

		$service = new OvertimeBankService($config, $overtime, $payoutMapper);
		$status = $service->getBankStatus('user1');

		$this->assertSame(100.0, $status['effective_balance']);
		$this->assertSame(0.0, $status['payout_eligible_hours']);
		$this->assertSame(100.0, $status['banked_hours']);
	}

	public function testGetBankStatusAppliesAdjustmentsWhenBankDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0', '0'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)OvertimeBankService::DEFAULT_BANK_MAX_HOURS, '100'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80', '80'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95', '95'],
		]);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn([
			'cumulative_balance' => 12.0,
		]);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYearThroughMonth')->willReturn(5.0);

		$adjMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class);
		$adjMapper->method('sumHoursDeltaForYearThroughDate')->willReturn(-4.0);

		$service = new OvertimeBankService($config, $overtime, $payoutMapper, $adjMapper);
		$status = $service->getBankStatus('user1');

		// Bank off → payouts ignored (legacy); adjustments still applied: 12 + (-4) = 8
		$this->assertFalse($status['enabled']);
		$this->assertSame(12.0, $status['raw_balance']);
		$this->assertSame(5.0, $status['total_payouts_ytd']);
		$this->assertSame(-4.0, $status['total_adjustments_ytd']);
		$this->assertSame(8.0, $status['effective_balance']);
	}

	private function makeService(bool $enabled, float $maxHours): OvertimeBankService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0', $enabled ? '1' : '0'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)OvertimeBankService::DEFAULT_BANK_MAX_HOURS, (string)$maxHours],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80', '80'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95', '95'],
		]);

		return new OvertimeBankService(
			$config,
			$this->createMock(OvertimeService::class),
			$this->createMock(OvertimePayoutMapper::class),
		);
	}
}
