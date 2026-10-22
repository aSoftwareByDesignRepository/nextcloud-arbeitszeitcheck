<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Contract: logAction promotes offline_sync onto indexed capture_source;
 * Offline-Sync list filter uses equality on the column (not LIKE on JSON).
 */
class AuditLogCaptureSourceTest extends TestCase
{
	public function testExtractCaptureSourcePromotesOfflineSync(): void
	{
		$mapper = new AuditLogMapper($this->createMock(IDBConnection::class));
		$method = new ReflectionMethod(AuditLogMapper::class, 'extractCaptureSource');
		$method->setAccessible(true);

		$this->assertSame(
			Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC,
			$method->invoke($mapper, ['capture_source' => 'offline_sync', 'client_occurred_at' => '2026-09-21T10:00:00+02:00'])
		);
		$this->assertNull($method->invoke($mapper, ['capture_source' => 'live']));
		$this->assertNull($method->invoke($mapper, null));
		$this->assertNull($method->invoke($mapper, []));
	}

	public function testBuildDateRangeQueryUsesIndexedEqualityNotLike(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Db/AuditLogMapper.php');
		$this->assertStringContainsString("'capture_source'", $src);
		$this->assertStringContainsString('AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC', $src);
		$this->assertStringContainsString("eq(", $src);
		$this->assertStringNotContainsString('%"capture_source":"offline_sync"%', $src);
		// Offline filter must not LIKE-scan new_values JSON anymore.
		$this->assertDoesNotMatchRegularExpression(
			'/offline_sync[^\n]*\n[^\n]*like\(\s*[\'"]new_values/i',
			$src
		);
	}

	public function testEntityDeclaresCaptureSource(): void
	{
		$log = new AuditLog();
		$log->setCaptureSource(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC);
		$this->assertSame(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC, $log->getCaptureSource());
	}
}
