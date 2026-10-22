<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\KioskController;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Middleware\KioskUnauthorizedException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskOfflineStampService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Invokes KioskController used entrypoints: config/users/heartbeat/enrollScan.
 */
class KioskControllerAtlasTest extends TestCase
{
	/** @var IRequest&MockObject */
	private $request;
	/** @var KioskTerminalService&MockObject */
	private $terminalService;
	/** @var KioskAuthService&MockObject */
	private $authService;
	/** @var KioskEnrollmentService&MockObject */
	private $enrollmentService;
	/** @var LicenseService&MockObject */
	private $licenseService;
	/** @var TerminalDeviceService&MockObject */
	private $terminalDeviceService;
	/** @var KioskActionService&MockObject */
	private $actionService;
	/** @var KioskOfflineStampService&MockObject */
	private $offlineStampService;
	/** @var IThrottler&MockObject */
	private $throttler;
	private TimeZoneService $timeZoneService;
	private KioskController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->terminalService = $this->createMock(KioskTerminalService::class);
		$this->authService = $this->createMock(KioskAuthService::class);
		$this->enrollmentService = $this->createMock(KioskEnrollmentService::class);
		$this->licenseService = $this->createMock(LicenseService::class);
		$this->terminalDeviceService = $this->createMock(TerminalDeviceService::class);

		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'UTC',
			default => $default,
		});
		$tzDateTime = $this->createMock(IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new KioskController(
			'arbeitszeitcheck',
			$this->request,
			$this->terminalService,
			$this->authService,
			$this->actionService = $this->createMock(KioskActionService::class),
			$this->offlineStampService = $this->createMock(KioskOfflineStampService::class),
			$this->enrollmentService,
			new KioskErrorMessages($l10n),
			$this->licenseService,
			$this->terminalDeviceService,
			$this->timeZoneService,
			$this->createMock(LoggerInterface::class),
			$this->throttler = $this->createMock(IThrottler::class),
			$l10n,
		);
	}

	private function authedTerminal(): KioskTerminal
	{
		$terminal = new KioskTerminal();
		$terminal->setTerminalId('term-atlas');
		$terminal->setLabel('Atlas Desk');
		$this->request->method('getHeader')->willReturnCallback(static function (string $name): string {
			return match (strtolower($name)) {
				'x-kiosk-terminal-id' => 'term-atlas',
				'x-kiosk-token' => 'secret',
				default => '',
			};
		});
		$this->terminalService->method('validateTerminalToken')->willReturn($terminal);
		return $terminal;
	}

	public function testConfigHappyPath(): void
	{
		$this->authedTerminal();
		$this->terminalService->expects($this->once())->method('recordHeartbeat');
		$this->enrollmentService->method('getConfigEnrollment')->willReturn(null);
		$this->licenseService->method('buildEnvelope')->willReturn(['v' => 1]);
		$this->licenseService->method('getLicenseSummary')->willReturn(['terminalDevices' => 2, 'validUntil' => null]);
		$this->licenseService->method('isTerminalPlanActive')->willReturn(true);
		$this->terminalDeviceService->method('getActiveCount')->willReturn(1);

		$res = $this->controller->config();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('Atlas Desk', $data['data']['label']);
		$this->assertTrue($data['data']['licensing']['terminal']['planActive']);
	}

	public function testUsersHappyPath(): void
	{
		$this->authedTerminal();
		$this->authService->method('listPinUsers')->willReturn([
			['userId' => 'alice', 'displayName' => 'Alice'],
		]);
		$res = $this->controller->users();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame('alice', $res->getData()['data']['users'][0]['userId']);
	}

	public function testHeartbeatHappyPath(): void
	{
		$this->authedTerminal();
		$this->terminalService->expects($this->once())->method('recordHeartbeat');
		$res = $this->controller->heartbeat();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertTrue($res->getData()['success']);
	}

	public function testEnrollScanHappyPath(): void
	{
		$this->authedTerminal();
		$this->enrollmentService->method('completeScan')->with('term-atlas', 'UID123')->willReturn([
			'displayName' => 'Bob',
			'message' => 'ok',
		]);
		$res = $this->controller->enrollScan('UID123');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame('Bob', $res->getData()['data']['displayName']);
	}

	public function testConfigUnauthorizedWithoutTerminal(): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->terminalService->method('validateTerminalToken')->willReturn(null);
		$this->expectException(KioskUnauthorizedException::class);
		$this->controller->config();
	}

	public function testIdentifyHappyPath(): void
	{
		$this->authedTerminal();
		$this->authService->method('identify')->willReturn(['session' => 'abc']);
		$r = $this->controller->identify('rfid', '04:a1:b2', 'alice', null);
		$this->assertTrue($r->getData()['success']);
		$this->assertSame('abc', $r->getData()['data']['session']);
	}

	public function testIdentifyKioskErrorRegistersBruteForceAttempt(): void
	{
		$this->authedTerminal();
		$this->request->method('getRemoteAddress')->willReturn('10.0.0.7');
		$this->authService->method('identify')
			->willThrowException(new KioskException('PIN_INVALID'));

		$this->throttler->expects($this->once())->method('registerAttempt')
			->with('arbeitszeitcheck_kiosk_identify', '10.0.0.7', $this->arrayHasKey('reason'));

		$r = $this->controller->identify('pin', null, 'alice', '0000');
		$d = $r->getData();
		$this->assertFalse($d['success']);
		$this->assertSame('PIN_INVALID', $d['error']);
	}

	public function testIdentifyInternalErrorBecomesKioskError(): void
	{
		$this->authedTerminal();
		$this->authService->method('identify')->willThrowException(new \RuntimeException('db'));
		$r = $this->controller->identify('rfid', '04:a1:b2', null, null);
		$this->assertFalse($r->getData()['success']);
		$this->assertSame('KIOSK_INTERNAL_ERROR', $r->getData()['error']);
	}

	public function testActionHappyAndErrorPaths(): void
	{
		$this->authedTerminal();
		$this->actionService->method('performAction')->willReturn(['state' => 'in']);
		$r = $this->controller->action('tok', 'clock_in');
		$this->assertTrue($r->getData()['success']);
	}

	public function testActionErrorBecomesKioskError(): void
	{
		$this->authedTerminal();
		$this->actionService->method('performAction')
			->willThrowException(new KioskException('KIOSK_ACTION_INVALID'));
		$r = $this->controller->action('tok', 'bogus');
		$this->assertFalse($r->getData()['success']);
		$this->assertSame('KIOSK_ACTION_INVALID', $r->getData()['error']);
	}

	public function testActionUnexpectedErrorBecomesInternalError(): void
	{
		$this->authedTerminal();
		$this->actionService->method('performAction')
			->willThrowException(new \RuntimeException('driver crashed'));
		$r = $this->controller->action('tok', 'clock_in');
		$this->assertFalse($r->getData()['success']);
		$this->assertSame('KIOSK_INTERNAL_ERROR', $r->getData()['error']);
	}

	public function testHeartbeatErrorBecomesKioskError(): void
	{
		$this->authedTerminal();
		$this->terminalService->method('recordHeartbeat')
			->willThrowException(new \RuntimeException('db'));
		$r = $this->controller->heartbeat();
		$this->assertFalse($r->getData()['success']);
		$this->assertSame('KIOSK_INTERNAL_ERROR', $r->getData()['error']);
	}

	public function testEnrollScanErrorBecomesKioskError(): void
	{
		$this->authedTerminal();
		$this->enrollmentService->method('completeScan')
			->willThrowException(new KioskException('KIOSK_ENROLLMENT_EXPIRED'));
		$r = $this->controller->enrollScan('04:a1');
		$this->assertFalse($r->getData()['success']);
		$this->assertSame('KIOSK_ENROLLMENT_EXPIRED', $r->getData()['error']);
	}

	public function testStampReplayErrorResponseMapping(): void
	{
		$this->authedTerminal();
		$this->offlineStampService->method('stampRfid')
			->willThrowException(new StampReplayException('STAMP_OCCURRED_AT_OUT_OF_BOUNDS'));

		$r = $this->controller->stamp('rfid', '04:a1', 'clock_in', '', 'req-1');
		$d = $r->getData();
		$this->assertFalse($d['success']);
		$this->assertSame('STAMP_OCCURRED_AT_OUT_OF_BOUNDS', $d['error_code']);
		$this->assertSame(422, $r->getStatus());
	}

	public function testStampReplayInFlightIsConflict(): void
	{
		$this->authedTerminal();
		$this->offlineStampService->method('stampRfid')
			->willThrowException(new StampReplayException('STAMP_CLIENT_REQUEST_IN_FLIGHT'));
		$r = $this->controller->stamp('rfid', '04:a1', 'clock_in', '', 'req-1');
		$this->assertSame(Http::STATUS_CONFLICT, $r->getStatus());
	}
}