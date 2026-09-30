<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog;
use OCA\ArbeitszeitCheck\Repair\EnsureArbeitszeitCheckSchema;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class EnsureArbeitszeitCheckSchemaTest extends TestCase
{
	public function testSucceedsWhenAllTablesExist(): void
	{
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new EnsureArbeitszeitCheckSchema($connection, $config);
		$step->run($output);
		$catalog = ArbeitszeitCheckTableCatalog::TABLES;
		self::assertContains('azc_license_state', $catalog);
		self::assertContains('at_kiosk_terminals', $catalog);
		// stamp idempotency tables must be in the ensure-catalog too —
		// a regression here makes the repair step blind to missing tables.
		self::assertContains('at_mob_stamp_idem', $catalog);
		self::assertContains('at_kiosk_stamp_idem', $catalog);
		self::assertGreaterThanOrEqual(25, count($catalog));
		self::assertTrue(ArbeitszeitCheckTableCatalog::isLegacyDroppedTable('at_absence_calendar'));
	}

	public function testThrowsWhenTablesStayMissing(): void
	{
		// at_audit reports missing; migrate('latest') is a no-op on an
		// already-applied schema, so the table stays missing -> RuntimeException.
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')
			->willReturnCallback(static fn (string $t): bool => $t !== 'at_audit');
		$config = $this->createMock(IConfig::class);
		$config->method('deleteAppValue');
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step = new EnsureArbeitszeitCheckSchema($connection, $config);
		self::expectException(\RuntimeException::class);
		self::expectExceptionMessageMatches('/still incomplete/');
		$step->run($output);
	}
}
