<?php

declare(strict_types=1);

/**
 * Tests for TimeTrackingService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class TimeTrackingServiceTest
 */
class TimeTrackingServiceTest extends TestCase {

	/** @var TimeTrackingService */
	private $service;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var ProjectCheckIntegrationService|\PHPUnit\Framework\MockObject\MockObject */
	private $projectCheckService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->projectCheckService = $this->createMock(ProjectCheckIntegrationService::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->service = new TimeTrackingService(
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->projectCheckService,
			$this->l10n
		);
	}

	/**
	 * Test that clocking in when already clocked in throws exception
	 */
	public function testClockInWhenAlreadyActiveThrowsException(): void {
		$userId = 'testuser';

		// Mock that user is already clocked in
		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn($this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class));

		$this->l10n->expects($this->once())
			->method('t')
			->with('User is already clocked in')
			->willReturn('User is already clocked in');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User is already clocked in');

		$this->service->clockIn($userId);
	}

	/**
	 * Test successful clock in
	 */
	public function testClockInSuccess(): void {
		$userId = 'testuser';
		$projectId = 'proj123';
		$description = 'Working on project';

		// Mock that user is not clocked in
		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		// Mock that user is not on break
		$this->timeEntryMapper->expects($this->once())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		// Mock project validation
		$this->projectCheckService->expects($this->once())
			->method('projectExists')
			->with($projectId)
			->willReturn(true);

		// Mock compliance check (no violations)
		$this->violationMapper->expects($this->never())
			->method('createViolation');

		// Mock time entry creation and saving
		$mockEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($mockEntry);

		// Mock audit logging
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'clock_in', 'time_entry', $this->anything(), null, $this->anything());

		$result = $this->service->clockIn($userId, $projectId, $description);

		$this->assertSame($mockEntry, $result);
	}

	/**
	 * Test clocking in with invalid project throws exception
	 */
	public function testClockInWithInvalidProjectThrowsException(): void {
		$userId = 'testuser';
		$projectId = 'invalid123';

		// Mock that user is not clocked in
		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		// Mock that user is not on break
		$this->timeEntryMapper->expects($this->once())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		// Mock project validation - project doesn't exist
		$this->projectCheckService->expects($this->once())
			->method('projectExists')
			->with($projectId)
			->willReturn(false);

		$this->l10n->expects($this->once())
			->method('t')
			->with('Selected project does not exist')
			->willReturn('Selected project does not exist');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Selected project does not exist');

		$this->service->clockIn($userId, $projectId);
	}

	/**
	 * Test getting current status
	 */
	public function testGetStatus(): void {
		$userId = 'testuser';

		// Mock active entry
		$mockEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$mockEntry->method('getStatus')->willReturn('active');
		$mockEntry->method('getSummary')->willReturn(['id' => 1, 'status' => 'active']);

		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn($mockEntry);

		$this->timeEntryMapper->expects($this->once())
			->method('getTotalHoursByUserAndDateRange')
			->willReturn(8.5);

		$result = $this->service->getStatus($userId);

		$this->assertEquals('active', $result['status']);
		$this->assertEquals(8.5, $result['working_today_hours']);
	}

	/**
	 * Test getting status when not clocked in
	 */
	public function testGetStatusWhenNotActive(): void {
		$userId = 'testuser';

		// Mock no active entry
		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		// Mock no break entry
		$this->timeEntryMapper->expects($this->once())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		$this->timeEntryMapper->expects($this->once())
			->method('getTotalHoursByUserAndDateRange')
			->willReturn(0);

		$result = $this->service->getStatus($userId);

		$this->assertEquals('clocked_out', $result['status']);
		$this->assertNull($result['current_entry']);
		$this->assertEquals(0, $result['working_today_hours']);
	}
}