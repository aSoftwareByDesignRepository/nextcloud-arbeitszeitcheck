<?php

declare(strict_types=1);

/**
 * Unit tests for ExportController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ExportController;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class ExportControllerTest
 */
class ExportControllerTest extends TestCase
{
	/** @var ExportController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var DatevExportService|\PHPUnit\Framework\MockObject\MockObject */
	private $datevExportService;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var \OCP\IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var \OCA\ArbeitszeitCheck\Service\TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var \OCA\ArbeitszeitCheck\Db\TeamMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamMapper;

	/** @var \OCA\ArbeitszeitCheck\Db\TeamMemberMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamMemberMapper;

	/** @var \OCA\ArbeitszeitCheck\Db\TeamManagerMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamManagerMapper;

	/** @var \OCA\ArbeitszeitCheck\Db\AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var \OCA\ArbeitszeitCheck\Service\OversightScopeService */
	private $oversightScopeService;

	/** @var array<string,bool> uid => true when the account must look deleted */
	private array $missingUsers = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->datevExportService = $this->createMock(DatevExportService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []): string {
			// minimal formatter for tests (covers %s and %d)
			return $parameters === [] ? $text : (string)vsprintf($text, $parameters);
		});

		// Default: midnight split enabled to preserve current behaviour.
		// Use a callback (no strict argument matching) so individual tests can override cleanly.
		$this->config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($app === 'arbeitszeitcheck' && $key === 'export_midnight_split_enabled') {
					return '1';
				}
				return $default;
			});

		$this->request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			return $default ?? '';
		});

		// Scope checks uid existence via userManager->get(); default: every
		// uid resolves to an enabled account (see missingUsers overrides).
		$this->userManager = $this->createMock(\OCP\IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if ($uid === '' || isset($this->missingUsers[$uid])) {
				return null;
			}
			$stub = $this->createMock(IUser::class);
			$stub->method('getUID')->willReturn($uid);
			$stub->method('isEnabled')->willReturn(true);
			$stub->method('getDisplayName')->willReturn($uid);
			return $stub;
		});
		$this->teamResolver = $this->createMock(\OCA\ArbeitszeitCheck\Service\TeamResolverService::class);
		$this->teamMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMapper::class);
		$this->teamMemberMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class);
		$this->teamManagerMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class);
		$this->auditLogMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
		// Real scope service (final — not mockable) wired to the test mocks.
		$this->oversightScopeService = new \OCA\ArbeitszeitCheck\Service\OversightScopeService(
			$this->permissionService,
			$this->teamResolver,
			$this->teamMapper,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->userManager,
		);

		$this->controller = new ExportController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer($this->config),
			$this->userSession,
			$this->l10n,
			$this->config,
			$this->permissionService,
			$this->userManager,
			$this->oversightScopeService,
			$this->auditLogMapper
		);
	}

	/**
	 * Test timeEntries export as CSV
	 */
	public function testTimeEntriesExportAsCsv(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Work');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-15 17:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-15 17:00:00'));

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->timeEntries('csv', '2024-01-01', '2024-01-31');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('time-entries-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	/**
	 * Test timeEntries export as JSON
	 */
	public function testTimeEntriesExportAsJson(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Work');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-15 17:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-15 17:00:00'));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->timeEntries('json');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('.json', $contentDisposition);
	}

	/**
	 * Test timeEntries export as DATEV
	 */
	public function testTimeEntriesExportAsDatev(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Work');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-15 17:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-15 17:00:00'));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$this->datevExportService->expects($this->once())
			->method('exportTimeEntries')
			->with(
				$userId,
				$this->callback(static function (\DateTime $start): bool {
					return $start->format('Y-m-d H:i:s') === '2024-01-01 00:00:00';
				}),
				$this->callback(static function (\DateTime $endExclusive): bool {
					// Inclusive end 2024-01-31 → exclusive 2024-02-01 (last-day hours included).
					return $endExclusive->format('Y-m-d H:i:s') === '2024-02-01 00:00:00';
				})
			)
			->willReturn('DATEV export content');

		$response = $this->controller->timeEntries('datev', '2024-01-01', '2024-01-31');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('datev-export-', $contentDisposition);
	}

	/**
	 * Test timeEntries export uses default date range
	 */
	public function testTimeEntriesExportUsesDefaultDateRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with(
				$userId,
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn([]);

		$response = $this->controller->timeEntries('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	/**
	 * Test absences export as CSV
	 */
	public function testAbsencesExportAsCsv(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setDays(5);
		$absence->setReason('Summer vacation');
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment(null);
		$absence->setApprovedAt(null);
		$absence->setCreatedAt(new \DateTime('2024-05-01'));
		$absence->setUpdatedAt(new \DateTime('2024-05-01'));

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('absences-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	public function testAbsencesExportAsCsvSanitisesFormulaInjectionPayloads(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-01'));
		$absence->setDays(1);
		$absence->setReason('=HYPERLINK("http://evil.example","click")');
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment('  +SUM(1,1)');
		$absence->setApprovedAt(null);
		$absence->setCreatedAt(new \DateTime('2024-05-01'));
		$absence->setUpdatedAt(new \DateTime('2024-05-01'));

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = (string)$response->render();
		$this->assertStringContainsString('\'=HYPERLINK(""http://evil.example"",""click"")', $content);
		$this->assertStringContainsString('\'  +SUM(1,1)', $content);
	}

	public function testAbsencesExportSortByTypeGroupsCsvSections(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$sick = new Absence();
		$sick->setId(2);
		$sick->setUserId($userId);
		$sick->setType(Absence::TYPE_SICK_LEAVE);
		$sick->setStartDate(new \DateTime('2024-06-10'));
		$sick->setEndDate(new \DateTime('2024-06-10'));
		$sick->setDays(1);
		$sick->setReason('flu');
		$sick->setStatus(Absence::STATUS_APPROVED);
		$sick->setApproverComment(null);
		$sick->setApprovedAt(null);
		$sick->setCreatedAt(new \DateTime('2024-06-09'));
		$sick->setUpdatedAt(new \DateTime('2024-06-09'));

		$vacation = new Absence();
		$vacation->setId(1);
		$vacation->setUserId($userId);
		$vacation->setType(Absence::TYPE_VACATION);
		$vacation->setStartDate(new \DateTime('2024-06-01'));
		$vacation->setEndDate(new \DateTime('2024-06-02'));
		$vacation->setDays(2);
		$vacation->setReason('trip');
		$vacation->setStatus(Absence::STATUS_APPROVED);
		$vacation->setApproverComment(null);
		$vacation->setApprovedAt(null);
		$vacation->setCreatedAt(new \DateTime('2024-05-01'));
		$vacation->setUpdatedAt(new \DateTime('2024-05-01'));

		// Deliberately return date order (vacation first); sort=type must regroup.
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$vacation, $sick]);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', 'type');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = (string)$response->render();
		$this->assertStringContainsString('Sorted by absence type', $content);
		$firstTypeMarker = strpos($content, 'Absence type');
		$this->assertNotFalse($firstTypeMarker);
		$secondTypeMarker = strpos($content, 'Absence type', (int)$firstTypeMarker + 1);
		$this->assertNotFalse($secondTypeMarker);
		$slice = substr($content, (int)$firstTypeMarker, (int)$secondTypeMarker - (int)$firstTypeMarker);
		// sick_leave sorts before vacation
		$this->assertStringContainsString(Absence::TYPE_SICK_LEAVE, $slice);
		$this->assertStringNotContainsString(Absence::TYPE_VACATION, $slice);
	}

	/**
	 * Test absences export uses default date range
	 */
	public function testAbsencesExportUsesDefaultDateRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with(
				$userId,
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn([]);

		$response = $this->controller->absences('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	/**
	 * Test compliance export as PDF (falls back to CSV)
	 */
	public function testComplianceExportAsPdf(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = new ComplianceViolation();
		$violation->setId(1);
		$violation->setUserId($userId);
		$violation->setDate(new \DateTime('2024-01-15'));
		$violation->setViolationType(ComplianceViolation::TYPE_MISSING_BREAK);
		$violation->setDescription('Missing break');
		$violation->setSeverity(ComplianceViolation::SEVERITY_WARNING);
		$violation->setResolved(false);
		$violation->setResolvedAt(null);
		$violation->setTimeEntryId(1);
		$violation->setCreatedAt(new \DateTime('2024-01-15'));

		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->willReturn([$violation]);

		$response = $this->controller->compliance('pdf', '2024-01-01', '2024-01-31');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(\OCP\AppFramework\Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
		$this->assertStringContainsString('PDF', (string)$data['error']);
	}

	/**
	 * Test compliance export uses default date range
	 */
	public function testComplianceExportUsesDefaultDateRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId
			)
			->willReturn([]);

		$response = $this->controller->compliance('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	/**
	 * Test datevConfig returns configuration status
	 */
	public function testDatevConfigReturnsConfigurationStatus(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->permissionService->expects($this->once())
			->method('isAdmin')
			->with('admin-user')
			->willReturn(true);

		$status = [
			'configured' => true,
			'beraternummer' => '12345',
			'mandantennummer' => '1'
		];

		$this->datevExportService->expects($this->once())
			->method('getConfigurationStatus')
			->willReturn($status);

		$response = $this->controller->datevConfig();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('config', $data);
		$this->assertTrue($data['config']['configured']);
		$this->assertFalse($data['config']['personalnummer_set_for_viewer']);
		$this->assertFalse($data['config']['ready_for_self_export']);
	}

	public function testDatevConfigMarksReadyWhenPersonalnummerSet(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->datevExportService->method('getConfigurationStatus')->willReturn([
			'configured' => true,
			'beraternummer' => '1234567',
			'mandantennummer' => '12345',
		]);
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default = ''): string {
				if ($uid === 'admin-user' && $key === \OCA\ArbeitszeitCheck\Constants::USER_DATEV_PERSONALNUMMER) {
					return '12345678';
				}
				return $default;
			});

		$response = $this->controller->datevConfig();
		$data = $response->getData();
		$this->assertTrue($data['config']['personalnummer_set_for_viewer']);
		$this->assertTrue($data['config']['ready_for_self_export']);
	}

	public function testDatevEndpointUsesExclusiveEndBound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->willReturn(true);

		$this->datevExportService->expects($this->once())
			->method('exportTimeEntries')
			->with(
				'admin-user',
				$this->callback(static fn (\DateTime $s): bool => $s->format('Y-m-d') === '2024-03-01'),
				$this->callback(static fn (\DateTime $e): bool => $e->format('Y-m-d H:i:s') === '2024-04-01 00:00:00')
			)
			->willReturn("HEADER\n");

		$response = $this->controller->datev('2024-03-01', '2024-03-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testDatevOrganizationDeniedForNonAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->expects($this->once())
			->method('isAdmin')
			->with('regular-user')
			->willReturn(false);
		$this->permissionService->expects($this->once())
			->method('logPermissionDenied')
			->with('regular-user', 'export_datev_org', 'datev_export');
		$this->datevExportService->expects($this->never())->method('exportMultipleUsers');
		$this->datevExportService->expects($this->never())->method('exportTimeEntries');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			if ($name === 'scope') {
				return 'organization';
			}
			return $default ?? '';
		});
		$controller = new ExportController(
			'arbeitszeitcheck',
			$request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer($this->config),
			$this->userSession,
			$this->l10n,
			$this->config,
			$this->permissionService
		);

		$response = $controller->datev('2024-01-01', '2024-01-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('Access denied', $content);
	}

	public function testDatevOtherUserDeniedForNonAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->expects($this->once())
			->method('logPermissionDenied')
			->with('regular-user', 'export_datev_user', 'datev_export', 'victim');
		$this->datevExportService->expects($this->never())->method('exportTimeEntries');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			if ($name === 'userId') {
				return 'victim';
			}
			if ($name === 'scope') {
				return 'self';
			}
			return $default ?? '';
		});
		$controller = new ExportController(
			'arbeitszeitcheck',
			$request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer($this->config),
			$this->userSession,
			$this->l10n,
			$this->config,
			$this->permissionService
		);

		$response = $controller->datev('2024-01-01', '2024-01-07');
		$this->assertStringContainsString('Access denied', $response->render());
	}

	public function testDatevOrganizationExportsEnabledUsers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with('admin-user')->willReturn(true);

		$u1 = $this->createMock(IUser::class);
		$u1->method('getUID')->willReturn('alice');
		$u1->method('isEnabled')->willReturn(true);
		$u2 = $this->createMock(IUser::class);
		$u2->method('getUID')->willReturn('bob');
		$u2->method('isEnabled')->willReturn(false);
		$userManager = $this->createMock(\OCP\IUserManager::class);
		$userManager->method('search')->with('')->willReturn([$u1, $u2]);

		$this->datevExportService->expects($this->once())
			->method('exportMultipleUsers')
			->with(
				['alice'],
				$this->callback(static fn (\DateTime $s): bool => $s->format('Y-m-d') === '2024-01-01'),
				$this->callback(static fn (\DateTime $e): bool => $e->format('Y-m-d H:i:s') === '2024-02-01 00:00:00')
			)
			->willReturn("ORG\n");

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			if ($name === 'scope') {
				return 'organization';
			}
			return $default ?? '';
		});
		$controller = new ExportController(
			'arbeitszeitcheck',
			$request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer($this->config),
			$this->userSession,
			$this->l10n,
			$this->config,
			$this->permissionService,
			$userManager
		);

		$response = $controller->datev('2024-01-01', '2024-01-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$cd = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('datev-export-org-', $cd);
		$this->assertSame("ORG\n", $response->render());
	}

	/**
	 * Test datevConfig handles exceptions
	 */
	public function testDatevConfigHandlesException(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-user');
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->permissionService->expects($this->once())
			->method('isAdmin')
			->with('admin-user')
			->willReturn(true);

		$this->datevExportService->expects($this->once())
			->method('getConfigurationStatus')
			->willThrowException(new \Exception('Configuration error'));

		$response = $this->controller->datevConfig();

		$this->assertEquals(\OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Export failed', $data['error']);
	}

	public function testDatevConfigReturnsUnauthorizedWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);
		$this->permissionService->expects($this->never())->method('isAdmin');
		$this->datevExportService->expects($this->never())->method('getConfigurationStatus');

		$response = $this->controller->datevConfig();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals(\OCP\AppFramework\Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertStringContainsString('User not authenticated', $data['error']);
	}

	public function testDatevConfigReturnsForbiddenForNonAdmin(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->permissionService->expects($this->once())
			->method('isAdmin')
			->with('regular-user')
			->willReturn(false);
		$this->permissionService->expects($this->once())
			->method('logPermissionDenied')
			->with('regular-user', 'read_datev_config', 'datev_config');
		$this->datevExportService->expects($this->never())->method('getConfigurationStatus');

		$response = $this->controller->datevConfig();
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals(\OCP\AppFramework\Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('Access denied', $data['error']);
	}

	/**
	 * Test timeEntries export handles DATEV export exceptions
	 */
	public function testTimeEntriesExportHandlesDatevException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Work');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-15 17:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-15 17:00:00'));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$this->datevExportService->expects($this->once())
			->method('exportTimeEntries')
			->willThrowException(new \Exception('DATEV export failed'));

		$response = $this->controller->timeEntries('datev');

		// Should return CSV with error message
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('datev-export-error-', $contentDisposition);
	}

	/**
	 * Test timeEntries export returns error when user not authenticated
	 */
	public function testTimeEntriesExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->timeEntries('csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('User not authenticated', $content);
	}

	/**
	 * Test absences export returns error when user not authenticated
	 */
	public function testAbsencesExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->absences('csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('User not authenticated', $content);
	}

	/**
	 * Test compliance export returns error when user not authenticated
	 */
	public function testComplianceExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->compliance('csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('User not authenticated', $content);
	}

	/**
	 * Test timeEntries export handles empty data
	 */
	public function testTimeEntriesExportHandlesEmptyData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([]);

		$response = $this->controller->timeEntries('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('No data available', $content);
	}

	/**
	 * Test timeEntries export splits entries spanning midnight into two rows
	 */
	public function testTimeEntriesExportSplitsMidnightSpanningEntry(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 22:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-16 06:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Night shift');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-16 06:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-16 06:00:00'));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Ensure midnight split is enabled for this test
		$this->config->method('getAppValue')
			->with('arbeitszeitcheck', 'export_midnight_split_enabled', '1')
			->willReturn('1');

		$response = $this->controller->timeEntries('csv', '2024-01-15', '2024-01-16');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();

		// Erste Zeile ist Header, danach sollten zwei Datenzeilen existieren
		$lines = array_values(array_filter(explode("\n", trim($content))));
		if (isset($lines[0]) && str_starts_with($lines[0], 'sep=')) {
			array_shift($lines);
		}
		$lines = array_values(array_filter($lines, static function (string $line): bool {
			$cols = str_getcsv($line);
			$first = isset($cols[0]) ? trim((string)$cols[0]) : '';
			return !str_starts_with($first, '#');
		}));
		$this->assertGreaterThanOrEqual(3, count($lines));

		$header = str_getcsv($lines[0]);
		$row1 = str_getcsv($lines[1]);
		$row2 = str_getcsv($lines[2]);

		// Hilfsfunktion: Hole Spaltenindex
		$getIndex = static function (array $head, string $name): int {
			$idx = array_search($name, $head, true);
			return $idx === false ? -1 : $idx;
		};

		$dateIdx = $getIndex($header, 'date');
		$startIdx = $getIndex($header, 'start_time');
		$endIdx = $getIndex($header, 'end_time');
		$durationIdx = $getIndex($header, 'duration_hours');
		$workingIdx = $getIndex($header, 'working_hours');

		$this->assertNotSame(-1, $dateIdx);
		$this->assertNotSame(-1, $startIdx);
		$this->assertNotSame(-1, $endIdx);
		$this->assertNotSame(-1, $durationIdx);
		$this->assertNotSame(-1, $workingIdx);

		// Erste Datenzeile: 15.01., 23:00:00–23:59:59 (timezone-normalized)
		$this->assertSame('2024-01-15', $row1[$dateIdx]);
		$this->assertSame('23:00:00', $row1[$startIdx]);
		$this->assertSame('23:59:59', $row1[$endIdx]);

		// Zweite Datenzeile: 16.01., 00:00:00–07:00:00 (timezone-normalized)
		$this->assertSame('2024-01-16', $row2[$dateIdx]);
		$this->assertSame('00:00:00', $row2[$startIdx]);
		$this->assertSame('07:00:00', $row2[$endIdx]);

		// Die Summe der gesplitteten Arbeitsstunden sollte (näherungsweise) der Originaldauer entsprechen
		$totalDuration = (float)$row1[$durationIdx] + (float)$row2[$durationIdx];
		$totalWorking = (float)$row1[$workingIdx] + (float)$row2[$workingIdx];

		$this->assertEqualsWithDelta(8.0, $totalDuration, 0.02);
		$this->assertEqualsWithDelta(8.0, $totalWorking, 0.02);
	}

	/**
	 * Test timeEntries export does NOT split midnight-spanning entries when setting is disabled
	 */
	public function testTimeEntriesExportDoesNotSplitWhenMidnightSplitDisabled(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 22:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-16 06:00:00'));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
		$entry->setBreaks(null);
		$entry->setDescription('Night shift');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setProjectCheckProjectId(null);
		$entry->setCreatedAt(new \DateTime('2024-01-16 06:00:00'));
		$entry->setUpdatedAt(new \DateTime('2024-01-16 06:00:00'));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Explicitly disable midnight split by using a dedicated controller instance
		// (avoids interactions with other stubs defined in setUp()).
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				if ($app === 'arbeitszeitcheck' && $key === 'export_midnight_split_enabled') {
					return '0';
				}
				return $default;
			});
		$controller = new ExportController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer($this->config),
			$this->userSession,
			$this->l10n,
			$config,
			$this->permissionService
		);

		$response = $controller->timeEntries('csv', '2024-01-15', '2024-01-16');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();

		$lines = array_values(array_filter(explode("\n", trim($content))));
		// CSV exports may contain an Excel separator hint line: "sep=,"
		if (isset($lines[0]) && str_starts_with($lines[0], 'sep=')) {
			array_shift($lines);
		}
		$lines = array_values(array_filter($lines, static function (string $line): bool {
			$cols = str_getcsv($line);
			$first = isset($cols[0]) ? trim((string)$cols[0]) : '';
			return !str_starts_with($first, '#');
		}));
		$this->assertCount(2, $lines); // 1 header + 1 data line

		$header = str_getcsv($lines[0]);
		$row = str_getcsv($lines[1]);

		$getIndex = static function (array $head, string $name): int {
			$idx = array_search($name, $head, true);
			return $idx === false ? -1 : $idx;
		};

		$dateIdx = $getIndex($header, 'date');
		$startIdx = $getIndex($header, 'start_time');
		$endIdx = $getIndex($header, 'end_time');

		$this->assertNotSame(-1, $dateIdx);
		$this->assertNotSame(-1, $startIdx);
		$this->assertNotSame(-1, $endIdx);

		$this->assertSame('2024-01-15', $row[$dateIdx]);
		$this->assertSame('23:00:00', $row[$startIdx]);
		$this->assertSame('07:00:00', $row[$endIdx]);
	}

	/**
	 * Test absences export handles empty data
	 */
	public function testAbsencesExportHandlesEmptyData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceMapper->method('findByUserAndDateRange')
			->willReturn([]);

		$response = $this->controller->absences('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$this->assertStringContainsString('No data available', $content);
	}

	/**
	 * Build an absence entity for scoped-export tests.
	 */
	private function makeScopedAbsence(string $uid): Absence
	{
		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($uid);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-02'));
		$absence->setDays(2);
		$absence->setReason('Trip');
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment(null);
		$absence->setApprovedAt(null);
		$absence->setCreatedAt(new \DateTime('2024-05-01'));
		$absence->setUpdatedAt(new \DateTime('2024-05-01'));
		return $absence;
	}

	private function asUser(string $uid): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAbsencesScopedExportManagerTeamIncludesIdentityColumns(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);

		$this->absenceMapper->method('countByUsersAndDateRange')->willReturn(1);
		$this->absenceMapper->expects($this->once())
			->method('findByUsersAndDateRange')
			->with(['employee1'], $this->anything(), $this->anything(), null, null)
			->willReturn([$this->makeScopedAbsence('employee1')]);

		// Self-scope legacy mapper must not run for scoped exports.
		$this->absenceMapper->expects($this->never())->method('findByUserAndDateRange');

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, null, null, 'managed');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = (string)$response->render();
		$this->assertStringContainsString('user_id', $content);
		$this->assertStringContainsString('employee1', $content);
	}

	public function testAbsencesScopedExportForeignUidDenied(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);

		$this->absenceMapper->expects($this->never())->method('findByUsersAndDateRange');

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, 'employee1,foreign');
		$this->assertSame(403, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testAbsencesScopedExportGhostUidDenied(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1', 'ghost']);
		$this->missingUsers['ghost'] = true; // deleted account, stale member row

		$this->absenceMapper->expects($this->never())->method('findByUsersAndDateRange');

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, 'ghost');
		$this->assertSame(403, $response->getStatus());
	}

	public function testAbsencesOrgScopeRequiresAdmin(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, null, null, 'organization');
		$this->assertSame(403, $response->getStatus());
	}

	public function testAbsencesOrgScopeAdminExportsDoorFilteredUsers(): void
	{
		$this->asUser('admin1');
		$this->permissionService->method('isAdmin')->with('admin1')->willReturn(true);
		$this->permissionService->method('listAppAccessUserIds')->willReturn(['orguser1']);

		$this->absenceMapper->method('countByUsersAndDateRange')->willReturn(1);
		$this->absenceMapper->expects($this->once())
			->method('findByUsersAndDateRange')
			->with(['orguser1'], $this->anything(), $this->anything(), null, null)
			->willReturn([$this->makeScopedAbsence('orguser1')]);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, null, null, 'organization');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('orguser1', (string)$response->render());
	}

	public function testAbsencesScopedExportAmbiguousSelectorsRejected(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, 'employee1', 'employee2');
		$this->assertSame(400, $response->getStatus());
	}

	public function testAbsencesScopedExportFailsLoudOnRowCap(): void
	{
		$this->asUser('admin1');
		$this->permissionService->method('isAdmin')->with('admin1')->willReturn(true);
		$this->permissionService->method('listAppAccessUserIds')->willReturn(['u1']);

		$this->absenceMapper->method('countByUsersAndDateRange')->willReturn(50001);
		$this->absenceMapper->expects($this->never())->method('findByUsersAndDateRange');

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30', null, null, null, null, 'organization');
		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Too many rows', $response->getData()['error']);
	}

	public function testComplianceScopedExportParsesResolvedStringStrictly(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);

		// "false" must parse to false — not truthy — so only UNRESOLVED
		// violations are exported.
		$this->violationMapper->expects($this->once())
			->method('countByUserIdsAndDateRange')
			->with(['employee1'], $this->anything(), $this->anything(), null, null, false)
			->willReturn(0);
		$this->violationMapper->expects($this->once())
			->method('findByUserIdsAndDateRange')
			->with(['employee1'], $this->anything(), $this->anything(), null, null, false)
			->willReturn([]);

		$response = $this->controller->compliance('csv', '2024-06-01', '2024-06-30', null, null, null, 'managed', null, null, 'false');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testComplianceScopedExportRejectsBogusResolved(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);

		$response = $this->controller->compliance('csv', '2024-06-01', '2024-06-30', null, null, null, 'managed', null, null, 'maybe');
		$this->assertSame(400, $response->getStatus());
	}

	public function testComplianceScopedExportAuditLogged(): void
	{
		$this->asUser('manager1');
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);

		$this->violationMapper->method('countByUserIdsAndDateRange')->willReturn(0);
		$this->violationMapper->method('findByUserIdsAndDateRange')->willReturn([]);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with('manager1', 'export_download', 'compliance_export', null, null, $this->callback(
				static fn ($ctx): bool => is_array($ctx) && ($ctx['scope'] ?? '') === 'managed' && ($ctx['user_count'] ?? 0) === 1
			));

		$response = $this->controller->compliance('csv', '2024-06-01', '2024-06-30', null, null, null, 'managed');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}
}
