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
		$claim = new MobileStampIdempotency();
		$claim->setId(7);
		$this->idempotency->method('findByUserAndRequestId')->willReturn(null);
		$this->idempotency->expects($this->once())->method('tryInsert')->willReturn($claim);
		$this->idempotency->expects($this->once())->method('updateResponseJsonForId')->with(7);
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
		$claim = new MobileStampIdempotency();
		$claim->setId(8);
		$this->idempotency->method('findByUserAndRequestId')->willReturn(null);
		$this->idempotency->method('tryInsert')->willReturn($claim);
		$this->idempotency->expects($this->once())->method('deleteById')->with(8);
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
		// Fresh claim (createdAt == timeFactory now) — not stale, keeps waiting.
		$pending->setCreatedAt(1_700_000_000);
		$this->idempotency->method('findByUserAndRequestId')->willReturn($pending);

		$this->expectException(StampReplayException::class);
		$this->expectExceptionMessage('STAMP_CLIENT_REQUEST_IN_FLIGHT');
		$this->service->clockIn(
			'ada',
			null,
			null,
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
		);
	}

	public function testSameRequestIdWithDifferentPayloadThrowsMismatch(): void
	{
		$row = new MobileStampIdempotency();
		$row->setResponseJson(json_encode(['success' => true, 'timeEntry' => ['id' => 9]], JSON_THROW_ON_ERROR));
		$row->setPayloadHash('a'.str_repeat('0', 63));
		$this->idempotency->method('findByUserAndRequestId')->willReturn($row);
		$this->timeTracking->expects($this->never())->method('clockIn');

		try {
			$this->service->clockIn(
				'ada',
				null,
				null,
				'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 minutes')->format(\DateTimeInterface::ATOM),
			);
			$this->fail('Expected StampReplayException');
		} catch (StampReplayException $e) {
			$this->assertSame('STAMP_CLIENT_REQUEST_MISMATCH', $e->getErrorCode());
		}
	}

	public function testStalePendingClaimIsReclaimedAndMutated(): void
	{
		$stale = new MobileStampIdempotency();
		$stale->setResponseJson('{"pending":true}');
		// Abandoned long before timeFactory's now → reclaimable.
		$stale->setCreatedAt(1_700_000_000 - 3600);
		$claim = new MobileStampIdempotency();
		$claim->setId(11);
		$this->idempotency->method('findByUserAndRequestId')->willReturnOnConsecutiveCalls($stale, null);
		$this->idempotency->expects($this->once())->method('deleteStalePending');
		$this->idempotency->expects($this->once())->method('tryInsert')->willReturn($claim);
		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 12]);
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

	public function testEquivalentOccurredAtFormatsReplayToSamePayload(): void
	{
		// "+02:00" and "Z" serializations of one instant share an epoch → one
		// fingerprint → the cached completed row is accepted as a replay.
		$instant = new \DateTimeImmutable('-5 minutes', new \DateTimeZone('Europe/Berlin'));
		$epoch = $instant->getTimestamp();
		$row = new MobileStampIdempotency();
		$row->setResponseJson(json_encode(['success' => true, 'timeEntry' => ['id' => 20]], JSON_THROW_ON_ERROR));
		$row->setPayloadHash(hash('sha256', 'clock_in|' . $epoch . '|||'));
		$this->idempotency->method('findByUserAndRequestId')->willReturn($row);
		$this->idempotency->expects($this->never())->method('tryInsert');
		$this->timeTracking->expects($this->never())->method('clockIn');

		$payload = $this->service->clockIn(
			'ada',
			null,
			null,
			'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee',
			$instant->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
		);
		$this->assertSame(20, $payload['timeEntry']['id']);
	}

	public function testSafeSummaryFallsBackWhenGetSummaryThrows(): void
	{
		// id/status are magic entity accessors — use a real entry, override getSummary
		$entry = new class extends TimeEntry {
			public function getSummary(): array
			{
				throw new \RuntimeException('corrupt');
			}
		};
		$entry->setId(42);
		$entry->setStatus('active');
		$this->timeTracking->method('clockIn')->willReturn($entry);

		$payload = $this->service->clockIn('ada', null, null, null, null);
		$this->assertTrue($payload['success']);
		$this->assertSame(42, $payload['timeEntry']['id']);
		$this->assertSame('ada', $payload['timeEntry']['userId']);
		$this->assertSame('active', $payload['timeEntry']['status']);
	}
}