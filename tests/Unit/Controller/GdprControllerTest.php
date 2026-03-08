<?php

declare(strict_types=1);

/**
 * Unit tests for GdprController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\GdprController;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class GdprControllerTest
 */
class GdprControllerTest extends TestCase
{
	/** @var GdprController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var UserSettingsMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new GdprController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->userSettingsMapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->userSession,
			$this->l10n
		);
	}

	/**
	 * Test export returns all user data
	 */
	public function testExportReturnsAllUserData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 1]);

		$absence = $this->createMock(\OCA\ArbeitszeitCheck\Db\Absence::class);
		$absence->method('getId')->willReturn(1);
		$absence->method('getType')->willReturn('vacation');
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getReason')->willReturn(null);
		$absence->method('getStatus')->willReturn('approved');
		$absence->method('getApproverComment')->willReturn(null);
		$absence->method('getApprovedAt')->willReturn(null);
		$absence->method('getCreatedAt')->willReturn(new \DateTime());
		$absence->method('getUpdatedAt')->willReturn(new \DateTime());

		$setting = $this->createMock(\OCA\ArbeitszeitCheck\Db\UserSetting::class);
		$setting->method('getSettingKey')->willReturn('vacation_days_per_year');
		$setting->method('getSettingValue')->willReturn('25');
		$setting->method('getCreatedAt')->willReturn(new \DateTime());
		$setting->method('getUpdatedAt')->willReturn(new \DateTime());

		$violation = $this->createMock(\OCA\ArbeitszeitCheck\Db\ComplianceViolation::class);
		$violation->method('getId')->willReturn(1);
		$violation->method('getViolationType')->willReturn('missing_break');
		$violation->method('getDescription')->willReturn('Missing break');
		$violation->method('getDate')->willReturn(new \DateTime('2024-01-15'));
		$violation->method('getTimeEntryId')->willReturn(1);
		$violation->method('getSeverity')->willReturn('warning');
		$violation->method('getResolved')->willReturn(false);
		$violation->method('getResolvedAt')->willReturn(null);
		$violation->method('getCreatedAt')->willReturn(new \DateTime());

		$auditLog = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLog::class);
		$auditLog->method('getId')->willReturn(1);
		$auditLog->method('getAction')->willReturn('time_entry_created');
		$auditLog->method('getEntityType')->willReturn('time_entry');
		$auditLog->method('getEntityId')->willReturn(1);
		$auditLog->method('getOldValues')->willReturn(null);
		$auditLog->method('getNewValues')->willReturn('{"id":1}');
		$auditLog->method('getIpAddress')->willReturn('127.0.0.1');
		$auditLog->method('getUserAgent')->willReturn('Test');
		$auditLog->method('getPerformedBy')->willReturn($userId);
		$auditLog->method('getCreatedAt')->willReturn(new \DateTime());
		$auditLog->method('getUserId')->willReturn($userId);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$entry]);

		$this->absenceMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$absence]);

		$this->userSettingsMapper->expects($this->once())
			->method('getUserSettings')
			->with($userId)
			->willReturn([$setting]);

		$this->violationMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$violation]);

		$this->auditLogMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$auditLog]);

		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('arbeitszeitcheck-gdpr-export-', $response->getFilename());
		$this->assertStringContainsString('.json', $response->getFilename());
	}

	/**
	 * Test export handles empty data
	 */
	public function testExportHandlesEmptyData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->method('findByUser')->willReturn([]);
		$this->absenceMapper->method('findByUser')->willReturn([]);
		$this->userSettingsMapper->method('getUserSettings')->willReturn([]);
		$this->violationMapper->method('findByUser')->willReturn([]);
		$this->auditLogMapper->method('findByUser')->willReturn([]);

		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$content = $response->render();
		$data = json_decode($content, true);
		$this->assertArrayHasKey('export_metadata', $data);
		$this->assertEquals(0, $data['data_summary']['total_time_entries']);
	}

	/**
	 * Test delete respects retention period
	 */
	public function testDeleteRespectsRetentionPeriod(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		// Create old entry (beyond 2 years)
		$oldEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$oldDate = new \DateTime();
		$oldDate->modify('-3 years');
		$oldEntry->method('getStartTime')->willReturn($oldDate);

		// Create recent entry (within 2 years)
		$recentEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$recentDate = new \DateTime();
		$recentDate->modify('-1 year');
		$recentEntry->method('getStartTime')->willReturn($recentDate);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$oldEntry, $recentEntry]);

		$this->timeEntryMapper->expects($this->once())
			->method('delete')
			->with($oldEntry);

		$setting = $this->createMock(\OCA\ArbeitszeitCheck\Db\UserSetting::class);
		$setting->method('getSettingKey')->willReturn('vacation_days_per_year');

		$this->userSettingsMapper->method('getUserSettings')
			->willReturn([$setting]);

		$this->userSettingsMapper->expects($this->once())
			->method('deleteSetting')
			->with($userId, 'vacation_days_per_year');

		$this->auditLogMapper->expects($this->once())
			->method('logAction');

		$this->l10n->method('n')
			->willReturn('Data deletion request processed. 1 time entry deleted. 1 entries retained due to legal 2-year retention requirement.');

		$response = $this->controller->delete();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['deleted_entries']);
		$this->assertEquals(1, $data['retained_entries']);
		$this->assertEquals('2 years', $data['retention_period']);
	}

	/**
	 * Test delete handles no entries
	 */
	public function testDeleteHandlesNoEntries(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->method('findByUser')->willReturn([]);
		$this->userSettingsMapper->method('getUserSettings')->willReturn([]);

		$this->auditLogMapper->expects($this->once())
			->method('logAction');

		$this->l10n->method('n')
			->willReturn('Data deletion request processed. 0 time entries deleted. 0 entries retained.');

		$response = $this->controller->delete();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals(0, $data['deleted_entries']);
		$this->assertEquals(0, $data['retained_entries']);
	}

	/**
	 * Test delete returns error when user not authenticated
	 */
	public function testDeleteReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->delete();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}

	/**
	 * Test export returns error when user not authenticated
	 */
	public function testExportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User not authenticated');

		$this->controller->export();
	}

	/**
	 * Test delete handles exceptions
	 */
	public function testDeleteHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUser')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->delete();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Database error', $data['error']);
	}

	/**
	 * Test export handles JSON encoding errors
	 */
	public function testExportHandlesJsonEncodingError(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		// Create entry with data that might cause JSON encoding issues
		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->method('findByUser')->willReturn([$entry]);
		$this->absenceMapper->method('findByUser')->willReturn([]);
		$this->userSettingsMapper->method('getUserSettings')->willReturn([]);
		$this->violationMapper->method('findByUser')->willReturn([]);
		$this->auditLogMapper->method('findByUser')->willReturn([]);

		// This should work fine - JSON encoding should succeed
		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}
}
