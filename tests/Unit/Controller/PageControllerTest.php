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
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
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

	protected function setUp(): void
	{
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$overtimeService = $this->createMock(OvertimeService::class);
		$absenceService = $this->createMock(AbsenceService::class);
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('getColleagueIds')->willReturn([]);
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$user->method('getDisplayName')->willReturn('Test User');
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$config = $this->createMock(IConfig::class);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canAccessManagerDashboard')->willReturn(false);
		$permissionService->method('isAdmin')->willReturn(false);
		$cspService = $this->createMock(CSPService::class);
		$cspService->method('applyPolicyWithNonce')->willReturnCallback(fn ($r) => $r);
		$l10n = $this->createMock(IL10N::class);

		$this->controller = new PageController(
			'arbeitszeitcheck',
			$request,
			$timeTrackingService,
			$overtimeService,
			$absenceService,
			$timeEntryMapper,
			$absenceMapper,
			$teamResolver,
			$userSession,
			$groupManager,
			$urlGenerator,
			$config,
			$permissionService,
			$cspService,
			$l10n
		);
	}

	/**
	 * Test index returns template
	 */
	public function testIndexReturnsTemplate(): void
	{
		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('index', $response->getRenderAs());
	}

	/**
	 * Test dashboard returns template
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('index', $response->getRenderAs());
	}

	/**
	 * Test reports returns template
	 */
	public function testReportsReturnsTemplate(): void
	{
		$response = $this->controller->reports();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('index', $response->getRenderAs());
	}

	/**
	 * Test calendar returns template
	 */
	public function testCalendarReturnsTemplate(): void
	{
		$response = $this->controller->calendar();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('index', $response->getRenderAs());
	}

	/**
	 * Test timeline returns template
	 */
	public function testTimelineReturnsTemplate(): void
	{
		$response = $this->controller->timeline();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('index', $response->getRenderAs());
	}
}
