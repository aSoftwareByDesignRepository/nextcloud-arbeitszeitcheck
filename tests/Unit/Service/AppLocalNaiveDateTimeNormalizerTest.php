<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use PHPUnit\Framework\TestCase;

class AppLocalNaiveDateTimeNormalizerTest extends TestCase
{
	public function testReinterpretsUtcLoadedNaiveAsBerlinWallClock(): void
	{
		// Simulate Entity::setter: naive SQL string parsed in PHP default UTC
		$wronglyRead = new \DateTime('2026-05-13 09:00:00', new \DateTimeZone('UTC'));

		$fixed = AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone(
			$wronglyRead,
			new \DateTimeZone('Europe/Berlin')
		);

		$this->assertSame('Europe/Berlin', $fixed->getTimezone()->getName());
		$this->assertSame('2026-05-13 09:00:00', $fixed->format('Y-m-d H:i:s'));
		// Same wall 09:00 Berlin in summer (CEST) is 07:00Z
		$this->assertSame('2026-05-13T07:00:00+00:00', $fixed->setTimezone(new \DateTimeZone('UTC'))->format('c'));
	}

	public function testBerlinUserSeesSameClockDigitsWhenDisplayTzMatchesStorage(): void
	{
		$berlin = new \DateTimeZone('Europe/Berlin');
		$wronglyRead = new \DateTime('2026-05-13 09:00:00', new \DateTimeZone('UTC'));
		$fixed = AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($wronglyRead, $berlin);

		$display = clone $fixed;
		$display->setTimezone($berlin);
		$this->assertSame('09:00', $display->format('H:i'));
	}

	public function testParseFlexibleDateTimeHonoursExplicitZAndUsesAppTzForNaiveIso(): void
	{
		$berlin = new \DateTimeZone('Europe/Berlin');
		$utcNine = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime('2026-05-13T09:00:00Z', $berlin);
		// Explicit-offset input: the instant is honoured but the object is
		// expressed in the storage zone — persisting the raw foreign wall clock
		// would rehydrate shifted (storage columns are naive storage-wall).
		$this->assertSame('Europe/Berlin', $utcNine->getTimezone()->getName());
		$this->assertSame('11:00', $utcNine->format('H:i'));
		$this->assertSame((new \DateTime('2026-05-13T09:00:00Z'))->getTimestamp(), $utcNine->getTimestamp());

		$naive = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime('2026-05-13T09:00:00', $berlin);
		$this->assertSame('Europe/Berlin', $naive->getTimezone()->getName());
		$this->assertSame('09:00', $naive->format('H:i'));
	}

	public function testNowMutableInAppStorageUsesConfiguredZone(): void
	{
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(static function (string $appId, string $key, ?string $default = null) {
				if ($key === Constants::CONFIG_APP_TIMEZONE) {
					return 'Europe/Berlin';
				}
				return $default ?? '';
			});

		$dt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($config);
		$this->assertSame('Europe/Berlin', $dt->getTimezone()->getName());
		$this->assertLessThanOrEqual(2, abs(time() - $dt->getTimestamp()));
	}

	public function testNormalizeAtEntryDatetimeStringsInRowEmitsIso8601(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$row = [
			'id' => 1,
			'user_id' => 'alice',
			'start_time' => '2026-05-13 09:00:00',
			'end_time' => '2026-05-13 17:00:00',
			'approved_at' => '',
		];
		$out = AppLocalNaiveDateTimeNormalizer::normalizeAtEntryDatetimeStringsInRow($row, $tz);
		$this->assertStringStartsWith('2026-05-13T09:00:00', (string)$out['start_time']);
		$this->assertStringStartsWith('2026-05-13T17:00:00', (string)$out['end_time']);
		$this->assertSame('', $out['approved_at']);
	}
}
