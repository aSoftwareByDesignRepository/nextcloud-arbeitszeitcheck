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
}
