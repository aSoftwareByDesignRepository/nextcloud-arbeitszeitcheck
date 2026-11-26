<?php

declare(strict_types=1);

/**
 * HR-notification-matrix transfer normalisation.
 *
 * TransferService::applyPayload runs applyConfigPlan (progress 40) BEFORE
 * applyTablePlan (progress 60), so a custom absence type imported by the
 * same package does not exist in the target catalog at config-apply time.
 * AbsenceTypeCatalog::normalizeEventMatrix drops unknown codes fail-closed —
 * without the preserve step, every custom_* matrix row silently vanished on
 * a first test→prod transfer. The send-side stays fail-closed for codes
 * that never materialize (isCreatable), so preserving input rows is safe.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\ConfigTransfer;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferHooks;
use OCA\ArbeitszeitCheck\ConfigTransfer\TransferManifest;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TransferHooksTest extends TestCase
{
	private function hooks(): TransferHooks
	{
		return new TransferHooks(
			$this->createMock(PermissionService::class),
			$this->createMock(UpgradeBackupService::class),
			$this->createMock(AuditLogMapper::class),
			new TransferManifest(),
			null,
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new TimeZoneService(
				$this->createMock(IConfig::class),
				$this->createMock(\OCP\IDateTimeZone::class),
				$this->createMock(\OCP\IUserSession::class),
				new \Psr\Log\NullLogger(),
			),
			$this->createMock(IDBConnection::class),
		);
	}

	/**
	 * A custom_* matrix row for a type that does not exist in the target
	 * catalog yet (imported by the same package, applied after the config
	 * step) must survive the normalize pass. Asserting the built-in row's
	 * bool coercion alongside proves the normalizer actually ran and the
	 * row was not passed through by the Throwable fallback.
	 */
	public function testNormalizeHrMatrixPreservesNotYetImportedCustomRows(): void
	{
		$input = json_encode([
			'vacation' => ['manager_approved' => '1'],
			'custom_transfer_only' => ['manager_approved' => true, 'request_created' => true],
		], JSON_THROW_ON_ERROR);

		$out = json_decode(
			$this->hooks()->normalizeValue('hr_notification_matrix_v1', $input),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertIsArray($out);
		$this->assertTrue($out['vacation']['manager_approved'], 'normalizer ran (string "1" coerced to bool true)');
		$this->assertArrayHasKey('custom_transfer_only', $out, 'custom_* row for a not-yet-imported type must survive the transfer');
		$this->assertTrue($out['custom_transfer_only']['manager_approved']);
		$this->assertTrue($out['custom_transfer_only']['request_created']);
		$this->assertFalse($out['custom_transfer_only']['manager_rejected'], 'unset events coerce to false, not dropped');
	}
}
