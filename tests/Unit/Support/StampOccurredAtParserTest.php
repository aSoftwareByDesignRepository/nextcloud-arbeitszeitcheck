<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StampOccurredAtParserTest extends TestCase
{
	private StampOccurredAtParser $parser;

	protected function setUp(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'UTC',
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$this->parser = new StampOccurredAtParser($tz);
	}

	public function testEmptyWithoutClientRequestId(): void
	{
		$this->assertNull($this->parser->parseOptional(null, null));
		$this->assertNull($this->parser->parseOptional('', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));
	}

	public function testRequiresClientRequestIdWhenOccurredAtPresent(): void
	{
		$this->expectException(StampReplayException::class);
		$this->parser->parseOptional((new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM), null);
	}

	public function testRejectsFarPast(): void
	{
		$this->expectException(StampReplayException::class);
		$far = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 days')->format(\DateTimeInterface::ATOM);
		$this->parser->parseOptional($far, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
	}

	public function testAcceptsRecentPast(): void
	{
		$recent = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 minutes')->format(\DateTimeInterface::ATOM);
		$at = $this->parser->parseOptional($recent, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$this->assertInstanceOf(\DateTimeInterface::class, $at);
	}
}
