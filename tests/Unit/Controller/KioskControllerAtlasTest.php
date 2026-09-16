<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\KioskController;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Middleware\KioskUnauthorizedException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService;
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
			$this->createMock(KioskActionService::class),
			$this->createMock(KioskOfflineStampService::class),
			$this->enrollmentService,
			new KioskErrorMessages($l10n),
			$this->licenseService,
			$this->terminalDeviceService,
			$this->timeZoneService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IThrottler::class),
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
}
