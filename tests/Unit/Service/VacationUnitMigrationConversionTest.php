<?php

declare(strict_types=1);

/**
 * Conversion integrity for days ↔ hours (Bestandskunden / Q3=A).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Tests\Unit\Support\SchemaReadyDbMock;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class VacationUnitMigrationConversionTest extends TestCase
{
	use SchemaReadyDbMock;
	public function testOrgDefaultMapperIsNeverUpdatedDuringMigrate(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				if ($key === Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS) {
					return '5';
				}
				return $default;
			}
		);
		$config->expects($this->atLeastOnce())->method('setAppValue');

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNotNull')->willReturn('nn');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(0);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->method('getQueryBuilder')->willReturn($qb);

		$orgMapper = $this->createMock(OrgVacationDefaultMapper::class);
		$orgMapper->expects($this->never())->method('findActiveByDate');
		$orgMapper->expects($this->never())->method('update');

		$balances = $this->createMock(VacationYearBalanceMapper::class);
		$balances->expects($this->never())->method('upsert');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction');

		$svc = new VacationUnitMigrationService(
			$config,
			$db,
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$balances,
			$audit,
			$orgMapper,
		);

		$r = $svc->migrate(Constants::VACATION_UNIT_HOURS, 8.0, true, 'admin');
		$this->assertSame(Constants::VACATION_UNIT_HOURS, $r['unit']);
		$this->assertSame(8.0, $r['hours_per_day']);
	}

	public function testCarryoverMaxConfigRescalesWithFactor(): void
	{
		$stored = ['vacation_carryover_max_days' => '5'];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);

		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(AuditLogMapper::class),
		);

		$ref = new \ReflectionClass($svc);
		$method = $ref->getMethod('rescaleCarryoverMaxConfig');
		$method->setAccessible(true);
		$method->invoke($svc, 8.0, true);
		$this->assertSame('40', $stored['vacation_carryover_max_days']);
		$method->invoke($svc, 8.0, false);
		$this->assertSame('5', $stored['vacation_carryover_max_days']);
	}

	public function testHoursCeilingAllowsFourHundredHourEntitlement(): void
	{
		$r = VacationProrationService::computeProration(
			2026,
			400.0,
			null,
			null,
			Constants::VACATION_PRORATION_METHOD_TWELFTHS,
			4000.0,
			8.0,
		);
		$this->assertSame(400.0, $r['days']);
		$this->assertFalse($r['prorated']);
	}

	public function testHoursModeStatutoryRoundingUsesHalfDayInHours(): void
	{
		// 200h × 8/12 = 133.333… → day-equivalent 16.666 days → round up to 17 days × 8 = 136h
		$r = VacationProrationService::computeProration(
			2026,
			200.0,
			new \DateTimeImmutable('2026-05-01'),
			null,
			Constants::VACATION_PRORATION_METHOD_TWELFTHS,
			4000.0,
			8.0,
		);
		$this->assertSame(136.0, $r['days']);
		$this->assertTrue($r['prorated']);
	}

	public function testDaysModeStatutoryRoundingUnchanged(): void
	{
		// 30 × 8/12 = 20 exact — no rounding change
		$r = VacationProrationService::computeProration(
			2026,
			30.0,
			new \DateTimeImmutable('2026-05-01'),
			null,
			Constants::VACATION_PRORATION_METHOD_TWELFTHS,
			366.0,
			1.0,
		);
		$this->assertSame(20.0, $r['days']);
	}

	public function testConvertAmountRoundTripHalfDay(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$s = new VacationUnitService($config);
		$this->assertSame(4.0, $s->daysToHours(0.5));
		$this->assertSame(0.5, $s->hoursToDays(4.0));
	}

	public function testSameUnitMigrateUpdatesHoursPerDayOnly(): void
	{
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_HOURS,
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);

		$db = $this->createSchemaReadyDbMock();
		$db->expects($this->never())->method('beginTransaction');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction');

		$svc = new VacationUnitMigrationService(
			$config,
			$db,
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
		);

		$r = $svc->migrate(Constants::VACATION_UNIT_HOURS, 7.5, true, 'admin');
		$this->assertSame(Constants::VACATION_UNIT_HOURS, $r['unit']);
		$this->assertSame(7.5, $r['hours_per_day']);
		$this->assertSame(0, $r['converted_absences']);
		$this->assertSame('7.5', $stored[Constants::CONFIG_VACATION_HOURS_PER_DAY]);
	}

	private function qbHarness(array $rows, ?array &$updates = null): \OCP\IDBConnection
	{
		$result = $this->createMock(\OCP\DB\IResult::class);
		$fetchQueue = array_merge($rows, [false]);
		$result->method('fetch')->willReturnCallback(static function () use (&$fetchQueue) {
			return array_shift($fetchQueue);
		});
		$result->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNotNull')->willReturn('nn');

		$updates = [];
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'update', 'set'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(static function () use (&$updates) {
			$updates[] = true;
			return 1;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function migrationServiceWith(IDBConnection $db, ?\OCP\IConfig $config = null, ?\OCP\Lock\ILockingProvider $locking = null): VacationUnitMigrationService
	{
		return new VacationUnitMigrationService(
			$config ?? $this->createMock(\OCP\IConfig::class),
			$db,
			new VacationUnitService($config ?? $this->createMock(\OCP\IConfig::class)),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			null,
			null,
			$locking,
		);
	}

	public function testAssertIdleThrowsWhenPendingFlagSet(): void
	{
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') =>
				$key === Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING ? '{"target":"hours"}' : $default
		);
		$svc = $this->migrationServiceWith($this->createMock(IDBConnection::class), $config);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS);
		$svc->assertIdle();
	}

	public function testAssertIdleThrowsOnLockedMigration(): void
	{
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(
			new \OCP\Lock\LockedException('held')
		);
		$svc = $this->migrationServiceWith($this->createMock(IDBConnection::class), $config, $locking);
		try {
			$svc->assertIdle();
			$this->fail('expected RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertSame(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS, $e->getMessage());
		}
	}

	public function testAssertIdlePassesWhenClean(): void
	{
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');
		$svc = $this->migrationServiceWith($this->createMock(IDBConnection::class), $config, $locking);
		$svc->assertIdle(); // no throw
		$this->addToAssertionCount(1);
	}

	public function testRescaleUserSettingsVacationDaysMultipliesToHours(): void
	{
		$updates = [];
		$db = $this->qbHarness([
			['id' => 1, 'setting_value' => '30'],
			['id' => 2, 'setting_value' => ''],
			['id' => 3, 'setting_value' => 'abc,5'],
		], $updates);
		$svc = $this->migrationServiceWith($db);
		$m = new \ReflectionMethod(VacationUnitMigrationService::class, 'rescaleUserSettingsVacationDays');
		$m->setAccessible(true);
		$m->invoke($svc, 8.0, true);
		// only the valid non-empty numeric row produced an UPDATE (garbage skipped, not zeroed)
		$this->assertCount(1, $updates);
	}

	public function testRescaleTariffRuleModuleDayAmounts(): void
	{
		$updates = [];
		$db = $this->qbHarness([
			['id' => 1, 'module_type' => 'base_formula', 'config_json' => '{"reference_days":30}'],
			['id' => 2, 'module_type' => 'additional_entitlements', 'config_json' => '{"days":2}'],
			['id' => 3, 'module_type' => 'unknown_type', 'config_json' => '{"days":9}'],
			['id' => 4, 'module_type' => 'deductions', 'config_json' => 'not-json'],
		], $updates);
		$svc = $this->migrationServiceWith($db);
		$m = new \ReflectionMethod(VacationUnitMigrationService::class, 'rescaleTariffRuleModuleDayAmounts');
		$m->setAccessible(true);
		$m->invoke($svc, 8.0, true);
		// base_formula + additional_entitlements updated; unknown type + bad JSON skipped
		$this->assertCount(2, $updates);
	}
}