<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StampOccurredAtParserTest extends TestCase
{
	private function makeParser(int $maxPastHours = 24): StampOccurredAtParser
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($maxPastHours) {
				if ($app === 'arbeitszeitcheck' && $key === 'app_timezone') {
					return 'UTC';
				}
				if ($app === 'arbeitszeitcheck' && $key === Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS) {
					return (string)$maxPastHours;
				}
				return is_string($default) ? $default : (string)$default;
			}
		);
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		return new StampOccurredAtParser($tz, $config);
	}

	public function testEmptyWithoutClientRequestId(): void
	{
		$parser = $this->makeParser();
		$this->assertNull($parser->parseOptional(null, null));
		$this->assertNull($parser->parseOptional('', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));
	}

	public function testRequiresClientRequestIdWhenOccurredAtPresent(): void
	{
		$parser = $this->makeParser();
		$this->expectException(StampReplayException::class);
		$parser->parseOptional((new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM), null);
	}

	public function testRejectsFarPastAtDefault24h(): void
	{
		$parser = $this->makeParser(24);
		$this->expectException(StampReplayException::class);
		$far = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-25 hours')->format(\DateTimeInterface::ATOM);
		$parser->parseOptional($far, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
	}

	public function testAcceptsRecentPast(): void
	{
		$parser = $this->makeParser(24);
		$recent = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 minutes')->format(\DateTimeInterface::ATOM);
		$at = $parser->parseOptional($recent, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$this->assertInstanceOf(\DateTimeInterface::class, $at);
	}

	public function testAcceptsWeekendWindowWhenConfigured72h(): void
	{
		$parser = $this->makeParser(72);
		$weekend = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-60 hours')->format(\DateTimeInterface::ATOM);
		$at = $parser->parseOptional($weekend, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$this->assertInstanceOf(\DateTimeInterface::class, $at);
	}

	public function testRejectsBeyondConfigured72h(): void
	{
		$parser = $this->makeParser(72);
		$this->expectException(StampReplayException::class);
		$tooOld = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-73 hours')->format(\DateTimeInterface::ATOM);
		$parser->parseOptional($tooOld, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
	}

	public function testPolicyClampsAndPresets(): void
	{
		$this->assertSame(24, OfflineStampSkewPolicy::normalizePastHours(1));
		$this->assertSame(72, OfflineStampSkewPolicy::normalizePastHours(999));
		$this->assertTrue(OfflineStampSkewPolicy::isAllowedPastHours(48));
		$this->assertFalse(OfflineStampSkewPolicy::isAllowedPastHours(12));
		$this->assertSame([24, 48, 72], OfflineStampSkewPolicy::PRESET_PAST_HOURS);
		$this->assertSame(48 * 3600, OfflineStampSkewPolicy::pastSeconds(48));
	}
}
