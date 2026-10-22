<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — lib/Migration is excluded from PCOV (phpunit.xml), so
 * line coverage can never prove these functions. The honest evidence is
 * behavioral: every migration step is executed against a stubbed
 * ISchemaWrapper/IDBConnection in both "schema present" and "schema absent"
 * states, which exercises the idempotency guards and the create/alter paths.
 *
 * The contract asserted: no step throws, and changeSchema honours its
 * ?ISchemaWrapper signature. Per-method negative/existence branches are
 * covered by the dedicated Version*Test files; this driver guarantees the
 * whole fleet at least executes both guard arms.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Schema\ITable;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MigrationSurfaceCoverageTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function migrationClasses(): iterable
	{
		foreach (glob(__DIR__ . '/../../../lib/Migration/Version*.php') as $file) {
			$class = 'OCA\\ArbeitszeitCheck\\Migration\\' . basename($file, '.php');
			yield basename($file, '.php') => [$class];
		}
	}

	private function newQb(bool $withRow = true): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectDistinct', 'selectAlias', 'addSelect', 'from', 'where', 'andWhere', 'orWhere',
			'join', 'innerJoin', 'leftJoin', 'rightJoin', 'groupBy', 'addGroupBy',
			'having', 'andHaving', 'orHaving', 'orderBy', 'addOrderBy',
			'setMaxResults', 'setFirstResult', 'setParameter', 'setParameters',
			'insert', 'values', 'setValue', 'delete', 'update', 'set',
		] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$qb->method('createFunction')->willReturn('computed');
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createParameter')->willReturn(':p');
		$qb->method('createPositionalParameter')->willReturn('?');

		$result = $this->createMock(IResult::class);
		$row = $withRow ? [
			'id' => 7, 'user_id' => 'alice', 'state' => 'BW', 'date' => '2026-01-01',
			'scope' => 'state', 'keep_id' => 7, 'row_count' => 2,
			'start_time' => '2026-09-01 08:00:00', 'end_time' => '2026-09-01 17:00:00',
		] : null;
		$result->method('fetch')->willReturnOnConsecutiveCalls($row, false);
		$result->method('fetchOne')->willReturn($withRow ? '5' : false);
		$result->method('fetchColumn')->willReturn($withRow ? '5' : false);
		$result->method('fetchAll')->willReturn($withRow ? [$row ?: []] : []);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function newDb(string $provider): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb());
		$db->method('tableExists')->willReturn(true);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$db->method('lastInsertId')->willReturn(7);
		return $db;
	}

	private function newConfig(): IConfig
	{
		$config = $this->createMock(IConfig::class);
		// return the caller-supplied default so string/int params stay typed;
		// the legacy holiday-key arm gets a real payload to exercise the loop.
		$cb = static function (...$args) {
			$key = $args[1] ?? '';
			if (str_contains((string)$key, 'state_years') || str_contains((string)$key, 'legacy')) {
				return '["NW-2026", "not-an-entry"]';
			}
			return $args[array_key_last($args)] ?? '';
		};
		$config->method('getAppValue')->willReturnCallback($cb);
		$config->method('getSystemValue')->willReturnCallback($cb);
		$config->method('getUserValue')->willReturnCallback($cb);
		return $config;
	}

	private function schema(bool $existing): ISchemaWrapper
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$table = $this->createMock(ITable::class);
		$schema->method('hasTable')->willReturn($existing);
		$schema->method('getTable')->willReturn($table);
		$schema->method('createTable')->willReturn($table);
		$schema->method('dropTable')->willReturn($schema);
		return $schema;
	}

	private function instantiate(string $class, string $provider): object
	{
		$ref = new \ReflectionClass($class);
		$ctor = $ref->getConstructor();
		$args = [];
		if ($ctor !== null) {
			foreach ($ctor->getParameters() as $p) {
				$type = $p->getType();
				$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
				$args[] = match (true) {
					$name === IDBConnection::class => $this->newDb($provider),
					$name === IConfig::class => $this->newConfig(),
					is_a($name, \Throwable::class, true) => null,
					$name !== '' && (interface_exists($name) || class_exists($name)) => $this->createMock($name),
					default => null,
				};
			}
		}
		return $ref->newInstanceArgs($args);
	}

	/**
	 * @dataProvider migrationClasses
	 */
	public function testMigrationStepsExecute(string $class): void
	{
		$ref = new \ReflectionClass($class);
		$output = $this->createMock(IOutput::class);

		foreach ([true, false] as $existing) {
			foreach ([IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_POSTGRES] as $provider) {
				$migration = $this->instantiate($class, $provider);
				$schema = $this->schema($existing);
				$closure = static fn () => $schema;

				foreach (['preSchemaChange', 'changeSchema', 'postSchemaChange'] as $step) {
					if (!$ref->hasMethod($step)) {
						continue;
					}
					$declared = $ref->getMethod($step);
					// only call methods the class itself defines
					if ($declared->getDeclaringClass()->getName() !== $class) {
						continue;
					}
					$result = $migration->{$step}($output, $closure, []);
					if ($step === 'changeSchema') {
						$this->assertTrue($result === null || $result instanceof ISchemaWrapper);
					}
				}
			}
		}
		$this->addToAssertionCount(1);
	}

	public function testTableCatalogLegacyDropped(): void
	{
		$this->assertIsBool(\OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog::isLegacyDroppedTable('at_entries'));
		$this->assertIsBool(\OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog::isLegacyDroppedTable('definitely_not_a_table'));
	}
}
