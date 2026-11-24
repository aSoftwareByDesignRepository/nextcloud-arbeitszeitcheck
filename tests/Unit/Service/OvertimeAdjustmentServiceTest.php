<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class OvertimeAdjustmentServiceTest extends TestCase
{
	/**
	 * Plain IDBConnection double — TTransactional::atomic() drives
	 * beginTransaction/commit/rollBack on it; savepoint semantics under an
	 * outer transaction are verified on the real DB in the integration suite.
	 */
	private function dbMock(): IDBConnection
	{
		return $this->createMock(IDBConnection::class);
	}

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

		$svc = new OvertimeAdjustmentService($mapper, $bank, $audit, $users,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
			$this->dbMock(),
		);
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
			$users,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
			$this->dbMock(),
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
			$users,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
			$this->dbMock(),
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
			$users,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
			$this->dbMock(),
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

		$svc = new OvertimeAdjustmentService($mapper, $bank, $audit, $users,
			$this->createMock(\OCP\Lock\ILockingProvider::class),
			$this->dbMock(),
		);
		$r = $svc->listForUser('alice', 2026);

		$this->assertSame(1, $r['total']);
		$this->assertSame(2026, $r['year']);
		$this->assertSame(12.5, $r['effective_balance']);
		$this->assertCount(1, $r['items']);
		$this->assertSame(7, $r['items'][0]['id']);
		$this->assertSame(2.5, $r['items'][0]['hours_delta']);
		$this->assertSame('2026-03-01', $r['items'][0]['effective_on']);
	}

	/**
	 * The balance read → compute → insert sequence must run under the
	 * per-user exclusive ledger lock, or two concurrent adjustments persist
	 * a self-contradicting balance_before/balance_after chain. Assert the
	 * lock wraps the whole mutation and is released on every exit.
	 */
	public function testCreateAdjustmentHoldsExclusiveLedgerLockAroundMutation(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$lockHeldDuringInsert = false;
		$lockHeld = false;
		$acquired = [];
		$released = [];

		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type) use (&$acquired, &$lockHeld): void {
				$acquired[] = [$key, $type];
				$lockHeld = $key === DbLockKeys::overtimeBalance('alice') && $type === ILockingProvider::LOCK_EXCLUSIVE;
			}
		);
		$lp->method('releaseLock')->willReturnCallback(
			static function (string $key, int $type) use (&$released, &$lockHeld): void {
				$released[] = [$key, $type];
				$lockHeld = false;
			}
		);
		$lp->method('isLocked')->willReturnCallback(
			static function (string $key, int $type) use (&$lockHeld): bool {
				return $lockHeld && $key === DbLockKeys::overtimeBalance('alice') && $type === ILockingProvider::LOCK_EXCLUSIVE; // our own exclusive lock reads as locked
			}
		);

		$mapper->expects($this->once())
			->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $e) use (&$lockHeldDuringInsert, $lp): OvertimeAdjustment {
				$lockHeldDuringInsert = $lp->isLocked(
					DbLockKeys::overtimeBalance('alice'),
					ILockingProvider::LOCK_EXCLUSIVE
				);
				$e->setId(1);
				return $e;
			});

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturnCallback(static function () use (&$lockHeld): array {
			self::assertTrue($lockHeld, 'balance read must run under the exclusive ledger lock');
			return ['effective_balance' => 20.0];
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper, $bank, $this->createMock(AuditLogMapper::class), $users, $lp,
			$this->dbMock()
		);
		$svc->createAdjustment('alice', 5.0, OvertimeAdjustment::REASON_MANUAL_PAYOUT, null, 'admin');

		$this->assertContains([DbLockKeys::overtimeBalance('alice'), ILockingProvider::LOCK_EXCLUSIVE], $acquired);
		$this->assertContains([DbLockKeys::overtimeBalance('alice'), ILockingProvider::LOCK_EXCLUSIVE], $released);
		$this->assertTrue($lockHeldDuringInsert, 'insert must run under the exclusive ledger lock');
	}

	public function testLockReleasedWhenInsertThrows(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->method('insertAdjustment')->willThrowException(new \RuntimeException('db gone'));

		$released = [];
		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('releaseLock')->willReturnCallback(
			static function (string $key, int $type) use (&$released): void {
				$released[] = [$key, $type];
			}
		);

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 20.0]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper, $bank, $this->createMock(AuditLogMapper::class), $users, $lp,
			$this->dbMock()
		);
		try {
			$svc->createAdjustment('alice', 5.0, OvertimeAdjustment::REASON_MANUAL_PAYOUT, null, 'admin');
			$this->fail('expected RuntimeException');
		} catch (\RuntimeException $e) {
			// expected
		}
		$this->assertContains([DbLockKeys::overtimeBalance('alice'), ILockingProvider::LOCK_EXCLUSIVE], $released);
	}

	/**
	 * C-01: the ledger row and its audit record are one unit of work —
	 * both writes must happen inside the atomic transaction, in order.
	 */
	public function testInsertAndAuditRunInsideAtomicScope(): void
	{
		$order = [];

		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->expects($this->once())
			->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $e) use (&$order): OvertimeAdjustment {
				$order[] = 'insert';
				$e->setId(5);
				return $e;
			});

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())
			->method('logAction')
			->willReturnCallback(static function () use (&$order): \OCA\ArbeitszeitCheck\Db\AuditLog {
				$order[] = 'audit';
				return new \OCA\ArbeitszeitCheck\Db\AuditLog();
			});

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())
			->method('beginTransaction')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'begin';
			});
		$db->expects($this->once())
			->method('commit')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'commit';
			});
		$db->expects($this->never())->method('rollBack');

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 20.0]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper, $bank, $audit, $users,
			$this->createMock(ILockingProvider::class),
			$db,
		);
		$svc->createAdjustment('alice', -10.0, OvertimeAdjustment::REASON_MANUAL_PAYOUT, null, 'admin1');

		$this->assertSame(
			['begin', 'insert', 'audit', 'commit'],
			$order,
			'insert and audit must run inside the transaction, in that order'
		);
	}

	/**
	 * C-01: an audit failure inside the unit of work propagates so atomic()
	 * rolls back — the service must never report success for an unaudited
	 * financial write. The no-row-survives proof lives in the integration
	 * suite on the real DB.
	 */
	public function testAuditFailureInsideAtomicPropagatesAndNoSuccessIsReturned(): void
	{
		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $e): OvertimeAdjustment {
				$e->setId(9);
				return $e;
			});

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('logAction')->willThrowException(new \RuntimeException('audit insert failed'));

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 20.0]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper, $bank, $audit, $users,
			$this->createMock(ILockingProvider::class),
			$db,
		);

		$this->expectException(\RuntimeException::class);
		$svc->createAdjustment('alice', -10.0, OvertimeAdjustment::REASON_MANUAL_PAYOUT, null, 'admin1');
	}

	/**
	 * C-01/§13.3: the advisory ledger lock is acquired BEFORE the write
	 * transaction opens — a lock-after-transaction mutant would let a
	 * second writer interleave inside its own transaction.
	 */
	public function testLedgerLockIsAcquiredBeforeAtomicTransaction(): void
	{
		$order = [];

		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'lock';
			}
		);
		$lp->method('releaseLock')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'unlock';
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'begin';
			}
		);
		$db->method('commit')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'commit';
			}
		);

		$mapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$mapper->method('insertAdjustment')
			->willReturnCallback(static function (OvertimeAdjustment $e): OvertimeAdjustment {
				$e->setId(1);
				return $e;
			});
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('getBankStatus')->willReturn(['effective_balance' => 20.0]);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		$svc = new OvertimeAdjustmentService(
			$mapper, $bank, $this->createMock(AuditLogMapper::class), $users, $lp, $db
		);
		$svc->createAdjustment('alice', 5.0, OvertimeAdjustment::REASON_MANUAL_PAYOUT, null, 'admin');

		$this->assertSame(['lock', 'begin', 'commit', 'unlock'], $order);
	}

}