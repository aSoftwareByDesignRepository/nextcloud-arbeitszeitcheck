<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Db\TerminalDevice;
use OCA\ArbeitszeitCheck\Kiosk\KioskCrypto;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deep coverage for KioskTerminalService: token validation, expiry sweep,
 * license-limit trimming and mass revocation.
 */
final class KioskTerminalServiceDeepTest extends TestCase
{
	private KioskTerminalMapper&MockObject $mapper;
	private TerminalDeviceService&MockObject $deviceService;
	private LicenseService&MockObject $licenseService;
	private ITimeFactory&MockObject $timeFactory;
	private ILockingProvider&MockObject $lockingProvider;
	private KioskTerminalService $service;

	private ?KioskTerminal $foundByTerminalId = null;
	/** @var list<KioskTerminal> */
	private array $active = [];
	/** @var list<KioskTerminal> */
	private array $pending = [];
	/** @var list<TerminalDevice> */
	private array $devices = [];
	/** @var list<KioskTerminal> */
	private array $updated = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(KioskTerminalMapper::class);
		$this->deviceService = $this->createMock(TerminalDeviceService::class);
		$this->licenseService = $this->createMock(LicenseService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);

		$this->mapper->method('findByTerminalId')->willReturnCallback(fn () => $this->foundByTerminalId);
		$this->mapper->method('findAllActive')->willReturnCallback(fn () => $this->active);
		$this->mapper->method('findPendingPairing')->willReturnCallback(fn () => $this->pending);
		$this->mapper->method('update')->willReturnCallback(function (KioskTerminal $t) {
			$this->updated[] = $t;
			return $t;
		});
		$this->deviceService->method('findAllActiveDevices')->willReturnCallback(fn () => $this->devices);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-02-10 12:00:00'));

