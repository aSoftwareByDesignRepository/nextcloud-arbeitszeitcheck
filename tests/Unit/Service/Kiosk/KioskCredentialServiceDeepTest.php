<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Kiosk\KioskCrypto;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskDbLockPurger;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deep coverage for KioskCredentialService::assignRfid and importCsv —
 * dedupe/idempotency, unique-constraint mapping, per-line import errors.
 */
final class KioskCredentialServiceDeepTest extends TestCase
{
	private KioskCredMapper&MockObject $credMapper;
	private KioskSettingsService&MockObject $settingsService;
	private IUserManager&MockObject $userManager;
	private AuditLogMapper&MockObject $auditLogMapper;
	private ITimeFactory&MockObject $timeFactory;
	private ILockingProvider&MockObject $lockingProvider;
	private KioskDbLockPurger&MockObject $lockPurger;
	private KioskCredentialService $service;

	private bool $kioskAllowed = true;
	private ?IUser $user = null;
	private ?KioskCred $foundByHash = null;
	private ?KioskCred $foundByUserType = null;
	/** @var list<KioskCred> */
	private array $inserted = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->credMapper = $this->createMock(KioskCredMapper::class);
		$this->settingsService = $this->createMock(KioskSettingsService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->lockPurger = $this->createMock(KioskDbLockPurger::class);

		$u = $this->createMock(IUser::class);
		$u->method('isEnabled')->willReturn(true);
		$this->user = $u;

		$this->settingsService->method('isUserKioskAllowed')->willReturnCallback(fn () => $this->kioskAllowed);
		$this->settingsService->method('rfidLookupHash')->willReturnCallback(
			static fn (string $uid) => 'lh-' . $uid
		);
		$this->userManager->method('get')->willReturnCallback(fn () => $this->user);
		$this->credMapper->method('findByLookupHash')->willReturnCallback(fn () => $this->foundByHash);
		$this->credMapper->method('findByUserAndType')->willReturnCallback(fn () => $this->foundByUserType);
		$this->credMapper->method('insert')->willReturnCallback(function (KioskCred $c) {
			$this->inserted[] = $c;
			$c->setId(99);
			return $c;
		});
		$this->credMapper->method('update')->willReturnArgument(0);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-02-10 12:00:00'));

		$this->service = new KioskCredentialService(
			$this->credMapper,
			$this->settingsService,
			$this->userManager,
			$this->auditLogMapper,
			$this->timeFactory,
			$this->lockingProvider,
			$this->lockPurger
		);
	}

	// ---------------------------------------------------------------
	// assignRfid
	// ---------------------------------------------------------------

	public function testAssignRfidRejectsDisallowedUser(): void
	{
		$this->kioskAllowed = false;
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_USER_NOT_ALLOWED');
		$this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
	}

	public function testAssignRfidRejectsMissingUser(): void
	{
		$this->user = null;
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_USER_NOT_ALLOWED');
		$this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
	}

	public function testAssignRfidRejectsTrivialUid(): void
	{
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_RFID_INVALID');
		$this->service->assignRfid('alice', 'AB', 'admin');
	}

	public function testAssignRfidIsIdempotentForSameEmployeeBadge(): void
	{
		$existing = new KioskCred();
		$existing->setId(7);
		$existing->setUserId('alice');
		$existing->setType('rfid');
		$this->foundByHash = $existing;

		$this->auditLogMapper->expects($this->never())->method('logAction');
		$out = $this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
		$this->assertSame(7, $out['id']);
		$this->assertSame('alice', $out['userId']);
	}

	public function testAssignRfidRejectsBadgeOwnedByOtherUser(): void
	{
		$existing = new KioskCred();
		$existing->setUserId('bob');
		$existing->setType('rfid');
		$this->foundByHash = $existing;

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_RFID_ALREADY_ASSIGNED');
		$this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
	}

	public function testAssignRfidInsertsNewCredentialWithAudit(): void
	{
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'kiosk_credential_assigned', 'kiosk_cred', 99);
		$this->lockingProvider->expects($this->once())->method('acquireLock');
		$this->lockingProvider->expects($this->once())->method('releaseLock');

