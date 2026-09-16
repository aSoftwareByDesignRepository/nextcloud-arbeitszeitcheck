<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\MobileStampIdempotency;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MobileStampReplayServiceTest extends TestCase
{
	/** @var TimeTrackingService&MockObject */
	private $timeTracking;
	/** @var MobileStampIdempotencyMapper&MockObject */
	private $idempotency;
	private MobileStampReplayService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeTracking = $this->createMock(TimeTrackingService::class);
		$this->idempotency = $this->createMock(MobileStampIdempotencyMapper::class);
		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'UTC',
			default => $default,
		});
		$tzDateTime = $this->createMock(IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1_700_000_000);
		$this->service = new MobileStampReplayService(
			$this->timeTracking,
			$this->idempotency,
			new StampOccurredAtParser($tz, $tzConfig),
			$timeFactory,
		);
	}

	public function testWithoutClientRequestIdDoesNotTouchIdempotency(): void
	{
		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 1, 'status' => 'active']);
		$this->timeTracking->expects($this->once())->method('clockIn')->willReturn($entry);
		$this->idempotency->expects($this->never())->method('tryInsert');

		$payload = $this->service->clockIn('ada', null, null, null, null);
		$this->assertTrue($payload['success']);
		$this->assertSame(1, $payload['timeEntry']['id']);
	}

	public function testCompletedReplayReturnsCachedPayloadWithoutMutating(): void
	{
		$row = new MobileStampIdempotency();
		$row->setResponseJson(json_encode(['success' => true, 'timeEntry' => ['id' => 9]], JSON_THROW_ON_ERROR));
		$this->idempotency->method('findByUserAndRequestId')->willReturn($row);
		$this->timeTracking->expects($this->never())->method('clockIn');

		$payload = $this->service->clockIn(
			'ada',
			null,
			null,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
		);
		$this->assertSame(9, $payload['timeEntry']['id']);
	}

	public function testClaimThenMutateThenPersist(): void
	{
		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 3]);
		$this->idempotency->method('findByUserAndRequestId')->willReturn(null);
		$this->idempotency->expects($this->once())->method('tryInsert')->willReturn(true);
		$this->idempotency->expects($this->once())->method('updateResponseJson');
		$this->timeTracking->expects($this->once())->method('clockIn')->willReturn($entry);

		$payload = $this->service->clockIn(
			'ada',
			null,
			null,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
		);
		$this->assertTrue($payload['success']);
	}

	public function testMutateFailureReleasesClaim(): void
	{
		$this->idempotency->method('findByUserAndRequestId')->willReturn(null);
		$this->idempotency->method('tryInsert')->willReturn(true);
		$this->idempotency->expects($this->once())->method('deleteByUserAndRequestId');
		$this->timeTracking->method('clockIn')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->service->clockIn(
			'ada',
			null,
			null,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
		);
	}

	public function testInFlightWhenClaimLostAndStillPending(): void
	{
		$pending = new MobileStampIdempotency();
		$pending->setResponseJson('{"pending":true}');
		$this->idempotency->method('findByUserAndRequestId')->willReturn($pending);
		$this->idempotency->method('tryInsert')->willReturn(false);

		$this->expectException(StampReplayException::class);
		$this->service->clockIn(
			'ada',
			null,
			null,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
		);
	}
}
