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

	public function testAbsencesFiltersByTypeIncludingLegacySick(): void
	{
		$vacation = $this->absenceOfType(1, 'vacation', \OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING);
		$sick = $this->absenceOfType(2, 'sick_leave');
		$legacySick = $this->absenceOfType(3, 'sick');
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findByUser')->willReturn([$vacation, $sick, $legacySick]);
		$mapper->method('countByUser')->willReturn(3);
		$this->setControllerProperty('absenceMapper', $mapper);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => $key === 'type' ? 'sick_leave' : $default
		);
		$this->setControllerProperty('request', $request);
		$config = $this->readControllerProperty('config');
		$config->method('getAppValue')->willReturn('[]');

		$params = $this->controller->absences()->getParams();
		$this->assertArrayNotHasKey('error', $params, (string)($params['error'] ?? ''));
		$this->assertSame('sick_leave', $params['filterType']);
		$ids = array_map(static fn ($absence) => $absence->getId(), $params['absences']);
		$this->assertEqualsCanonicalizing([2, 3], $ids);
		// Pending count stays on the unfiltered list.
		$this->assertSame(1, $params['stats']['pending_requests']);
	}

	public function testAbsencesIgnoresUnknownTypeFilter(): void
	{
		$vacation = $this->absenceOfType(1, 'vacation');
		$sick = $this->absenceOfType(2, 'sick_leave');
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findByUser')->willReturn([$vacation, $sick]);
		$mapper->method('countByUser')->willReturn(2);
		$this->setControllerProperty('absenceMapper', $mapper);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => $key === 'type' ? 'not-a-type' : $default
		);
		$this->setControllerProperty('request', $request);
		$config = $this->readControllerProperty('config');
		$config->method('getAppValue')->willReturn('[]');

		$params = $this->controller->absences()->getParams();
		$this->assertArrayNotHasKey('error', $params, (string)($params['error'] ?? ''));
		$this->assertSame('', $params['filterType']);
		$this->assertCount(2, $params['absences']);
	}

	public function testAbsencesPagerUsesTheRequestedPageAndSize(): void
	{
		$rows = [];
		for ($id = 1; $id <= 30; $id++) {
			$rows[] = $this->absenceOfType($id, 'vacation');
		}
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('findByUser')->willReturn($rows);
		$mapper->method('countByUser')->willReturn(30);
		$this->setControllerProperty('absenceMapper', $mapper);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null) => match ($key) {
				'page' => '2',
				'per_page' => '10',
				'type' => '',
				default => $default,
			}
		);
		$this->setControllerProperty('request', $request);
		$config = $this->readControllerProperty('config');
		$config->method('getAppValue')->willReturn('[]');

		$params = $this->controller->absences()->getParams();
		$this->assertArrayNotHasKey('error', $params, (string)($params['error'] ?? ''));
		$this->assertSame(30, $params['absencePager']['total']);
		$this->assertSame(2, $params['absencePager']['page']);
		$this->assertSame(10, $params['absencePager']['perPage']);
		$this->assertSame(10, $params['absencePager']['offset']);
		$this->assertSame(3, $params['absencePager']['pages']);
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

	public function testTimeEntriesFiltersByWorkLocation(): void
	{
		$home = $this->entryWithLocation(1, 'home');
		$office = $this->entryWithLocation(2, 'office');
		$this->useTimeEntryList([$home, $office], ['work_location' => 'home']);

		$params = $this->controller->timeEntries()->getParams();
		$this->assertArrayNotHasKey('error', $params);
		$this->assertSame('home', $params['filterWorkLocation']);
		$this->assertCount(1, $params['entries']);
		$this->assertSame('home', $params['entries'][0]->getWorkLocation());
		// KPI stays on the unfiltered set.
		$this->assertSame(2, $params['stats']['total_time_entries']);
		$this->assertSame(1, $params['entryPager']['total']);
		$this->assertSame('home', $params['entryPager']['filters']['work_location']);
	}

	public function testTimeEntriesPagerUsesTheRequestedPageAndSize(): void
	{
		$rows = [];
		for ($id = 1; $id <= 30; $id++) {
			$rows[] = $this->entryWithLocation($id, 'office');
		}
		$this->useTimeEntryList($rows, ['page' => '2', 'per_page' => '10']);

		$params = $this->controller->timeEntries()->getParams();
		$this->assertArrayNotHasKey('error', $params);
		$this->assertSame(30, $params['entryPager']['total']);
		$this->assertSame(2, $params['entryPager']['page']);
		$this->assertSame(10, $params['entryPager']['perPage']);
		$this->assertSame(10, $params['entryPager']['offset']);
		$this->assertSame(3, $params['entryPager']['pages']);
		$this->assertSame([], $params['entryPager']['filters']);
	}

	public function testTimeEntriesIgnoresUnknownWorkLocation(): void
	{
		$home = $this->entryWithLocation(1, 'home');
		$office = $this->entryWithLocation(2, 'office');
		$this->useTimeEntryList([$home, $office], ['work_location' => 'not-a-place']);

		$params = $this->controller->timeEntries()->getParams();
		$this->assertArrayNotHasKey('error', $params);
		$this->assertSame('', $params['filterWorkLocation']);
		$this->assertCount(2, $params['entries']);
	}

	public function testTimeEntriesWorkLocationCombinesWithStatus(): void
	{
		$homeDone = $this->entryWithLocation(1, 'home', \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$homeOpen = $this->entryWithLocation(2, 'home', \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE);
		$officeDone = $this->entryWithLocation(3, 'office', \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$this->useTimeEntryList([$homeDone, $homeOpen, $officeDone], [
			'work_location' => 'HOME',
			'status' => 'completed',
		]);

		$params = $this->controller->timeEntries()->getParams();
		$this->assertSame('home', $params['filterWorkLocation']);
		$this->assertSame('completed', $params['filterStatus']);
		$this->assertCount(1, $params['entries']);
		$this->assertSame(1, $params['entries'][0]->getId());
	}

	/**
	 * @param list<\OCA\ArbeitszeitCheck\Db\TimeEntry> $entries
	 * @param array<string, string> $query
	 */
	private function useTimeEntryList(array $entries, array $query): void
	{
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findByUser')->willReturn($entries);
		$mapper->method('findByUserAndStatus')->willReturn([]);
		$mapper->method('countByUser')->willReturn(count($entries));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => $query[$key] ?? null
		);
		$this->setControllerProperty('timeEntryMapper', $mapper);
		$this->setControllerProperty('request', $request);
		$config = $this->readControllerProperty('config');
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, mixed $default = ''): string {
				return is_string($default) ? $default : '';
			}
		);
	}

	private function readControllerProperty(string $name): mixed
	{
		$ref = new \ReflectionClass($this->controller);
		while ($ref !== false && !$ref->hasProperty($name)) {
			$ref = $ref->getParentClass();
		}
		self::assertNotFalse($ref);
		$prop = $ref->getProperty($name);
		$prop->setAccessible(true);
		return $prop->getValue($this->controller);
	}

	private function absenceOfType(int $id, string $type, string $status = \OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED): \OCA\ArbeitszeitCheck\Db\Absence
	{
		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setId($id);
		$absence->setUserId('test-user');
		$absence->setType($type);
		$absence->setStatus($status);
		$absence->setDays(1.0);
		return $absence;
	}

	private function entryWithLocation(int $id, string $location, string $status = \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED): \OCA\ArbeitszeitCheck\Db\TimeEntry
	{
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setId($id);
		$entry->setUserId('test-user');
		$entry->setStatus($status);
		$entry->setWorkLocation($location);
		$entry->setStartTime(new \DateTime('2026-10-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2026-10-01 17:00:00'));
		return $entry;
	}

	private function setControllerProperty(string $name, mixed $value): void
	{
		$ref = new \ReflectionClass($this->controller);
		while ($ref !== false && !$ref->hasProperty($name)) {
			$ref = $ref->getParentClass();
		}
		self::assertNotFalse($ref);
		$prop = $ref->getProperty($name);
		$prop->setAccessible(true);
		$prop->setValue($this->controller, $value);
	}
}