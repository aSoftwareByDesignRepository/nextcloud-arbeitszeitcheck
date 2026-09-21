<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Contract: audit list exposes offline_sync meta + indexed filter (Version1046+).
 */
final class AuditOfflineSyncContractTest extends TestCase
{
	public function testFormatAuditLogEntryParsesOfflineMeta(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/AdminController.php');
		$this->assertStringContainsString('resolveOfflineSyncAuditMeta', $src);
		$this->assertStringContainsString('parseOfflineSyncAuditMeta', $src);
		$this->assertStringContainsString("'isOfflineSync'", $src);
		$this->assertStringContainsString("'captureSource'", $src);
		$this->assertStringContainsString('clientOccurredAtIso', $src);
		$this->assertStringContainsString('AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC', $src);
		$this->assertStringNotContainsString('clock_in_offline', $src);
	}

	public function testMapperSupportsOfflineSyncFilterOnIndexedColumn(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Db/AuditLogMapper.php');
		$this->assertStringContainsString('offline_sync', $src);
		$this->assertStringContainsString('capture_source', $src);
		$this->assertStringContainsString('AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC', $src);
		$this->assertStringContainsString('extractCaptureSource', $src);
		$this->assertStringNotContainsString('%"capture_source":"offline_sync"%', $src);
	}

	public function testMigrationAddsIndexedCaptureSource(): void
	{
		$path = __DIR__ . '/../../../lib/Migration/Version1046Date20260921120000.php';
		$this->assertFileExists($path);
		$src = (string)file_get_contents($path);
		$this->assertStringContainsString('at_audit_csrc_idx', $src);
		$this->assertStringContainsString('capture_source', $src);
	}

	public function testUiHasOfflineFilterAndBadgeHooks(): void
	{
		$tpl = (string)file_get_contents(__DIR__ . '/../../../templates/audit-log.php');
		$js = (string)file_get_contents(__DIR__ . '/../../../js/audit-log-viewer.js');
		$this->assertStringContainsString('id="offline-sync-filter"', $tpl);
		$this->assertStringContainsString('offlineSync', $js);
		$this->assertStringContainsString('Offline sync', $js);
	}
}
