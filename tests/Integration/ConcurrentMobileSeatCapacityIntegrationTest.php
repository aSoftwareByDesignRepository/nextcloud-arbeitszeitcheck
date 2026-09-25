<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCA\ArbeitszeitCheck\Tests\Support\Azc2TestSigning;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Test\TestCase;

/**
 * Atlas Lens 1 — mobile seat capacity lock + limit ground truth.
 *
 * Each test applies a test-signed AZC2 license (vendor test public key) so the
 * capacity-limit code runs without relying on a live production plan. The
 * license is cleared in tearDown. True concurrent overshoot is additionally
 * proven by {@see scripts/atlas-concurrent-seat-race.php} (separate processes).
 */
class ConcurrentMobileSeatCapacityIntegrationTest extends TestCase
{
	/** Must match MobileSeatService::CAPACITY_LOCK */
	private const CAPACITY_LOCK = 'arbeitszeitcheck/mobile_seat_capacity';

	private const PREFIX = '__azc_atlas_seat_';

	private MobileSeatService $seats;
	private IUserManager $users;
	private ILockingProvider $locking;
	private LicenseService $licenseService;

	/** @var list<string> */
	private array $createdUsers = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->seats = \OC::$server->get(MobileSeatService::class);
		$this->users = \OC::$server->get(IUserManager::class);
		$this->locking = \OC::$server->get(ILockingProvider::class);
		$this->licenseService = \OC::$server->get(LicenseService::class);
		$this->licenseService->clearLicense();
		$this->licenseService->applyLicenseKey($this->generateTestLicense());
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
		try {
			$this->licenseService->clearLicense();
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	private function generateTestLicense(): string
	{
		$now = new \DateTimeImmutable();
		return Azc2TestSigning::signPayload([
			'v' => 2,
			'customerId' => 'atlas-test',
			'issuedAt' => $now->format('Y-m-d'),
			'validUntil' => $now->modify('+1 year')->format('Y-m-d'),
			'mobileSeats' => 2,
			'terminalDevices' => 0,
			'product' => 'arbeitszeitcheck',
		]);
	}

	public function testHeldCapacityLockBlocksConcurrentAssignWhenPlanActive(): void
	{
		$this->assertGreaterThanOrEqual(1, $this->seats->getSeatLimit(), 'Test fixture must provide an active mobile plan');
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
		$this->assertGreaterThanOrEqual(1, $this->seats->getSeatLimit(), 'Test fixture must provide an active mobile plan');
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
