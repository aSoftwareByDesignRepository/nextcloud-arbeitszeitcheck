<?php

declare(strict_types=1);

/**
 * Tests for {@see \OCA\ArbeitszeitCheck\Service\TimeZoneService}.
 *
 * The service is the single source of truth for backend timezone handling.
 * These tests pin down every contractually important behaviour so that any
 * future regression (e.g. someone "fixing" the storage zone resolution to
 * read from PHP's default zone instead of app config) is caught immediately.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TimeZoneServiceTest extends TestCase
{
	private function buildService(
		string $appTimezone = 'Europe/Berlin',
		?\DateTimeZone $userDisplay = null,
		bool $userLoggedIn = true
	): TimeZoneService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function ($app, $key, $default) use ($appTimezone) {
				return $key === 'app_timezone' ? $appTimezone : $default;
			});

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')
			->willReturn($userDisplay ?? new \DateTimeZone('Europe/Berlin'));

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($userLoggedIn ? $this->createMock(IUser::class) : null);

		return new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
	}

	public function testStorageTimeZoneResolvesFromConfig(): void
	{
		$service = $this->buildService('America/New_York');
		$this->assertSame('America/New_York', $service->storageTimeZoneName());
	}

	public function testStorageTimeZoneFallsBackToDefaultOnInvalidConfig(): void
	{
		$service = $this->buildService('Definitely/NotAZone');
		$this->assertSame(TimeZoneService::DEFAULT_STORAGE_TZ, $service->storageTimeZoneName());
	}

	public function testNowInStorageIsBoundToStorageTimeZone(): void
	{
		$service = $this->buildService('Europe/Berlin');
		$now = $service->nowInStorage();
		$this->assertSame('Europe/Berlin', $now->getTimezone()->getName());
	}

	public function testFormatForNaiveSqlConvertsToStorageWallClock(): void
	{
		$service = $this->buildService('Europe/Berlin');
		// 09:00 UTC == 11:00 Berlin (summer) or 10:00 Berlin (winter). We choose
		// a winter instant so the assertion stays stable regardless of DST.
		$utc = new \DateTimeImmutable('2026-01-15T09:00:00+00:00');
		$this->assertSame('2026-01-15 10:00:00', $service->formatForNaiveSql($utc));
		// mutable DateTime arm: same instant, same naive output
		$mut = new \DateTime('2026-01-15 09:00:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-01-15 10:00:00', $service->formatForNaiveSql($mut));
		// naive input is interpreted as the instant it names
		$naive = \DateTime::createFromFormat('Y-m-d H:i:s', '2026-01-15 10:00:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-01-15 11:00:00', $service->formatForNaiveSql($naive));
	}

	public function testHydrateNaiveReinterpretsPhpDefaultZoneAsStorageZone(): void
	{
		$service = $this->buildService('Europe/Berlin');
		// Simulate Nextcloud Entity hydration in a UTC container: the wall
		// clock "11:00:00" was actually meant to be Berlin local time but
		// PHP parsed it as UTC.
		$asUtc = new \DateTime('2026-01-15 11:00:00', new \DateTimeZone('UTC'));
		$bound = $service->hydrateNaive($asUtc);
		$this->assertSame('Europe/Berlin', $bound->getTimezone()->getName());
		$this->assertSame('2026-01-15 11:00:00', $bound->format('Y-m-d H:i:s'));
		// And the absolute instant matches "11:00 Berlin", not "11:00 UTC".
		$this->assertSame('2026-01-15T11:00:00+01:00', $bound->format('c'));
	}

	public function testTodayWindowIsHalfOpenAndInStorageZone(): void
	{
		$service = $this->buildService('Europe/Berlin');
		[$start, $end] = $service->todayWindowInStorage();
		$this->assertSame('Europe/Berlin', $start->getTimezone()->getName());
		$this->assertSame('00:00:00', $start->format('H:i:s'));
		$this->assertSame('00:00:00', $end->format('H:i:s'));
		// Exactly 24 h apart unless the DST transition falls on this day.
		$diff = $end->getTimestamp() - $start->getTimestamp();
		$this->assertContains($diff, [86400, 82800, 90000], 'Day window should be 23/24/25h');
	}

	public function testDayWindowForReferenceInOtherZone(): void
	{
		$service = $this->buildService('Europe/Berlin');
		// 23:30 in Tokyo on 2026-01-15 is 15:30 in Berlin on 2026-01-15 — so
		// the storage-TZ day window must still be "2026-01-15 in Berlin".
		$reference = new \DateTimeImmutable('2026-01-15T23:30:00+09:00');
		[$start, $end] = $service->dayWindowInStorage($reference);
		$this->assertSame('2026-01-15 00:00:00', $start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-01-16 00:00:00', $end->format('Y-m-d H:i:s'));
	}

	public function testMonthWindowSpansTheFullCalendarMonth(): void
	{
		$service = $this->buildService('Europe/Berlin');
		[$start, $end] = $service->monthWindowInStorage(2026, 2);
		$this->assertSame('2026-02-01 00:00:00', $start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-03-01 00:00:00', $end->format('Y-m-d H:i:s'));
	}

	public function testDayKeyInStorageHandlesMidnightCrossing(): void
	{
		$service = $this->buildService('Europe/Berlin');
		// 00:30 UTC on 2026-01-16 == 01:30 Berlin on 2026-01-16 (still 16th).
		$instant = new \DateTimeImmutable('2026-01-16T00:30:00+00:00');
		$this->assertSame('2026-01-16', $service->dayKeyInStorage($instant));
		// 22:30 UTC on 2026-01-15 == 23:30 Berlin on 2026-01-15 (15th, just
		// before midnight).
		$instant2 = new \DateTimeImmutable('2026-01-15T22:30:00+00:00');
		$this->assertSame('2026-01-15', $service->dayKeyInStorage($instant2));
		// mutable DateTime arm
		$mut = new \DateTime('2026-01-16 00:30:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-01-16', $service->dayKeyInStorage($mut));
	}

	public function testParseStrictDateRejectsOverflow(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->buildService()->parseStrictDate('2026-02-30');
	}

	public function testParseStrictDateAcceptsLeapDay(): void
	{
		$service = $this->buildService();
		$d = $service->parseStrictDate('2028-02-29');
		$this->assertSame('2028-02-29', $d->format('Y-m-d'));
	}

	public function testToIsoIncludesExplicitOffset(): void
	{
		$service = $this->buildService('Europe/Berlin');
		$dt = new \DateTimeImmutable('2026-01-15T11:00:00', new \DateTimeZone('Europe/Berlin'));
		$this->assertSame('2026-01-15T11:00:00+01:00', $service->toIso($dt));
	}

	public function testToUserDisplayPreservesInstantWhileChangingZone(): void
	{
		$service = $this->buildService('Europe/Berlin', new \DateTimeZone('Pacific/Auckland'));
		$utc = new \DateTimeImmutable('2026-01-15T09:00:00+00:00');
		$converted = $service->toUserDisplay($utc);
		$this->assertSame('Pacific/Auckland', $converted->getTimezone()->getName());
		// The absolute instant must not change.
		$this->assertSame($utc->getTimestamp(), $converted->getTimestamp());
	}

	public function testUserDisplayTimeZoneFallsBackToStorageZoneWithoutSession(): void
	{
		$service = $this->buildService('Europe/Berlin', null, false);
		$this->assertSame('Europe/Berlin', $service->userDisplayTimeZone()->getName());
	}

	public function testFromIsoConvertsExplicitInstantsIntoStorageZone(): void
	{
		$service = $this->buildService('Europe/Berlin');
		$dt = $service->fromIso('2026-01-15T09:00:00+00:00');
		$this->assertSame('Europe/Berlin', $dt->getTimezone()->getName());
		$this->assertSame('2026-01-15 10:00:00', $dt->format('Y-m-d H:i:s'));
	}

	public function testFromIsoRejectsEmpty(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->buildService()->fromIso('   ');
	}

	public function testCurrentInstantMatchesNowTimestamp(): void
	{
		$service = $this->buildService('Europe/Berlin');
		$before = time();
		$instant = $service->currentInstant();
		$this->assertGreaterThanOrEqual($before, $instant);
		$this->assertLessThanOrEqual(time() + 1, $instant);
	}

	public function testHydrateNaiveAnyRebindsMutableAndImmutable(): void
	{
		$service = $this->buildService('Europe/Berlin');
		// mutable input -> mutable output, wall clock re-anchored to storage TZ
		$mutable = new \DateTime('2026-03-15 10:30:00', new \DateTimeZone('UTC'));
		$rebound = $service->hydrateNaiveAny($mutable);
		$this->assertInstanceOf(\DateTime::class, $rebound);
		$this->assertSame('2026-03-15 10:30:00', $rebound->format('Y-m-d H:i:s'));
		$this->assertSame('Europe/Berlin', $rebound->getTimezone()->getName());

		// immutable input -> immutable output
		$immutable = new \DateTimeImmutable('2026-03-15 10:30:00', new \DateTimeZone('UTC'));
		$reboundImmutable = $service->hydrateNaiveAny($immutable);
		$this->assertInstanceOf(\DateTimeImmutable::class, $reboundImmutable);
		$this->assertSame('Europe/Berlin', $reboundImmutable->getTimezone()->getName());
	}

}