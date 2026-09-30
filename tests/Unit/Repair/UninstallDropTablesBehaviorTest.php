<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UninstallDropTablesBehaviorTest extends TestCase
{
	private IDBConnection&MockObject $connection;
	private IConfig&MockObject $config;
	private IRootFolder&MockObject $rootFolder;
	private IOutput&MockObject $output;

	protected function setUp(): void
	{
		parent::setUp();
		$this->connection = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->output = $this->createMock(IOutput::class);
	}

	public function testDisablePathPreservesDataAndClearsLegacyPassKey(): void
	{
		$this->config->expects(self::once())
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$this->connection->expects(self::never())->method('executeStatement');
		$this->config->expects(self::never())->method('deleteAppValues');

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$step->run($this->output);
	}

	public function testDoubleDisableIsIdempotent(): void
	{
		$this->config->expects(self::exactly(2))
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$this->connection->expects(self::never())->method('executeStatement');
		$this->config->expects(self::never())->method('deleteAppValues');

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$step->run($this->output);
		$step->run($this->output);
	}

	public function testDisableClearsStaleLegacyPassCounterWithoutDropping(): void
	{
		// Upgrades from the old two-pass implementation may leave uninstall_repair_pass = 1.
		$this->config->method('getAppValue')->willReturn('1');
		$this->config->expects(self::once())
			->method('deleteAppValue')
			->with(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$this->connection->expects(self::never())->method('executeStatement');
		$this->config->expects(self::never())->method('deleteAppValues');

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$step->run($this->output);
	}

	public function testDropAllTablesAndClearsMetadata(): void
	{
		$this->config->expects(self::never())->method('deleteAppValue');

		$this->connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->connection->method('tableExists')->willReturn(false);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('app = :app');
		$qb->method('createNamedParameter')->willReturn(UninstallDropTables::APP_ID);
		$qb->expects(self::once())->method('executeStatement')->willReturn(1);
		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$this->config->expects(self::once())
			->method('deleteAppValues')
			->with(UninstallDropTables::APP_ID);

		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $key === 'instanceid' ? '' : $default,
		);
		$this->rootFolder->expects(self::never())->method('get');

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$method = (new ReflectionClass(UninstallDropTables::class))->getMethod('dropAllTablesAndMetadata');
		$method->setAccessible(true);
		$method->invoke($step, $this->output);
	}

	public function testDropRemovesUpgradeBackupSnapshotsFromAppData(): void
	{
		$this->connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->connection->method('tableExists')->willReturn(false);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('app = :app');
		$qb->method('createNamedParameter')->willReturn(UninstallDropTables::APP_ID);
		$qb->method('executeStatement')->willReturn(0);
		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $key === 'instanceid' ? 'inst42' : $default,
		);

		$folder = $this->createMock(\OCP\Files\Folder::class);
		$folder->expects(self::once())->method('delete');
		$this->rootFolder->expects(self::once())->method('get')
			->with('appdata_inst42/' . UninstallDropTables::APP_ID . '/upgrade-backups')
			->willReturn($folder);
		$this->output->expects(self::atLeastOnce())->method('info');

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$method = (new ReflectionClass(UninstallDropTables::class))->getMethod('dropAllTablesAndMetadata');
		$method->setAccessible(true);
		$method->invoke($step, $this->output);
	}

	public function testDropSkipsSnapshotPurgeWhenFolderMissing(): void
	{
		$this->connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$this->connection->method('tableExists')->willReturn(false);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('app = :app');
		$qb->method('createNamedParameter')->willReturn(UninstallDropTables::APP_ID);
		$qb->method('executeStatement')->willReturn(0);
		$this->connection->method('getQueryBuilder')->willReturn($qb);

		$this->config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $key === 'instanceid' ? 'inst42' : $default,
		);
		$this->rootFolder->method('get')
			->willThrowException(new \OCP\Files\NotFoundException('gone'));
		$this->output->expects(self::atLeastOnce())->method('info'); // drop ran to completion anyway

		$step = new UninstallDropTables($this->connection, $this->config, $this->rootFolder);
		$method = (new ReflectionClass(UninstallDropTables::class))->getMethod('dropAllTablesAndMetadata');
		$method->setAccessible(true);
		$method->invoke($step, $this->output);
	}

	/**
	 * dropLogicalTableIfExists has a provider-specific DROP arm per DB
	 * (MySQL backticks / Postgres CASCADE / Oracle CASCADE CONSTRAINTS /
	 * SQLite quoted IF EXISTS) plus the MySQL FK-check toggle around it.
	 * @dataProvider dropProviders
	 */
	public function testDropAllTablesExecutesProviderSql(string $provider): void
	{
		$connection = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$output = $this->createMock(IOutput::class);

		$connection->method('getDatabaseProvider')->willReturn($provider);
		$connection->method('tableExists')->willReturn(true);

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$expr->method('eq')->willReturn('app = :app');
		$qb->method('createNamedParameter')->willReturn(UninstallDropTables::APP_ID);
		$qb->method('executeStatement')->willReturn(0);
		$connection->method('getQueryBuilder')->willReturn($qb);

		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => $default,
		);
		$config->method('getAppValue')->willReturn('0');
		$rootFolder->method('get')->willThrowException(new \OCP\Files\NotFoundException('gone'));

		// every provider arm emits at least one DROP per table row
		$connection->expects(self::atLeastOnce())->method('executeStatement');

		$step = new UninstallDropTables($connection, $config, $rootFolder);
		$method = (new ReflectionClass(UninstallDropTables::class))->getMethod('dropAllTablesAndMetadata');
		$method->setAccessible(true);
		$method->invoke($step, $output);
	}

	/** @return iterable<string, array{string}> */
	public static function dropProviders(): iterable
	{
		yield 'mysql' => [IDBConnection::PLATFORM_MYSQL];
		yield 'pgsql' => [IDBConnection::PLATFORM_POSTGRES];
		yield 'oracle' => [IDBConnection::PLATFORM_ORACLE];
		yield 'sqlite' => [IDBConnection::PLATFORM_SQLITE];
	}
}
