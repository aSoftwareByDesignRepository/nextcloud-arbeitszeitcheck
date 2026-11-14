<?php

declare(strict_types=1);

/**
 * Regression tests for {@see \OCA\ArbeitszeitCheck\Migration\Version1015Date20260415120000}.
 *
 * The migration converts legacy UTC-interpreted naive timestamps to
 * Europe/Berlin wall clock and is guarded by `tz_utc_to_berlin_migration_done`
 * so it must never run twice (re-running would mis-shift already-correct rows).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use DateTime;
use DateTimeZone;
use OCA\ArbeitszeitCheck\Migration\Version1015Date20260415120000;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1015TimezoneMigrationTest extends TestCase
{
	private const MIGRATION_DONE_FLAG = 'tz_utc_to_berlin_migration_done';

	private const APP_TIMEZONE_KEY = 'app_timezone';

	/**
	 * @return array{0: Version1015Date20260415120000, 1: \ReflectionMethod}
	 */
	private function conversionMethod(): array
	{
		$migration = new Version1015Date20260415120000(
			$this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class),
		);
		$method = new \ReflectionMethod(Version1015Date20260415120000::class, 'convertUtcStringToTimezone');

		return [$migration, $method];
	}

	public function testConvertUtcStringToBerlinWallClockWinter(): void
	{
		[$migration, $method] = $this->conversionMethod();
		$utc = new DateTimeZone('UTC');
		$berlin = new DateTimeZone('Europe/Berlin');

		$result = $method->invoke($migration, '2026-01-15 09:00:00', $utc, $berlin);
		$this->assertSame('2026-01-15 10:00:00', $result);
	}

	public function testConvertUtcStringToBerlinWallClockSummer(): void
	{
		[$migration, $method] = $this->conversionMethod();
		$utc = new DateTimeZone('UTC');
		$berlin = new DateTimeZone('Europe/Berlin');

		$result = $method->invoke($migration, '2026-07-15 09:00:00', $utc, $berlin);
		$this->assertSame('2026-07-15 11:00:00', $result);
	}

	public function testConvertUtcStringReturnsNullForInvalidInput(): void
	{
		[$migration, $method] = $this->conversionMethod();
		$utc = new DateTimeZone('UTC');
		$berlin = new DateTimeZone('Europe/Berlin');

		$this->assertNull($method->invoke($migration, 'not-a-datetime', $utc, $berlin));
		$this->assertNull($method->invoke($migration, '', $utc, $berlin));
	}

	/**
	 * Documents why the idempotency flag is mandatory: a Berlin wall-clock value
	 * must not be fed through the UTC→Berlin converter a second time.
	 */
	public function testSecondPassWouldMisShiftBerlinWallClockIfFlagWereIgnored(): void
	{
		[$migration, $method] = $this->conversionMethod();
		$utc = new DateTimeZone('UTC');
		$berlin = new DateTimeZone('Europe/Berlin');

		$first = $method->invoke($migration, '2026-01-15 09:00:00', $utc, $berlin);
		$this->assertSame('2026-01-15 10:00:00', $first);

		$misShifted = $method->invoke($migration, $first, $utc, $berlin);
		$this->assertSame('2026-01-15 11:00:00', $misShifted);
		$this->assertNotSame($first, $misShifted);
	}

	public function testPreSchemaChangeSkipsWhenMigrationAlreadyDone(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				if ($key === self::MIGRATION_DONE_FLAG) {
					return '1';
				}
				return $default;
			});
		$config->expects($this->never())->method('setAppValue');

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$migration = new Version1015Date20260415120000($db, $config);
		$output = $this->createMock(IOutput::class);

		$migration->preSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);
	}

	public function testPreSchemaChangeSetsFlagAndAppTimezoneOnFirstRun(): void
	{
		$setCalls = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = '') {
				if ($key === self::MIGRATION_DONE_FLAG) {
					return '0';
				}
				return $default;
			});
		$config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$setCalls): void {
				$setCalls[] = [$key, $value];
			});

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$migration = new Version1015Date20260415120000($db, $config);
		$output = $this->createMock(IOutput::class);

		$migration->preSchemaChange($output, fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class), []);

		$this->assertContains([self::APP_TIMEZONE_KEY, 'Europe/Berlin'], $setCalls);
		$this->assertContains([self::MIGRATION_DONE_FLAG, '1'], $setCalls);
	}
}
