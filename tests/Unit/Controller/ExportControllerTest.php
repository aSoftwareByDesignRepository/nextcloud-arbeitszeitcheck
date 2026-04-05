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

		$this->controller = new ExportController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			new TimeEntryExportTransformer(),
			$this->userSession,
			$this->l10n,
			$this->config
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
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
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

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		// PDF export falls back to CSV, so filename should be CSV
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('compliance-report-', $contentDisposition);
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
	}

	/**
	 * Test datevConfig handles exceptions
	 */
	public function testDatevConfigHandlesException(): void
	{
		$this->datevExportService->expects($this->once())
			->method('getConfigurationStatus')
			->willThrowException(new \Exception('Configuration error'));

		$response = $this->controller->datevConfig();

		$this->assertEquals(\OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Configuration error', $data['error']);
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

		// Erste Datenzeile: 15.01., 22:00:00–23:59:59
		$this->assertSame('2024-01-15', $row1[$dateIdx]);
		$this->assertSame('22:00:00', $row1[$startIdx]);
		$this->assertSame('23:59:59', $row1[$endIdx]);

		// Zweite Datenzeile: 16.01., 00:00:00–06:00:00
		$this->assertSame('2024-01-16', $row2[$dateIdx]);
		$this->assertSame('00:00:00', $row2[$startIdx]);
		$this->assertSame('06:00:00', $row2[$endIdx]);

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
			new TimeEntryExportTransformer(),
			$this->userSession,
			$this->l10n,
			$config
		);

		$response = $controller->timeEntries('csv', '2024-01-15', '2024-01-16');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();

		$lines = array_values(array_filter(explode("\n", trim($content))));
		// CSV exports may contain an Excel separator hint line: "sep=,"
		if (isset($lines[0]) && str_starts_with($lines[0], 'sep=')) {
			array_shift($lines);
		}
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
		$this->assertSame('22:00:00', $row[$startIdx]);
		$this->assertSame('06:00:00', $row[$endIdx]);
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
}
