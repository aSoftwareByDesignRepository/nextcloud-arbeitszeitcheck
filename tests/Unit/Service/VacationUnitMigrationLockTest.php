<?php

declare(strict_types=1);

/**
 * Vacation unit migration lock + post-commit unit flip (race / TOCTOU hardening).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Tests\Unit\Support\SchemaReadyDbMock;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

class VacationUnitMigrationLockTest extends TestCase
{
	use SchemaReadyDbMock;
	public function testLockedMigrationThrowsInProgress(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				return $default;
			}
		);
		$db = $this->createSchemaReadyDbMock();
		$db->expects($this->never())->method('beginTransaction');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->exactly(2)) // DbLockGuard retries once after stale-sweep
			->method('acquireLock')
			->with(DbLockKeys::vacationUnitMigration(), ILockingProvider::LOCK_EXCLUSIVE, $this->anything())
			->willThrowException(new LockedException(DbLockKeys::vacationUnitMigration()));
		$locking->expects($this->never())->method('releaseLock');

		$svc = new VacationUnitMigrationService(
			$config,
			$db,
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(AuditLogMapper::class),
			null,
			null,
			$locking,
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('VAC_UNIT_MIGRATE_IN_PROGRESS');
		$svc->migrate(Constants::VACATION_UNIT_HOURS, 8.0, true, 'admin');
	}

	public function testUnitConfigWrittenOnlyAfterSuccessfulCommit(): void
	{
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS => '',
		];
		$configWrittenWhileUncommitted = false;
		$committed = false;

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored, &$configWrittenWhileUncommitted, &$committed): void {
				$stored[$key] = $value;
				if (!$committed && (
					$key === Constants::CONFIG_VACATION_UNIT
					|| $key === Constants::CONFIG_VACATION_HOURS_PER_DAY
				)) {
					$configWrittenWhileUncommitted = true;
				}
			}
		);

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
		$db->expects($this->once())->method('commit')->willReturnCallback(
			static function () use (&$committed): void {
				$committed = true;
			}
		);
		$db->method('getQueryBuilder')->willReturn($qb);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('findByAction')->willReturn([]);
		$audit->expects($this->once())->method('logAction');

		$svc = new VacationUnitMigrationService(
			$config,
			$db,
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
			null,
			null,
			$locking,
		);

		$r = $svc->migrate(Constants::VACATION_UNIT_HOURS, 8.0, true, 'admin');
		$this->assertSame(Constants::VACATION_UNIT_HOURS, $r['unit']);
		$this->assertFalse($configWrittenWhileUncommitted, 'hours_per_day and vacation_unit must never flip before DB commit');
		$this->assertTrue($committed);
		$this->assertSame(Constants::VACATION_UNIT_HOURS, $stored[Constants::CONFIG_VACATION_UNIT]);
		$this->assertSame('8', $stored[Constants::CONFIG_VACATION_HOURS_PER_DAY]);
	}
}
