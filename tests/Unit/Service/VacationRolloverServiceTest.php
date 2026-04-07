<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class VacationRolloverServiceTest extends TestCase
{
	public function testProcessSkipsWhenTargetYearAlreadyHasBalance(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['arbeitszeitcheck', Constants::CONFIG_VACATION_ROLLOVER_ENABLED, '1', '1'],
			['arbeitszeitcheck', Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL, '0', '0'],
		]);

		$alloc = $this->createMock(VacationAllocationService::class);
		$alloc->method('getCarryoverExpiryDateForYear')->willReturnCallback(function (int $y) {
			return new \DateTimeImmutable($y . '-03-31');
		});
		$alloc->method('computeYearAllocation')->willReturn([
			'carryover_remaining_after_approved' => 2.0,
			'annual_remaining_after_approved' => 0.0,
		]);
		$alloc->method('applyCapToOpeningBalance')->willReturnCallback(fn (float $d) => $d);

		$balance = $this->createMock(VacationYearBalanceMapper::class);
		$balance->method('getCarryoverDays')->willReturnCallback(function (string $uid, int $year) {
			return $year === 2027 ? 3.0 : 0.0;
		});

		$log = $this->createMock(VacationRolloverLogMapper::class);
		$log->method('existsForUserAndYears')->willReturn(false);

		$users = $this->createMock(IUserManager::class);
		$audit = $this->createMock(AuditLogMapper::class);

		$s = new VacationRolloverService(
			$config,
			$alloc,
			$balance,
			$log,
			$users,
			$audit
		);

		$r = $s->processUserForFromYear('u1', 2026, false, false, true);
		$this->assertSame('skipped_target_balance', $r['action']);
	}
}
