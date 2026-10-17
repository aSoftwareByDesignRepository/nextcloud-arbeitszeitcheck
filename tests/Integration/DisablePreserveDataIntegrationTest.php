<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Verifies disable preserves DB tables; re-enable via installApp keeps data intact.
 */
final class DisablePreserveDataIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		if (!$appManager->isEnabledForUser(UninstallDropTables::APP_ID)) {
			$installer = \OCP\Server::get(\OC\Installer::class);
			$installer->installApp(UninstallDropTables::APP_ID);
			$appManager->enableApp(UninstallDropTables::APP_ID);
		}
	}

	public function testDisableThenReEnablePreservesCoreTable(): void
	{
		/** @var IDBConnection $db */
		$db = \OCP\Server::get(IDBConnection::class);
		$coreTable = $this->resolveExistingTable($db);
		self::assertTrue($db->tableExists($coreTable), 'fixture: core table must exist before disable');

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$appManager->disableApp(UninstallDropTables::APP_ID);

		self::assertTrue(
			$db->tableExists($coreTable),
			'disable must not drop app tables (auto-disable / manual disable)',
		);

		$installer = \OCP\Server::get(\OC\Installer::class);
		$installer->installApp(UninstallDropTables::APP_ID);
		$appManager->enableApp(UninstallDropTables::APP_ID);

		self::assertTrue(
			$db->tableExists($coreTable),
			're-enable after disable must keep existing tables',
		);
	}

	public function testAutoDisablePreservesTables(): void
	{
		/** @var IDBConnection $db */
		$db = \OCP\Server::get(IDBConnection::class);
		$coreTable = $this->resolveExistingTable($db);
		self::assertTrue($db->tableExists($coreTable), 'fixture: core table must exist before auto-disable');

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$appManager->disableApp(UninstallDropTables::APP_ID, true);
		self::assertTrue(
			$db->tableExists($coreTable),
			'auto-disable during server upgrade must preserve tables',
		);

		$installer = \OCP\Server::get(\OC\Installer::class);
		$installer->installApp(UninstallDropTables::APP_ID);
		$appManager->enableApp(UninstallDropTables::APP_ID);
	}

	public function testDoubleDisablePreservesTables(): void
	{
		/** @var IDBConnection $db */
		$db = \OCP\Server::get(IDBConnection::class);
		$coreTable = $this->resolveExistingTable($db);
		self::assertTrue($db->tableExists($coreTable), 'fixture: core table must exist before double disable');

		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		$appManager->disableApp(UninstallDropTables::APP_ID);
		self::assertTrue($db->tableExists($coreTable), 'first disable must preserve tables');

		// Nextcloud runs uninstall repair on every disableApp() call (no enabled guard).
		$appManager->disableApp(UninstallDropTables::APP_ID);
		self::assertTrue(
			$db->tableExists($coreTable),
			'second disable must not drop tables (upgrade / repeated disable scenario)',
		);

		$installer = \OCP\Server::get(\OC\Installer::class);
		$installer->installApp(UninstallDropTables::APP_ID);
		$appManager->enableApp(UninstallDropTables::APP_ID);
	}

	public function testUninstallRepairStepResolvesFromContainer(): void
	{
		/** @var UninstallDropTables $step */
		$step = \OCP\Server::get(UninstallDropTables::class);
		self::assertInstanceOf(UninstallDropTables::class, $step);

		$output = $this->createMock(IOutput::class);
		$output->method('info');
		$step->run($output);
		$this->addToAssertionCount(1);
	}

	protected function tearDown(): void
	{
		/** @var IAppManager $appManager */
		$appManager = \OCP\Server::get(IAppManager::class);
		if (!$appManager->isEnabledForUser(UninstallDropTables::APP_ID)) {
			$installer = \OCP\Server::get(\OC\Installer::class);
			$installer->installApp(UninstallDropTables::APP_ID);
			$appManager->enableApp(UninstallDropTables::APP_ID);
		}
		parent::tearDown();
	}

	private function resolveExistingTable(IDBConnection $db): string
	{
		foreach (UninstallDropTables::TABLES as $table) {
			if ($db->tableExists($table)) {
				return $table;
			}
		}

		self::fail('No uninstall-catalog table exists before disable');
	}
}
