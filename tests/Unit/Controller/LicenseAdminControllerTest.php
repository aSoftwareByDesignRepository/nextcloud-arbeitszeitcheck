<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\LicenseAdminController;
use OCA\ArbeitszeitCheck\License\Azc2Codec;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LicenseEnforcementService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class LicenseAdminControllerTest extends TestCase
{
	private LicenseAdminController $controller;
	private LicenseService $licenseService;
	private LicenseEnforcementService $licenseEnforcementService;
	private MobileSeatService $mobileSeatService;
	private TerminalDeviceService $terminalDeviceService;
	private IRequest $request;

	protected function setUp(): void
	{
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->licenseService = $this->createMock(LicenseService::class);
		$this->licenseEnforcementService = $this->createMock(LicenseEnforcementService::class);
		$this->mobileSeatService = $this->createMock(MobileSeatService::class);
		$this->terminalDeviceService = $this->createMock(TerminalDeviceService::class);
		$userManager = $this->createMock(IUserManager::class);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isAdmin')->willReturn(true);
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin1');
		$userSession->method('getUser')->willReturn($user);
		$cspService = $this->createMock(CSPService::class);
		$cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($response, $context) => $response);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(static fn (string $route, array $params = []) => '/apps/' . $route);
		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
		]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$this->controller = new LicenseAdminController(
			'arbeitszeitcheck',
			$this->request,
			$this->licenseService,
			$this->licenseEnforcementService,
			$this->mobileSeatService,
			$this->terminalDeviceService,
			$userManager,
			$permissionService,
			$userSession,
			$cspService,
			$urlGenerator,
			$localeFormat,
			$l10n,
		);
	}

	public function testIndexRendersAdminShellWithLicenseData(): void
	{
		$this->licenseService->method('getLicenseSummary')->willReturn(['valid' => true]);
		$this->licenseService->method('getMobileSeatLimit')->willReturn(5);
		$this->licenseService->method('getTerminalDeviceLimit')->willReturn(2);
		$this->licenseService->method('getInstanceIdForBinding')->willReturn('inst-1');
		$this->mobileSeatService->method('getAssignedCount')->willReturn(3);
		$this->mobileSeatService->method('listSeats')->willReturn([]);
		$this->terminalDeviceService->method('getActiveCount')->willReturn(1);

		$response = $this->controller->index();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$this->assertSame('admin-license', $params['pageId']);
		$this->assertTrue($params['showAdminNav']);
		$this->assertTrue($params['showManagerLink']);
		$this->assertFalse($params['showSubstitutionLink']);
		$this->assertSame(5, $params['mobileSeatsLimit']);
		$this->assertTrue($params['showMobileSeats']);
		$this->assertTrue($params['showTerminal']);
	}

	public function testApplyLicenseRejectsEmptyKey(): void
	{
		$this->request->method('getParams')->willReturn(['licenseKey' => '  ']);

		$r = $this->controller->applyLicense();
		$d = $r->getData();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertFalse($d['ok']);
		$this->assertSame('empty_key', $d['error']);
	}

	public function testApplyLicenseMapsKnownErrorCode(): void
	{
		$this->request->method('getParams')->willReturn(['licenseKey' => 'AZC2-bad']);
		$this->licenseService->method('applyLicenseKey')->willReturn(false);
		$this->licenseService->method('getLastApplyErrorCode')->willReturn(Azc2Codec::ERROR_EXPIRED);

		$r = $this->controller->applyLicense();
		$d = $r->getData();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $r->getStatus());
		$this->assertFalse($d['ok']);
		$this->assertSame(Azc2Codec::ERROR_EXPIRED, $d['error']);
		$this->assertSame('License expired.', $d['message']);
	}

	public function testApplyLicenseFallsBackForUnknownErrorCode(): void
	{
		$this->request->method('getParams')->willReturn(['licenseKey' => 'AZC2-weird']);
		$this->licenseService->method('applyLicenseKey')->willReturn(false);
		$this->licenseService->method('getLastApplyErrorCode')->willReturn('totally_unknown');

		$d = $this->controller->applyLicense()->getData();
		$this->assertSame('Could not save license.', $d['message']);
	}

	public function testApplyLicenseSuccessEnforcesLimitsAndReturnsSummary(): void
	{
		$this->request->method('getParams')->willReturn(['licenseKey' => 'AZC2-ok']);
		$this->licenseService->method('applyLicenseKey')->willReturn(true);
		$this->licenseService->method('getLicenseSummary')->willReturn(['valid' => true]);
		$this->licenseService->method('getMobileSeatLimit')->willReturn(5);
		$this->licenseService->method('getTerminalDeviceLimit')->willReturn(0);
		$this->mobileSeatService->method('getAssignedCount')->willReturn(1);
		$this->terminalDeviceService->method('getActiveCount')->willReturn(0);
		$this->licenseEnforcementService->expects($this->once())
			->method('enforceCurrentLimits')
			->willReturn(['seatsRevoked' => 0]);

		$r = $this->controller->applyLicense();
		$d = $r->getData();
		$this->assertSame(Http::STATUS_OK, $r->getStatus());
		$this->assertTrue($d['ok']);
		$this->assertSame(['seatsRevoked' => 0], $d['enforced']);
	}

	public function testClearLicense(): void
	{
		$this->licenseEnforcementService->method('clearAllCommercialState')->willReturn(['seats' => 2]);

		$d = $this->controller->clearLicense()->getData();
		$this->assertTrue($d['ok']);
		$this->assertSame(['seats' => 2], $d['cleared']);
	}
}
