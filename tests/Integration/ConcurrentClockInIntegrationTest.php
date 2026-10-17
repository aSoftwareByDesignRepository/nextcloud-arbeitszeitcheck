<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

/**
 * Zeus concurrent → DB truth: per-user exclusive lock serialises clock-in,
 * and a second clock-in cannot leave two STATUS_ACTIVE rows for one user.
 */
class ConcurrentClockInIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_zeus_concurrent_clock__';

	private TimeTrackingService $timeTracking;

	private TimeEntryMapper $timeEntryMapper;

	private ILockingProvider $locking;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeTracking = \OCP\Server::get(TimeTrackingService::class);
		$this->timeEntryMapper = \OCP\Server::get(TimeEntryMapper::class);
		$this->locking = \OCP\Server::get(ILockingProvider::class);
		$this->cleanupUserRows();
	}

	protected function tearDown(): void
	{
		$this->cleanupUserRows();
		parent::tearDown();
	}

	public function testHeldUserLockBlocksConcurrentClockIn(): void
	{
		$key = DbLockKeys::timeTrackingUser(self::TEST_USER);
		$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'zeus held lock');
		try {
			$this->expectException(LockedException::class);
			$this->timeTracking->clockIn(self::TEST_USER);
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->assertNull(
			$this->timeEntryMapper->findActiveByUser(self::TEST_USER),
			'Blocked concurrent clock-in must not insert an active row',
		);
	}

	public function testSecondClockInLeavesExactlyOneActiveRow(): void
	{
		$first = $this->timeTracking->clockIn(self::TEST_USER);
		$this->assertInstanceOf(TimeEntry::class, $first);
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $first->getStatus());

		$activeBefore = $this->timeEntryMapper->findActiveByUser(self::TEST_USER);
		$this->assertNotNull($activeBefore);
		$this->assertSame($first->getId(), $activeBefore->getId());
		$this->assertSame(self::TEST_USER, $activeBefore->getLiveUserId());

		try {
			$this->timeTracking->clockIn(self::TEST_USER);
			$this->fail('Second clock-in must be rejected');
		} catch (BusinessRuleException $e) {
			$this->assertSame(BusinessRuleCode::ALREADY_CLOCKED_IN, $e->getReasonCode());
		}

		$activeAfter = $this->timeEntryMapper->findActiveByUser(self::TEST_USER);
		$this->assertNotNull($activeAfter);
		$this->assertSame($first->getId(), $activeAfter->getId());
		$this->assertSame(1, $this->countActiveRows(), 'DB truth: exactly one active session');
	}

	private function countActiveRows(): int
	{
		$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from('at_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_ACTIVE)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function cleanupUserRows(): void
	{
		$key = DbLockKeys::timeTrackingUser(self::TEST_USER);
		try {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			// no held lock
		}

		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		foreach (['at_audit', 'at_entries'] as $table) {
			if (!$db->tableExists($table)) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
				->executeStatement();
		}
	}
}
