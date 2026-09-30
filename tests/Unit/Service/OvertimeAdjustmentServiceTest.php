<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class OvertimeAdjustmentServiceTest extends TestCase
{
	public function testCreateAdjustmentCreditsAndDebitsSaldoWithoutTouchingTimeEntries(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->expects($this->once())
			->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $entity): OvertimeAdjustment {
				$entity->setId(42);
				return $entity;
			});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 20.0]);

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction');

		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('alice')->willReturn($user);

		$svc = new OvertimeAdjustmentService($mapper, $bank, $audit, $users);
		$result = $svc->createAdjustment(
			'alice',
			-10.0,
			OvertimeAdjustment::REASON_MANUAL_PAYOUT,
			'Paid out',
			'admin1'
		);

		$this->assertSame(20.0, $result['balance_before']);
		$this->assertSame(10.0, $result['balance_after']);
		$this->assertSame(-10.0, $result['adjustment']['hours_delta']);
		$this->assertSame(42, $result['adjustment']['id']);
	}

	public function testResetBalanceToZeroSkipsWhenAlreadyZero(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->expects($this->never())->method('insertAdjustment');

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 0.0]);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper,
			$bank,
			$this->createMock(AuditLogMapper::class),
			$users
		);
		$result = $svc->resetBalanceToZero('alice', 'admin1');
		$this->assertSame('skipped_zero', $result['action']);
	}

	public function testResetBalanceToZeroWritesCompensatingCreditForUndertime(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->expects($this->once())
			->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $entity): OvertimeAdjustment {
				self::assertSame(8.0, $entity->getHoursDelta());
				self::assertSame(OvertimeAdjustment::REASON_UNDERTIME_WAIVER, $entity->getReasonCode());
				$entity->setId(7);
				return $entity;
			});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => -8.0]);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper,
			$bank,
			$this->createMock(AuditLogMapper::class),
			$users
		);
		$result = $svc->resetBalanceToZero('alice', 'admin1');
		$this->assertSame('reset', $result['action']);
		$this->assertSame(0.0, $result['balance_after']);
	}

	public function testCreateAdjustmentRejectsInvalidRange(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$svc = new OvertimeAdjustmentService(
			$this->createMock(OvertimeAdjustmentMapper::class),
			$this->createMock(OvertimeBankService::class),
			$this->createMock(AuditLogMapper::class),
			$users
		);
		$this->expectException(\InvalidArgumentException::class);
		$svc->createAdjustment('alice', 0.0, OvertimeAdjustment::REASON_CUSTOM, null, 'admin');
	}

	public function testListForUserReturnsItemsAndBalance(): void
	{
		$mapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class);
		$bank = $this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class);
		$audit = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
		$users = $this->createMock(\OCP\IUserManager::class);

		$row = new \OCA\ArbeitszeitCheck\Db\OvertimeAdjustment();
		$row->setId(7);
		$row->setUserId('alice');
		$row->setCalendarYear(2026);
		$row->setEffectiveOn(new \DateTime('2026-03-01'));
		$row->setHoursDelta(2.5);
		$row->setReasonCode('correction');
		$row->setNote('fix');
		$row->setProcessedBy('admin');
		$row->setCreatedAt(new \DateTime('2026-03-02'));

		$mapper->method('findByUserAndYear')->with('alice', 2026, 50, 0)->willReturn([$row]);
		$mapper->method('countByUserAndYear')->with('alice', 2026)->willReturn(1);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 12.5]);

		$svc = new OvertimeAdjustmentService($mapper, $bank, $audit, $users);
		$r = $svc->listForUser('alice', 2026);

		$this->assertSame(1, $r['total']);
		$this->assertSame(2026, $r['year']);
		$this->assertSame(12.5, $r['effective_balance']);
		$this->assertCount(1, $r['items']);
		$this->assertSame(7, $r['items'][0]['id']);
		$this->assertSame(2.5, $r['items'][0]['hours_delta']);
		$this->assertSame('2026-03-01', $r['items'][0]['effective_on']);
	}

}