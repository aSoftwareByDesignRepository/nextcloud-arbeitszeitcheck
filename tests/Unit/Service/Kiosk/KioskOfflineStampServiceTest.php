<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\KioskStampIdempotency;
use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskOfflineStampService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IDateTimeZone;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers KioskOfflineStampService::stampRfid — claim-before-mutate idempotency,
 * replay short-circuit, in-flight contention, and action dispatch.
 */
final class KioskOfflineStampServiceTest extends TestCase
{
	private KioskAuthService&MockObject $authService;
	private KioskActionService&MockObject $actionService;
	private TimeTrackingService&MockObject $timeTrackingService;
	private KioskStampIdempotencyMapper&MockObject $idempotencyMapper;
	private StampOccurredAtParser $occurredAtParser;
	private ITimeFactory&MockObject $timeFactory;
	private KioskOfflineStampService $service;
	private KioskTerminal $terminal;

	protected function setUp(): void
	{
		parent::setUp();
		$this->authService = $this->createMock(KioskAuthService::class);
		$this->actionService = $this->createMock(KioskActionService::class);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->idempotencyMapper = $this->createMock(KioskStampIdempotencyMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$tzService = new TimeZoneService(
			$this->createMock(IConfig::class),
			$this->createMock(IDateTimeZone::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);
		$this->occurredAtParser = new StampOccurredAtParser($tzService, $this->createMock(IConfig::class));

		$this->service = new KioskOfflineStampService(
			$this->authService,
			$this->actionService,
			$this->timeTrackingService,
			$this->idempotencyMapper,
			$this->occurredAtParser,
			$this->timeFactory
		);

		$this->terminal = new KioskTerminal();
		$this->terminal->setTerminalId('term-1');

		$this->timeFactory->method('getTime')->willReturn(1770000000);
		$this->authService->method('resolveUserIdFromRfid')->willReturn('alice');
		$this->actionService->method('actionMessageFor')->willReturn('Done');
	}

	private function occurredAt(string $rel = '-1 hour'): string
	{
		return (new \DateTime($rel, new \DateTimeZone('Europe/Berlin')))->format(\DateTimeInterface::ATOM);
	}

	public function testStampRfidRequiresClientRequestId(): void
	{
		$this->expectException(StampReplayException::class);
		$this->expectExceptionMessage('STAMP_CLIENT_REQUEST_ID_REQUIRED');
		$this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', '  ', $this->occurredAt());
	}

	public function testStampRfidRejectsInvalidRequestIdCharset(): void
	{
		$this->expectException(StampReplayException::class);
		$this->expectExceptionMessage('STAMP_CLIENT_REQUEST_ID_INVALID');
		$this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'bad id!', $this->occurredAt());
	}

	public function testStampRfidRejectsMissingOccurredAt(): void
	{
		$this->expectException(StampReplayException::class);
		$this->expectExceptionMessage('STAMP_OCCURRED_AT_INVALID');
		$this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'req-1', null);
	}

