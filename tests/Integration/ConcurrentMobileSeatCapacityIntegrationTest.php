<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

/**
 * Atlas Lens 1 — mobile seat capacity lock + limit ground truth.
 *
 * Note: PHPUnit bootstrap injects the test AZC vendor public key, which makes
 * the live (prod-signed) license fail crypto re-verify. Tests that need an
 * active plan skip under that env. True concurrent overshoot is proven by
 * {@see scripts/atlas-concurrent-seat-race.php} (separate processes, live key).
 */
class ConcurrentMobileSeatCapacityIntegrationTest extends TestCase
{
	/** Must match MobileSeatService::CAPACITY_LOCK */
	private const CAPACITY_LOCK = 'arbeitszeitcheck/mobile_seat_capacity';

	private const PREFIX = '__azc_atlas_seat_';

	private MobileSeatService $seats;
	private IUserManager $users;
	private ILockingProvider $locking;

	/** @var list<string> */
	private array $createdUsers = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->seats = \OCP\Server::get(MobileSeatService::class);
		$this->users = \OCP\Server::get(IUserManager::class);
		$this->locking = \OCP\Server::get(ILockingProvider::class);
	}

	protected function tearDown(): void
	{
		foreach ($this->createdUsers as $uid) {
			try {
				$this->seats->removeSeat($uid);
			} catch (\Throwable) {
			}
			$this->users->get($uid)?->delete();
		}
		$this->createdUsers = [];
		parent::tearDown();
	}

	public function testHeldCapacityLockBlocksConcurrentAssignWhenPlanActive(): void
	{
		if ($this->seats->getSeatLimit() < 1) {
			$this->markTestSkipped('Active mobile plan required (PHPUnit may use test vendor key)');
		}
		$uid = $this->createTempUser('lock');
		$this->locking->acquireLock(self::CAPACITY_LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'atlas held seat lock');
		try {
			$this->expectException(LockedException::class);
			$this->seats->assignSeat($uid, 'atlas');
		} finally {
			$this->locking->releaseLock(self::CAPACITY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
		$this->assertFalse(
			$this->seats->isUserAllowed($uid),
			'Blocked concurrent assign must not create a seat row',
		);
	}

	public function testAssignFailsAtCapacityWithoutExceedingLimit(): void
	{
		if ($this->seats->getSeatLimit() < 1) {
			$this->markTestSkipped('Active mobile plan required (PHPUnit may use test vendor key)');
		}
		$limit = $this->seats->getSeatLimit();
		while ($this->seats->getAssignedCount() < $limit) {
			$fill = $this->createTempUser('fill' . count($this->createdUsers));
			$r = $this->seats->assignSeat($fill, 'atlas');
			$this->assertTrue($r['ok'], 'fill failed: ' . ($r['error'] ?? ''));
		}
		$this->assertSame($limit, $this->seats->getAssignedCount());

		$overflow = $this->createTempUser('overflow');
		$r = $this->seats->assignSeat($overflow, 'atlas');
		$this->assertFalse($r['ok']);
		$this->assertSame('seat_limit_reached', $r['error'] ?? null);
		$this->assertSame($limit, $this->seats->getAssignedCount(), 'DB truth: count must stay at limit');
		$this->assertFalse($this->seats->isUserAllowed($overflow));
	}

	private function createTempUser(string $suffix): string
	{
		$uid = self::PREFIX . $suffix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
		$password = 'Azc-Seat-' . bin2hex(random_bytes(6)) . '!A1';
		$user = $this->users->createUser($uid, $password);
		$this->assertNotNull($user);
		$this->createdUsers[] = $uid;
		return $uid;
	}
}
