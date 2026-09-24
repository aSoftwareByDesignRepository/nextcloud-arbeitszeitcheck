<?php

declare(strict_types=1);

/**
 * Integration checks for the post-upgrade timezone migration state.
 *
 * These tests do not re-run {@see Version1015Date20260415120000} (that would
 * be unsafe on a shared dev database). They verify that a normal Nextcloud
 * install has the expected config markers and that {@see TimeZoneService}
 * honours the storage/display contract end-to-end.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use Test\TestCase;

class TimezoneMigrationStateIntegrationTest extends TestCase
{
	public function testMigrationMarkersArePresent(): void
	{
		$config = \OCP\Server::get(IConfig::class);

		$this->assertSame(
			'1',
			$config->getAppValue('arbeitszeitcheck', 'tz_utc_to_berlin_migration_done', '0'),
			'UTC→Berlin migration must have completed on this instance.'
		);

		$appTz = $config->getAppValue('arbeitszeitcheck', Constants::CONFIG_APP_TIMEZONE, '');
		$this->assertNotSame('', $appTz, 'app_timezone must be configured after upgrade.');
		$this->assertSame('Europe/Berlin', $appTz);
	}

	public function testTimeZoneServiceResolvesStorageAndSerialisesWithOffset(): void
	{
		$service = \OCP\Server::get(TimeZoneService::class);
		$this->assertInstanceOf(TimeZoneService::class, $service);

		$this->assertSame('Europe/Berlin', $service->storageTimeZoneName());

		$iso = $service->toIso($service->nowInStorage());
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$iso,
			'API instants must carry an explicit offset.'
		);
	}

	public function testHydrateNaivePreservesStorageWallClockDigits(): void
	{
		$service = \OCP\Server::get(TimeZoneService::class);
		// Simulate Entity hydration in a UTC container: digits are Berlin wall clock.
		$asUtc = new \DateTime('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
		$hydrated = $service->hydrateNaive($asUtc);

		$this->assertSame('Europe/Berlin', $hydrated->getTimezone()->getName());
		$this->assertSame('2026-01-15 10:00:00', $hydrated->format('Y-m-d H:i:s'));
	}

	public function testToIsoEmbedsStorageTimezoneOffset(): void
	{
		$service = \OCP\Server::get(TimeZoneService::class);
		$asUtc = new \DateTime('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
		$winter = $service->hydrateNaive($asUtc);
		$iso = $service->toIso($winter);

		$this->assertStringContainsString('2026-01-15T10:00:00', $iso);
		$this->assertMatchesRegularExpression('/[+-]01:00$/', $iso, 'January in Europe/Berlin is CET (+01:00).');
	}
}