	public function testStampRfidReturnsCachedCompletedPayload(): void
	{
		$existing = new KioskStampIdempotency();
		$existing->setResponseJson('{"success":true,"data":{"newStatus":"working","message":"Done"}}');
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn($existing);
		$this->idempotencyMapper->expects($this->never())->method('tryInsert');

		$out = $this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'req-1', $this->occurredAt());
		$this->assertTrue($out['success']);
		$this->assertSame('working', $out['data']['newStatus']);
	}

	public function testStampRfidHappyPathClockIn(): void
	{
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn(null);
		$this->idempotencyMapper->method('tryInsert')->willReturn(true);
		$this->timeTrackingService->expects($this->once())->method('clockIn')
			->with('alice', null, null, $this->isInstanceOf(\DateTimeInterface::class));
		$this->idempotencyMapper->expects($this->once())->method('updateResponseJson')
			->with('term-1', 'req-1', $this->stringContains('"newStatus":"working"'));

		$out = $this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'req-1', $this->occurredAt());
		$this->assertTrue($out['success']);
		$this->assertSame('working', $out['data']['newStatus']);
		$this->assertSame('Done', $out['data']['message']);
	}

	public function testStampRfidReleasesClaimOnActionFailure(): void
	{
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn(null);
		$this->idempotencyMapper->method('tryInsert')->willReturn(true);
		$this->timeTrackingService->method('clockOut')->willThrowException(new KioskException('KIOSK_NO_ACTIVE_SESSION'));
		$this->idempotencyMapper->expects($this->once())
			->method('deleteByTerminalAndRequestId')->with('term-1', 'req-1');
		$this->idempotencyMapper->expects($this->never())->method('updateResponseJson');

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_NO_ACTIVE_SESSION');
		$this->service->stampRfid($this->terminal, 'A1B2', 'clock_out', 'req-1', $this->occurredAt());
	}

	public function testStampRfidRejectsUnknownAction(): void
	{
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn(null);
		$this->idempotencyMapper->method('tryInsert')->willReturn(true);
		$this->idempotencyMapper->expects($this->once())->method('deleteByTerminalAndRequestId');

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_ACTION_INVALID');
		$this->service->stampRfid($this->terminal, 'A1B2', 'fly_to_moon', 'req-1', $this->occurredAt());
	}

	public function testStampRfidDetectsInFlightRequest(): void
	{
		$pending = new KioskStampIdempotency();
		$pending->setResponseJson('{"pending":true}');
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn($pending);
		$this->idempotencyMapper->method('tryInsert')->willReturn(false);

		$this->expectException(StampReplayException::class);
		$this->expectExceptionMessage('STAMP_CLIENT_REQUEST_IN_FLIGHT');
		$this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'req-1', $this->occurredAt());
	}

	public function testStampRfidBreakAndClockOutStatuses(): void
	{
		$this->idempotencyMapper->method('findByTerminalAndRequestId')->willReturn(null);
		$this->idempotencyMapper->method('tryInsert')->willReturn(true);

		$this->assertSame('on_break', $this->service->stampRfid($this->terminal, 'A1B2', 'break_start', 'r1', $this->occurredAt())['data']['newStatus']);
		$this->assertSame('working', $this->service->stampRfid($this->terminal, 'A1B2', 'break_end', 'r2', $this->occurredAt('-30 minutes'))['data']['newStatus']);
		$this->assertSame('off', $this->service->stampRfid($this->terminal, 'A1B2', 'clock_out', 'r3', $this->occurredAt('-10 minutes'))['data']['newStatus']);
	}

	/**
	 * Two terminals racing the same clientRequestId: the loser of the
	 * claim waits briefly; when the winner finishes mid-wait the stored
	 * payload is returned instead of raising IN_FLIGHT.
	 */
	public function testStampRfidReturnsPayloadCompletedDuringWait(): void
	{
		$completed = new KioskStampIdempotency();
		$completed->setResponseJson(json_encode(['success' => true, 'data' => ['newStatus' => 'working', 'message' => 'Done']]));

		$pending = new KioskStampIdempotency();
		$pending->setResponseJson('{"pending":true}');

		$calls = 0;
		$this->idempotencyMapper->method('findByTerminalAndRequestId')
			->willReturnCallback(function () use (&$calls, $pending, $completed) {
				// first read (pre-claim): nothing; then claim fails; wait loop
				// sees pending once, then the completed payload.
				$calls++;
				return match (true) {
					$calls === 1 => null,
					$calls === 2 => $pending,
					default => $completed,
				};
			});
		$this->idempotencyMapper->method('tryInsert')->willReturn(false);

		$out = $this->service->stampRfid($this->terminal, 'A1B2', 'clock_in', 'req-9', $this->occurredAt());
		$this->assertSame('working', $out['data']['newStatus']);
	}
}
