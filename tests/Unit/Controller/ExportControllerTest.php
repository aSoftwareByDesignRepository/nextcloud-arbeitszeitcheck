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
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
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

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->datevExportService = $this->createMock(DatevExportService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ExportController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->datevExportService,
			$this->userSession
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

		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getId')->willReturn(1);
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getBreakStartTime')->willReturn(null);
		$entry->method('getBreakEndTime')->willReturn(null);
		$entry->method('getDurationHours')->willReturn(8.0);
		$entry->method('getBreakDurationHours')->willReturn(0.0);
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('Work');
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getIsManualEntry')->willReturn(false);
		$entry->method('getProjectCheckProjectId')->willReturn(null);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->timeEntries('csv', '2024-01-01', '2024-01-31');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('time-entries-', $response->getFilename());
		$this->assertStringContainsString('.csv', $response->getFilename());
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

		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getId')->willReturn(1);
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getBreakStartTime')->willReturn(null);
		$entry->method('getBreakEndTime')->willReturn(null);
		$entry->method('getDurationHours')->willReturn(8.0);
		$entry->method('getBreakDurationHours')->willReturn(0.0);
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('Work');
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getIsManualEntry')->willReturn(false);
		$entry->method('getProjectCheckProjectId')->willReturn(null);

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->timeEntries('json');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('.json', $response->getFilename());
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

		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getId')->willReturn(1);
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getBreakStartTime')->willReturn(null);
		$entry->method('getBreakEndTime')->willReturn(null);
		$entry->method('getDurationHours')->willReturn(8.0);
		$entry->method('getBreakDurationHours')->willReturn(0.0);
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('Work');
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getIsManualEntry')->willReturn(false);
		$entry->method('getProjectCheckProjectId')->willReturn(null);

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
		$this->assertStringContainsString('datev-export-', $response->getFilename());
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

		$absence = $this->createMock(\OCA\ArbeitszeitCheck\Db\Absence::class);
		$absence->method('getId')->willReturn(1);
		$absence->method('getType')->willReturn('vacation');
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getReason')->willReturn('Summer vacation');
		$absence->method('getStatus')->willReturn('approved');
		$absence->method('getApproverComment')->willReturn(null);
		$absence->method('getApprovedAt')->willReturn(null);
		$absence->method('getCreatedAt')->willReturn(new \DateTime('2024-05-01'));

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$response = $this->controller->absences('csv', '2024-06-01', '2024-06-30');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('absences-', $response->getFilename());
		$this->assertStringContainsString('.csv', $response->getFilename());
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

		$violation = $this->createMock(\OCA\ArbeitszeitCheck\Db\ComplianceViolation::class);
		$violation->method('getId')->willReturn(1);
		$violation->method('getDate')->willReturn(new \DateTime('2024-01-15'));
		$violation->method('getViolationType')->willReturn('missing_break');
		$violation->method('getDescription')->willReturn('Missing break');
		$violation->method('getSeverity')->willReturn('warning');
		$violation->method('getResolved')->willReturn(false);
		$violation->method('getResolvedAt')->willReturn(null);
		$violation->method('getTimeEntryId')->willReturn(1);

		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->willReturn([$violation]);

		$response = $this->controller->compliance('pdf', '2024-01-01', '2024-01-31');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		// PDF export falls back to CSV, so filename should be CSV
		$this->assertStringContainsString('compliance-report-', $response->getFilename());
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
		$this->assertEquals('Configuration error', $data['error']);
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

		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getId')->willReturn(1);
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getBreakStartTime')->willReturn(null);
		$entry->method('getBreakEndTime')->willReturn(null);
		$entry->method('getDurationHours')->willReturn(8.0);
		$entry->method('getBreakDurationHours')->willReturn(0.0);
		$entry->method('getWorkingDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('Work');
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getIsManualEntry')->willReturn(false);
		$entry->method('getProjectCheckProjectId')->willReturn(null);

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$this->datevExportService->expects($this->once())
			->method('exportTimeEntries')
			->willThrowException(new \Exception('DATEV export failed'));

		$response = $this->controller->timeEntries('datev');

		// Should return CSV with error message
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('datev-export-error-', $response->getFilename());
	}

	/**
	 * Test timeEntries export returns error when user not authenticated
	 */
	public function testTimeEntriesExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User not authenticated');

		$this->controller->timeEntries('csv');
	}

	/**
	 * Test absences export returns error when user not authenticated
	 */
	public function testAbsencesExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User not authenticated');

		$this->controller->absences('csv');
	}

	/**
	 * Test compliance export returns error when user not authenticated
	 */
	public function testComplianceExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User not authenticated');

		$this->controller->compliance('csv');
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