		$this->service = new KioskTerminalService(
			$this->mapper,
			$this->deviceService,
			$this->licenseService,
			$this->timeFactory,
			$this->lockingProvider
		);
	}

	private function terminal(string $id, string $status = 'active'): KioskTerminal
	{
		$t = new KioskTerminal();
		$t->setTerminalId($id);
		$t->setStatus($status);
		return $t;
	}

	// ---------------------------------------------------------------
	// validateTerminalToken
	// ---------------------------------------------------------------

	public function testValidateTerminalTokenRejectsEmptyArgs(): void
	{
		$this->assertNull($this->service->validateTerminalToken('', 'tok'));
		$this->assertNull($this->service->validateTerminalToken('term-1', ''));
	}

	public function testValidateTerminalTokenRejectsUnknownOrInactive(): void
	{
		$this->assertNull($this->service->validateTerminalToken('term-1', 'tok'));
		$this->foundByTerminalId = $this->terminal('term-1', 'pending_pairing');
		$this->assertNull($this->service->validateTerminalToken('term-1', 'tok'));
	}

	public function testValidateTerminalTokenRejectsWrongToken(): void
	{
		$t = $this->terminal('term-1');
		$t->setTokenHash(KioskCrypto::hashSecret('correct-token'));
		$this->foundByTerminalId = $t;
		$this->assertNull($this->service->validateTerminalToken('term-1', 'wrong-token'));

		$t2 = $this->terminal('term-1');
		$t2->setTokenHash('');
		$this->foundByTerminalId = $t2;
		$this->assertNull($this->service->validateTerminalToken('term-1', 'any'));
	}

	public function testValidateTerminalTokenAcceptsCorrectSecret(): void
	{
		$t = $this->terminal('term-1');
		$t->setTokenHash(KioskCrypto::hashSecret('correct-token'));
		$this->foundByTerminalId = $t;
		$this->assertSame($t, $this->service->validateTerminalToken('term-1', 'correct-token'));
	}

	// ---------------------------------------------------------------
	// revoke / listTerminals / expiry sweep
	// ---------------------------------------------------------------

	public function testRevokeClearsSecretsAndDetachesDevice(): void
	{
		$t = $this->terminal('term-1');
		$t->setTokenHash('hash');
		$t->setPairingCodeHash('code-hash');
		$t->setPairingExpiresAt(new \DateTime('2026-03-01'));
		$this->foundByTerminalId = $t;
		$this->deviceService->expects($this->once())->method('revokeByKioskTerminalId')->with('term-1');

		$this->service->revoke('term-1');

		$this->assertSame('revoked', $t->getStatus());
		$this->assertNull($t->getPairingCodeHash());
		$this->assertNull($t->getPairingExpiresAt());
		$this->assertCount(1, $this->updated);
	}

	public function testRevokeNoopOnUnknownTerminal(): void
	{
		$this->deviceService->expects($this->never())->method('revokeByKioskTerminalId');
		$this->service->revoke('nope');
		$this->assertCount(0, $this->updated);
	}

	public function testListTerminalsExpiresStalePendingThenMerges(): void
	{
		$active = $this->terminal('a-1');
		$stale = $this->terminal('p-stale', 'pending_pairing');
		$stale->setPairingExpiresAt(new \DateTime('2026-01-01')); // before "now"
		$fresh = $this->terminal('p-fresh', 'pending_pairing');
		$fresh->setPairingExpiresAt(new \DateTime('2026-03-01')); // after "now"
		$this->active = [$active];
		$this->pending = [$stale, $fresh];

		$list = $this->service->listTerminals();

		$this->assertSame(['a-1', 'p-stale', 'p-fresh'], array_map(static fn (KioskTerminal $t) => $t->getTerminalId(), $list));
		$this->assertSame('revoked', $stale->getStatus());
		$this->assertSame('pending_pairing', $fresh->getStatus());
		$this->assertCount(1, $this->updated); // only the stale one expired
	}

	// ---------------------------------------------------------------
	// trimActiveToDeviceLimit / revokeAllActiveAndPending
	// ---------------------------------------------------------------

	public function testTrimActiveToDeviceLimitNoopWhenUnderLimit(): void
	{
		$this->devices = [new TerminalDevice()];
		$this->assertSame(0, $this->service->trimActiveToDeviceLimit(5));
	}

	public function testTrimActiveToDeviceLimitRevokesNewest(): void
	{
		$linked = new TerminalDevice();
		$linked->setKioskTerminalId('term-9');
		$unlinked = new TerminalDevice();
		$this->devices = [new TerminalDevice(), $linked, $unlinked];

		$terminal = $this->terminal('term-9');
		$this->foundByTerminalId = $terminal;
		$this->deviceService->expects($this->once())->method('revokeDevice')->with($unlinked);

		// keep 1 of 3 -> revoke the last two (linked via kiosk path, unlinked directly)
		$revoked = $this->service->trimActiveToDeviceLimit(1);
		$this->assertSame(2, $revoked);
		$this->assertSame('revoked', $terminal->getStatus());
	}

	public function testRevokeAllActiveAndPending(): void
	{
		$a1 = $this->terminal('a-1');
		$a2 = $this->terminal('a-2');
		$p1 = $this->terminal('p-1', 'pending_pairing');
		$this->active = [$a1, $a2];
		$this->pending = [$p1];
		$this->devices = [new TerminalDevice()];

		// revoke() looks up each terminal id -> return the matching stub
		$byId = ['a-1' => $a1, 'a-2' => $a2];
		$this->mapper = $this->createMock(KioskTerminalMapper::class);
		$this->mapper->method('findByTerminalId')->willReturnCallback(static fn (string $id) => $byId[$id] ?? null);
		$this->mapper->method('findAllActive')->willReturnCallback(fn () => $this->active);
		$this->mapper->method('findPendingPairing')->willReturnCallback(fn () => $this->pending);
		$this->mapper->method('update')->willReturnCallback(function (KioskTerminal $t) { $this->updated[] = $t; return $t; });
		$this->service = new KioskTerminalService($this->mapper, $this->deviceService, $this->licenseService, $this->timeFactory, $this->lockingProvider);

		$this->deviceService->expects($this->once())->method('revokeDevice');

		$count = $this->service->revokeAllActiveAndPending();
		$this->assertSame(3, $count);
		$this->assertSame('revoked', $a1->getStatus());
		$this->assertSame('revoked', $a2->getStatus());
		$this->assertSame('revoked', $p1->getStatus());
	}
}
