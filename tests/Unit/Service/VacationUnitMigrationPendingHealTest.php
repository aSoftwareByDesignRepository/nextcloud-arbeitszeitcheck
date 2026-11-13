<?php

declare(strict_types=1);

/**
 * Crash-window heal: DB committed hour magnitudes must not stay labeled as days.
 * Heal must not clear pending while migrate holds exclusive, and must not match
 * a stale prior audit for a later flip attempt.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

class VacationUnitMigrationPendingHealTest extends TestCase
{
	/**
	 * @param array<string, string> $stored
	 */
	private function mockConfig(array &$stored): IConfig
	{
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

		return $config;
	}

	public function testCompletePendingFlipsUnitWhenAuditCommittedWithToken(): void
	{
		$token = 'abc123def456';
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS => '',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING => json_encode([
				'from' => Constants::VACATION_UNIT_DAYS,
				'target' => Constants::VACATION_UNIT_HOURS,
				'hours_per_day' => 8.0,
				'scale_factor' => 8.0,
				'client_confirmed' => true,
				'actor' => 'admin',
				'started_at' => (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM),
				'token' => $token,
			], JSON_THROW_ON_ERROR),
		];

		$log = new AuditLog();
		$log->setAction('vacation_unit_migrated');
		$log->setOldValues(json_encode(['vacation_unit' => Constants::VACATION_UNIT_DAYS], JSON_THROW_ON_ERROR));
		$log->setNewValues(json_encode([
			'vacation_unit' => Constants::VACATION_UNIT_HOURS,
			'scale_factor' => 8.0,
			'migration_token' => $token,
		], JSON_THROW_ON_ERROR));
		$log->setCreatedAt(new \DateTime('now'));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('findByAction')->with('vacation_unit_migrated', 10)->willReturn([$log]);

		$config = $this->mockConfig($stored);
		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
		);

		$this->assertTrue($svc->completePendingMigrationIfNeeded());
		$this->assertSame(Constants::VACATION_UNIT_HOURS, $stored[Constants::CONFIG_VACATION_UNIT]);
		$this->assertSame('', $stored[Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING]);
		$this->assertSame('1', $stored[Constants::CONFIG_VACATION_UNIT_CLIENT_CONFIRMED]);
	}

	public function testStalePendingWithoutAuditIsClearedWithoutFlip(): void
	{
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING => json_encode([
				'from' => Constants::VACATION_UNIT_DAYS,
				'target' => Constants::VACATION_UNIT_HOURS,
				'hours_per_day' => 8.0,
				'scale_factor' => 8.0,
				'client_confirmed' => true,
				'actor' => 'admin',
				'started_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
				'token' => 'orphan-token',
			], JSON_THROW_ON_ERROR),
		];

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('findByAction')->willReturn([]);

		$config = $this->mockConfig($stored);
		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
		);

		$this->assertFalse($svc->completePendingMigrationIfNeeded());
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $stored[Constants::CONFIG_VACATION_UNIT]);
		$this->assertSame('', $stored[Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING]);
	}

	public function testHealDoesNotClearPendingWhenExclusiveLockHeldByMigrate(): void
	{
		$pendingJson = json_encode([
			'from' => Constants::VACATION_UNIT_DAYS,
			'target' => Constants::VACATION_UNIT_HOURS,
			'hours_per_day' => 8.0,
			'scale_factor' => 8.0,
			'client_confirmed' => true,
			'actor' => 'admin',
			'started_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
			'token' => 'in-flight',
		], JSON_THROW_ON_ERROR);
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING => $pendingJson,
		];

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->exactly(2)) // DbLockGuard retries once after stale-sweep
			->method('acquireLock')
			->with(DbLockKeys::vacationUnitMigration(), ILockingProvider::LOCK_EXCLUSIVE, $this->anything())
			->willThrowException(new LockedException(DbLockKeys::vacationUnitMigration()));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('findByAction');

		$config = $this->mockConfig($stored);
		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
			null,
			null,
			$locking,
		);

		$this->assertFalse($svc->completePendingMigrationIfNeeded());
		$this->assertSame($pendingJson, $stored[Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING]);
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $stored[Constants::CONFIG_VACATION_UNIT]);
	}

	public function testStalePriorAuditDoesNotMatchNewTokenPending(): void
	{
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS => '',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING => json_encode([
				'from' => Constants::VACATION_UNIT_DAYS,
				'target' => Constants::VACATION_UNIT_HOURS,
				'hours_per_day' => 7.7,
				'scale_factor' => 7.7,
				'client_confirmed' => true,
				'actor' => 'admin',
				'started_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
				'token' => 'new-attempt-token',
			], JSON_THROW_ON_ERROR),
		];

		// Prior successful days→hours audit (different token / factor).
		$stale = new AuditLog();
		$stale->setAction('vacation_unit_migrated');
		$stale->setOldValues(json_encode(['vacation_unit' => Constants::VACATION_UNIT_DAYS], JSON_THROW_ON_ERROR));
		$stale->setNewValues(json_encode([
			'vacation_unit' => Constants::VACATION_UNIT_HOURS,
			'scale_factor' => 8.0,
			'migration_token' => 'old-attempt-token',
		], JSON_THROW_ON_ERROR));
		$stale->setCreatedAt(new \DateTime('-1 day'));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('findByAction')->willReturn([$stale]);

		$config = $this->mockConfig($stored);
		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
		);

		// No matching token → treat as uncommitted → clear pending, do not flip.
		$this->assertFalse($svc->completePendingMigrationIfNeeded());
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $stored[Constants::CONFIG_VACATION_UNIT]);
		$this->assertSame('', $stored[Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING]);
	}

	public function testLegacyPendingRequiresParseableStartedAtNotUnitFlipAlone(): void
	{
		$stored = [
			Constants::CONFIG_VACATION_UNIT => Constants::VACATION_UNIT_DAYS,
			Constants::CONFIG_VACATION_HOURS_PER_DAY => '8',
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS => '',
			// Legacy pending: no token, unparseable started_at — must NOT match stale audit.
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING => json_encode([
				'from' => Constants::VACATION_UNIT_DAYS,
				'target' => Constants::VACATION_UNIT_HOURS,
				'hours_per_day' => 8.0,
				'scale_factor' => 8.0,
				'client_confirmed' => true,
				'actor' => 'admin',
				'started_at' => 'not-a-date',
			], JSON_THROW_ON_ERROR),
		];

		$stale = new AuditLog();
		$stale->setAction('vacation_unit_migrated');
		$stale->setOldValues(json_encode(['vacation_unit' => Constants::VACATION_UNIT_DAYS], JSON_THROW_ON_ERROR));
		$stale->setNewValues(json_encode([
			'vacation_unit' => Constants::VACATION_UNIT_HOURS,
			'scale_factor' => 8.0,
		], JSON_THROW_ON_ERROR));
		$stale->setCreatedAt(new \DateTime('now'));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('findByAction')->willReturn([$stale]);

		$config = $this->mockConfig($stored);
		$svc = new VacationUnitMigrationService(
			$config,
			$this->createMock(IDBConnection::class),
			new VacationUnitService($config),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$audit,
		);

		$this->assertFalse($svc->completePendingMigrationIfNeeded());
		$this->assertSame(Constants::VACATION_UNIT_DAYS, $stored[Constants::CONFIG_VACATION_UNIT]);
		$this->assertSame('', $stored[Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING]);
	}
}