		$out = $this->service->assignRfid('alice', 'A1B2C3D4', 'admin', 'Front door');
		$this->assertSame(99, $out['id']);
		$this->assertCount(1, $this->inserted);
		$this->assertSame('lh-A1B2C3D4', $this->inserted[0]->getLookupHash());
		$this->assertSame('Front door', $this->inserted[0]->getLabel());
		$this->assertNull($this->inserted[0]->getSecretHash());
	}

	public function testAssignRfidUpdatesExistingCredentialRow(): void
	{
		$cred = new KioskCred();
		$cred->setId(55);
		$cred->setUserId('alice');
		$cred->setType('rfid');
		$this->foundByUserType = $cred;

		$this->credMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (KioskCred $c): bool => $c->getLookupHash() === 'lh-A1B2C3D4'));
		$out = $this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
		$this->assertSame(55, $out['id']);
		$this->assertCount(0, $this->inserted);
	}

	public function testAssignRfidMapsUniqueViolationToKioskError(): void
	{
		$driverEx = new class extends \Exception implements \Doctrine\DBAL\Driver\Exception {
			public function getSQLState() { return '23000'; }
		};
		$uniqueViolation = new \Doctrine\DBAL\Exception\UniqueConstraintViolationException($driverEx, null);
		// service unwraps ->getPrevious(), mirroring the ORM wrapper shape
		$wrapped = new \RuntimeException('insert failed', 0, $uniqueViolation);

		$this->inserted = [];
		$this->credMapper = $this->createMock(KioskCredMapper::class);
		$this->credMapper->method('findByLookupHash')->willReturn(null);
		$this->credMapper->method('findByUserAndType')->willReturn(null);
		$this->credMapper->method('insert')->willThrowException($wrapped);
		$this->service = new KioskCredentialService(
			$this->credMapper, $this->settingsService, $this->userManager,
			$this->auditLogMapper, $this->timeFactory, $this->lockingProvider, $this->lockPurger
		);

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_RFID_ALREADY_ASSIGNED');
		$this->service->assignRfid('alice', 'A1B2C3D4', 'admin');
	}

	public function testAssignRfidSkipsAuditWhenWriteAuditFalse(): void
	{
		$this->auditLogMapper->expects($this->never())->method('logAction');
		$out = $this->service->assignRfid('alice', 'A1B2C3D4', 'admin', null, 'enroll-scan', false);
		$this->assertSame(99, $out['id']);
	}

	// ---------------------------------------------------------------
	// importCsv
	// ---------------------------------------------------------------

	public function testImportCsvRejectsOversizedPayload(): void
	{
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_IMPORT_TOO_LARGE');
		$this->service->importCsv(str_repeat('A', 1048577), 'admin');
	}

	public function testImportCsvImportsValidRowsAndSkipsBadOnes(): void
	{
		$csv = implode("\n", [
			'badge_uid,user_id,label',           // header — skipped
			'# comment line',
			'',
			'A1B2C3D4,alice,Front door',
			'B2C3D4E5,bob',
			'onlyonecolumn',
			'ZZ,carol',                          // too short -> KIOSK_RFID_INVALID
		]);

		$out = $this->service->importCsv($csv, 'admin');
		$this->assertSame(2, $out['imported']);
		$this->assertSame(2, $out['skipped']);
		$this->assertCount(2, $out['errors']);
		$this->assertStringContainsString('invalid format', $out['errors'][0]);
		$this->assertStringContainsString('KIOSK_RFID_INVALID', $out['errors'][1]);
	}

	public function testImportCsvCollectsPerLineDuplicateErrors(): void
	{
		$existing = new KioskCred();
		$existing->setUserId('bob'); // belongs to someone else
		$existing->setType('rfid');
		$this->foundByHash = $existing;

		$out = $this->service->importCsv("A1B2C3D4,alice\nA1B2C3D4,carol", 'admin');
		$this->assertSame(0, $out['imported']);
		$this->assertSame(2, $out['skipped']);
		$this->assertCount(2, $out['errors']);
	}

	// ---------------------------------------------------------------
	// revoke
	// ---------------------------------------------------------------

	public function testRevokeDeletesCredAndAudits(): void
	{
		$cred = new KioskCred();
		$cred->setId(7);
		$cred->setUserId('alice');
		$this->credMapper->method('findById')->with(7)->willReturn($cred);
		$this->credMapper->expects($this->once())->method('delete')->with($cred);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'kiosk_credential_revoked', 'kiosk_cred', 7, null, null, 'admin');

		$this->service->revoke(7, 'admin');
	}

	public function testRevokeThrowsWhenCredentialMissing(): void
	{
		$this->credMapper->method('findById')->willReturn(null);
		$this->credMapper->expects($this->never())->method('delete');
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_CREDENTIAL_NOT_FOUND');
		$this->service->revoke(404, 'admin');
	}
}