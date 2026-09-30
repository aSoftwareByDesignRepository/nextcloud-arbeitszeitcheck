<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\MobileSeat;
use OCA\ArbeitszeitCheck\Db\MobileSeatMapper;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class MobileSeatServiceBatchTest extends TestCase
{
	public function testBatchRespectsCapacityAndPartialResults(): void
	{
		$mapper = $this->createMock(MobileSeatMapper::class);
		$license = $this->createMock(LicenseService::class);
		$users = $this->createMock(IUserManager::class);
		$time = $this->createMock(ITimeFactory::class);
		$db = $this->createMock(IDBConnection::class);
		$lock = $this->createMock(ILockingProvider::class);

		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('getMobileSeatLimit')->willReturn(2);

		$user = $this->createMock(IUser::class);
		$users->method('get')->willReturnCallback(static function (string $id) use ($user) {
			return in_array($id, ['a', 'b', 'c', 'missing'], true) && $id !== 'missing' ? $user : null;
		});

		$assigned = [];
		$mapper->method('findByUserId')->willReturnCallback(static function (string $id) use (&$assigned) {
			return isset($assigned[$id]) ? $assigned[$id] : null;
		});
		$mapper->method('countSeats')->willReturnCallback(static function () use (&$assigned) {
			return count($assigned);
		});
		$mapper->method('insert')->willReturnCallback(static function (MobileSeat $seat) use (&$assigned) {
			$assigned[$seat->getUserId()] = $seat;
			return $seat;
		});
		$mapper->method('findAllOrdered')->willReturn([]);

		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-21'));
		$lock->expects($this->once())->method('acquireLock');
		$lock->expects($this->once())->method('releaseLock');
		$db->method('beginTransaction');
		$db->method('commit');

		$service = new MobileSeatService($mapper, $license, $users, $time, $db, $lock);
		$result = $service->assignSeatsBatch(['a', 'b', 'c', 'missing', 'a'], 'admin');

		$this->assertTrue($result['ok']);
		$this->assertSame(2, $result['summary']['assigned']);
		$this->assertSame(0, $result['summary']['skipped']);
		$this->assertSame(2, $result['summary']['failed']); // c seat_limit + missing
		$statuses = [];
		foreach ($result['results'] as $row) {
			$statuses[$row['userId']] = $row;
		}
		$this->assertSame('assigned', $statuses['a']['status']);
		$this->assertSame('assigned', $statuses['b']['status']);
		$this->assertSame('failed', $statuses['c']['status']);
		$this->assertSame('seat_limit_reached', $statuses['c']['error']);
		$this->assertSame('failed', $statuses['missing']['status']);
		$this->assertSame('user_not_found', $statuses['missing']['error']);
		$this->assertSame(2, $result['mobileSeatsUsed']);
		$this->assertSame(2, $result['mobileSeatsLimit']);
	}

	public function testBatchSkipsAlreadySeated(): void
	{
		$mapper = $this->createMock(MobileSeatMapper::class);
		$license = $this->createMock(LicenseService::class);
		$users = $this->createMock(IUserManager::class);
		$time = $this->createMock(ITimeFactory::class);
		$db = $this->createMock(IDBConnection::class);
		$lock = $this->createMock(ILockingProvider::class);

		$license->method('isMobilePlanActive')->willReturn(true);
		$license->method('getMobileSeatLimit')->willReturn(5);
		$user = $this->createMock(IUser::class);
		$users->method('get')->willReturn($user);

		$existing = new MobileSeat();
		$existing->setUserId('a');
		$mapper->method('findByUserId')->willReturnCallback(static fn (string $id) => $id === 'a' ? $existing : null);
		$mapper->method('countSeats')->willReturn(1);
		$mapper->expects($this->once())->method('insert');
		$mapper->method('findAllOrdered')->willReturn([]);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$db->method('beginTransaction');
		$db->method('commit');

		$service = new MobileSeatService($mapper, $license, $users, $time, $db, $lock);
		$result = $service->assignSeatsBatch(['a', 'b'], 'admin');
		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['summary']['assigned']);
		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertSame('already_seated', $result['results'][0]['error']);
	}

	public function testListSeatsMapsUsersAndFallsBackToUid(): void
	{
		$mapper = $this->createMock(MobileSeatMapper::class);
		$seat1 = new MobileSeat();
		$seat1->setUserId('alice');
		$seat1->setAssignedAt(new \DateTime('2026-01-05 10:00:00'));
		$seat1->setAssignedBy('admin');
		$seat2 = new MobileSeat();
		$seat2->setUserId('ghost');
		$mapper->method('findAllOrdered')->willReturn([$seat1, $seat2]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice A.');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid) => $uid === 'alice' ? $user : null
		);

		$service = new MobileSeatService(
			$mapper,
			$this->createMock(LicenseService::class),
			$users,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
		);

		$seats = $service->listSeats();
		$this->assertCount(2, $seats);
		$this->assertSame('Alice A.', $seats[0]['displayName']);
		$this->assertSame('admin', $seats[0]['assignedBy']);
		$this->assertStringContainsString('2026-01-05', $seats[0]['assignedAt']);
		// deleted user -> uid fallback, empty assignedAt
		$this->assertSame('ghost', $seats[1]['displayName']);
		$this->assertSame('', $seats[1]['assignedAt']);
	}

	public function testRemoveAllSeatsDeletesAndReturnsCount(): void
	{
		$mapper = $this->createMock(MobileSeatMapper::class);
		$seats = [new MobileSeat(), new MobileSeat(), new MobileSeat()];
		$mapper->method('findAllOrdered')->willReturn($seats);
		$mapper->expects($this->exactly(3))->method('delete')
			->withConsecutive([$seats[0]], [$seats[1]], [$seats[2]]);

		$service = new MobileSeatService(
			$mapper,
			$this->createMock(LicenseService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
		);
		$this->assertSame(3, $service->removeAllSeats());
	}
}