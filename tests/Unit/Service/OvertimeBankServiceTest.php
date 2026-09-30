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

	private function configFor(bool $enabled): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_ENABLED, '0', $enabled ? '1' : '0'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)OvertimeBankService::DEFAULT_BANK_MAX_HOURS, '100'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80', '80'],
			['arbeitszeitcheck', Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95', '95'],
		]);
		return $config;
	}

	public function testGetMonthEndSnapshotComputesEffectiveAndEligible(): void
	{
		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn(['cumulative_balance' => 120.0]);
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYear')->willReturn(30.0);
		$adjMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class);
		$adjMapper->expects($this->once())->method('sumHoursDeltaForYearThroughDate')
			->willReturn(2.5);

		$service = new OvertimeBankService($this->configFor(true), $overtime, $payoutMapper, $adjMapper);
		$snap = $service->getMonthEndSnapshot('u1', 2026, 3);

		$this->assertSame(120.0, $snap['raw_balance']);
		// 120 - 30 + 2.5 = 92.5
		$this->assertSame(92.5, $snap['effective_balance']);
		$this->assertSame(0.0, $snap['payout_eligible_hours']);
		$this->assertSame(100.0, $snap['bank_max_hours']);
		$this->assertSame(30.0, $snap['total_payouts_before_month']);
		$this->assertSame(2.5, $snap['total_adjustments_ytd']);
	}

	public function testGetMonthEndSnapshotRejectsInvalidMonth(): void
	{
		$service = new OvertimeBankService(
			$this->configFor(true),
			$this->createMock(OvertimeService::class),
			$this->createMock(OvertimePayoutMapper::class),
		);
		$this->expectException(\InvalidArgumentException::class);
		$service->getMonthEndSnapshot('u1', 2026, 13);
	}

	public function testSumAdjustmentsSwallowsMapperFailure(): void
	{
		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn(['cumulative_balance' => 10.0]);
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYear')->willReturn(0.0);
		$adjMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class);
		$adjMapper->method('sumHoursDeltaForYearThroughDate')
			->willThrowException(new \RuntimeException('table missing'));

		$service = new OvertimeBankService($this->configFor(true), $overtime, $payoutMapper, $adjMapper);
		$snap = $service->getMonthEndSnapshot('u1', 2026, 1);
		$this->assertSame(0.0, $snap['total_adjustments_ytd']);
		$this->assertSame(10.0, $snap['effective_balance']);
	}

	public function testSanitizeBankMaxHoursHandlesCommasAndGarbage(): void
	{
		$service = $this->makeService(true, 100);
		$this->assertSame(40.5, $service->sanitizeBankMaxHours('40,5'));
		$this->assertSame(OvertimeBankService::MIN_BANK_MAX_HOURS, $service->sanitizeBankMaxHours('-5'));
		$this->assertSame(OvertimeBankService::MAX_BANK_MAX_HOURS, $service->sanitizeBankMaxHours('99999'));
		// non-numeric strings cast to 0.0 -> MIN clamp
		$this->assertSame(OvertimeBankService::MIN_BANK_MAX_HOURS, $service->sanitizeBankMaxHours('INF'));
		// string overflow to a non-finite float -> DEFAULT
		$this->assertSame(
			OvertimeBankService::DEFAULT_BANK_MAX_HOURS,
			$service->sanitizeBankMaxHours('1e999')
		);
	}

	public function testSanitizePercentClampsToRangeOrDefault(): void
	{
		$service = $this->makeService(true, 100);
		$this->assertSame(80, $service->sanitizePercent('80', 50));
		$this->assertSame(50, $service->sanitizePercent('-3', 50));
		$this->assertSame(50, $service->sanitizePercent('101', 50));
		$this->assertSame(75, $service->sanitizePercent('74,6', 50));
	}

	public function testBuildClosureAuditBlockReturnsNullWhenDisabled(): void
	{
		$service = new OvertimeBankService(
			$this->configFor(false),
			$this->createMock(OvertimeService::class),
			$this->createMock(OvertimePayoutMapper::class),
		);
		$this->assertNull($service->buildClosureAuditBlock('u1', 2026, 3));
	}

	public function testBuildClosureAuditBlockIncludesPayoutRecord(): void
	{
		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('calculateOvertime')->willReturn(['cumulative_balance' => 130.0]);
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('sumHoursPaidForYear')->willReturn(0.0);

		$payout = new \OCA\ArbeitszeitCheck\Db\OvertimePayout();
		$payout->setCalendarYear(2026);
		$payout->setCalendarMonth(3);
		$payout->setHoursPaid(30.0);
		$payout->setEffectiveBalanceBefore(130.0);
		$payout->setEffectiveBalanceAfter(100.0);
		$payout->setRawBalanceBefore(130.0);
		$payout->setBankMaxHours(100.0);
		$payout->setProcessedBy('admin1');
		$payout->setCreatedAt(new \DateTime('2026-03-31 23:59:00'));

		$service = new OvertimeBankService($this->configFor(true), $overtime, $payoutMapper);
		$block = $service->buildClosureAuditBlock('u1', 2026, 3, $payout);

		$this->assertTrue($block['enabled']);
		$this->assertSame(130.0, $block['effective_balance_eom']);
		$this->assertSame(30.0, $block['payout_eligible_eom']);
		$this->assertSame(100.0, $block['banked_hours_eom']);
		$this->assertSame(2026, $block['payout_record']['calendar_year']);
		$this->assertSame(3, $block['payout_record']['calendar_month']);
		$this->assertSame(30.0, $block['payout_record']['hours_paid']);
		$this->assertSame('admin1', $block['payout_record']['processed_by']);
	}
}
