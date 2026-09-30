<?php

declare(strict_types=1);

/**
 * Unit tests for PageController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\PageController;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSetting;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class PageControllerTest
 */
class PageControllerTest extends TestCase
{
	/** @var PageController */
	private $controller;

	private TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject $timeTrackingService;
	private TimeCaptureMethodService|\PHPUnit\Framework\MockObject\MockObject $timeCaptureMethodService;

	protected function setUp(): void
	{
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService = $this->timeTrackingService;
		$overtimeService = $this->createMock(OvertimeService::class);
		$absenceService = $this->createMock(AbsenceService::class);
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$timeEntryMapper->method('countByUser')->willReturn(0);
		$absenceMapper->method('countByUser')->willReturn(0);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->method('getSetting')->willReturn(new UserSetting());
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('getColleagueIds')->willReturn([]);
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$user->method('getDisplayName')->willReturn('Test User');
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/settings/breaks');
		$config = $this->createMock(IConfig::class);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessManagerDashboard')->willReturn(false);
		$permissionService->method('isAdmin')->willReturn(false);
		$overtimeDisplayService = $this->createMock(OvertimeDisplayService::class);
		$overtimeDisplayService->method('buildTrafficLightViewModel')->willReturn([
			'enabled' => false,
			'state' => 'green',
			'balance' => 0.0,
			'thresholds' => [
				'yellow_over' => 5.0,
				'red_over' => 15.0,
				'yellow_under' => 5.0,
				'red_under' => 15.0,
			],
			'bank_enabled' => false,
			'bank_state' => null,
			'needs_attention' => false,
		]);
		$overtimeBankService = $this->createMock(OvertimeBankService::class);
		$overtimeBankService->method('getBankStatus')->willReturn(['enabled' => false]);
		$overtimePayoutService = $this->createMock(OvertimePayoutService::class);
		$cspService = $this->createMock(CSPService::class);
		$cspService->method('applyPolicyWithNonce')->willReturnCallback(fn ($r) => $r);
		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
			'displayTimezone' => 'Europe/Berlin',
		]);
		$navigationFlags = new NavigationFlagsService(
			$absenceMapper,
			$permissionService,
			$config
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$projectCheckIntegration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$projectCheckIntegration->method('isProjectCheckAvailable')->willReturn(false);
		$projectCheckIntegration->method('getAvailableProjects')->willReturn([]);

		$this->timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$timeCaptureMethodService = $this->timeCaptureMethodService;
		$timeCaptureMethodService->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);

		$monthClosureGuard = $this->createMock(\OCA\ArbeitszeitCheck\Service\MonthClosureGuard::class);
		$deletionPolicy = new \OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy(
			$config,
			$monthClosureGuard,
			$l10n,
		);

		$this->controller = new PageController(
			'arbeitszeitcheck',
			$request,
			$timeTrackingService,
			$overtimeService,
			$absenceService,
			$timeEntryMapper,
			$absenceMapper,
			$userSettingsMapper,
			$teamResolver,
			$userSession,
			$groupManager,
			$urlGenerator,
			$config,
			$permissionService,
			$overtimeDisplayService,
			$overtimeBankService,
			$overtimePayoutService,
			$cspService,
			$localeFormat,
			$navigationFlags,
			$projectCheckIntegration,
			$timeCaptureMethodService,
			$deletionPolicy,
			$l10n,
		);
	}

	/**
	 * Test index returns template
	 */
	public function testIndexReturnsTemplate(): void
	{
		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('dashboard', $response->getTemplateName());
	}

	/**
	 * Test dashboard returns template
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('dashboard', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame('dashboard', $params['pageId'] ?? null);
		$this->assertNotEmpty($params['clientHints'] ?? null);
		$this->assertIsArray($params['urls'] ?? null);
	}

	/**
	 * Test reports returns template
	 */
	public function testReportsReturnsTemplate(): void
	{
		$response = $this->controller->reports();

		// In test setup the user is neither manager nor admin -> reports access is gated and redirects to dashboard.
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	/**
	 * Test calendar returns template
	 */
	public function testCalendarReturnsTemplate(): void
	{
		$response = $this->controller->calendar();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('calendar', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame('calendar', $params['pageId'] ?? null);
	}

	/**
	 * Test timeline returns template
	 */
	public function testTimelineReturnsTemplate(): void
	{
		$response = $this->controller->timeline();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('timeline', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame('timeline', $params['pageId'] ?? null);
	}

	public function testGetTheAppReturnsTemplate(): void
	{
		$response = $this->controller->getTheApp();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('get-the-app', $response->getTemplateName());
	}

	public function testSettingsRedirectsToDefaultSection(): void
	{
		$response = $this->controller->settings();
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringContainsString('/settings/breaks', $response->getRedirectUrl());
	}

	public function testSettingsSectionUnknownReturnsNotFound(): void
	{
		$response = $this->controller->settingsSection('not-a-real-section');
		$this->assertInstanceOf(\OCP\AppFramework\Http\NotFoundResponse::class, $response);
	}

	public function testTimeEntriesReturnsTemplate(): void
	{
		$response = $this->controller->timeEntries();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('time-entries', $response->getTemplateName());
	}

	public function testAbsencesReturnsTemplate(): void
	{
		$response = $this->controller->absences();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('absences', $response->getTemplateName());
	}

	public function testOvertimeBalancePdfReturnsDownloadOrJson(): void
	{
		$ref = new \ReflectionClass($this->controller);
		$display = $ref->getProperty('overtimeDisplayService');
		$display->setAccessible(true);
		/** @var \PHPUnit\Framework\MockObject\MockObject $svc */
		$svc = $display->getValue($this->controller);
		$svc->method('getYearToDateBalanceForTrafficLight')->willReturn(0.0);

		$response = $this->controller->overtimeBalancePdf();
		$this->assertTrue(
			$response instanceof \OCP\AppFramework\Http\DataDownloadResponse
			|| $response instanceof \OCP\AppFramework\Http\JSONResponse
		);
	}

	public function testSettingsSectionRendersKnownSectionWithComplianceProfile(): void
	{
		$profile = \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE');
		$this->timeTrackingService->method('lawProfile')->willReturn($profile);

		$response = $this->controller->settingsSection('breaks');
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$this->assertSame('breaks', $params['settingsSection']);
		$this->assertSame('DE', $params['complianceProfile']['country']);
		$this->assertNotEmpty($params['complianceProfile']['breakLines']);
		$this->assertArrayHasKey('maxDailyHours', $params['complianceProfile']);
	}

	public function testDashboardFallsBackToOrgDefaultsOnTimeCaptureFailure(): void
	{
		$this->timeCaptureMethodService->method('getSettings')
			->willThrowException(new \RuntimeException('store down'));
		$this->timeCaptureMethodService->method('getOrganizationDefaults')->willReturn([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => true,
		]);
		$profile = \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE');
		$this->timeTrackingService->method('lawProfile')->willReturn($profile);

		$params = $this->controller->dashboard()->getParams();
		$this->assertFalse($params['timeCapture']['clockStampingEnabled']);
		$this->assertTrue($params['timeCapture']['manualTimeEntryEnabled']);
	}

	public function testDashboardFallsBackToDisabledWhenOrgDefaultsAlsoFail(): void
	{
		$this->timeCaptureMethodService->method('getSettings')
			->willThrowException(new \RuntimeException('store down'));
		$this->timeCaptureMethodService->method('getOrganizationDefaults')
			->willThrowException(new \RuntimeException('also down'));
		$profile = \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE');
		$this->timeTrackingService->method('lawProfile')->willReturn($profile);

		$params = $this->controller->dashboard()->getParams();
		$this->assertFalse($params['timeCapture']['clockStampingEnabled']);
		$this->assertFalse($params['timeCapture']['manualTimeEntryEnabled']);
	}
}