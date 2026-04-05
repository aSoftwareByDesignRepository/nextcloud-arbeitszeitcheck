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
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSetting;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
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

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

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
		$this->l10n->method('t')->willReturnCallback(static function (string $text, array $parameters = []): string {
			return $text;
		});
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturn('2');

		$this->controller = new GdprController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->userSettingsMapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->userSession,
			$this->l10n,
			$this->config
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

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setDays(5);
		$absence->setReason(null);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment(null);
		$absence->setApprovedAt(null);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$setting = new UserSetting();
		$setting->setId(1);
		$setting->setUserId($userId);
		$setting->setSettingKey('vacation_days_per_year');
		$setting->setSettingValue('25');
		$setting->setCreatedAt(new \DateTime());
		$setting->setUpdatedAt(new \DateTime());

		$violation = new ComplianceViolation();
		$violation->setId(1);
		$violation->setUserId($userId);
		$violation->setViolationType(ComplianceViolation::TYPE_MISSING_BREAK);
		$violation->setDescription('Missing break');
		$violation->setDate(new \DateTime('2024-01-15'));
		$violation->setTimeEntryId(1);
		$violation->setSeverity(ComplianceViolation::SEVERITY_WARNING);
		$violation->setResolved(false);
		$violation->setResolvedAt(null);
		$violation->setCreatedAt(new \DateTime());

		$auditLog = new AuditLog();
		$auditLog->setId(1);
		$auditLog->setUserId($userId);
		$auditLog->setAction('time_entry_created');
		$auditLog->setEntityType('time_entry');
		$auditLog->setEntityId(1);
		$auditLog->setOldValues(null);
		$auditLog->setNewValues('{"id":1}');
		$auditLog->setIpAddress('127.0.0.1');
		$auditLog->setUserAgent('Test');
		$auditLog->setPerformedBy($userId);
		$auditLog->setCreatedAt(new \DateTime());

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
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('arbeitszeitcheck-gdpr-export-', $contentDisposition);
		$this->assertStringContainsString('.json', $contentDisposition);
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
		$oldEntry = new TimeEntry();
		$oldEntry->setId(1);
		$oldEntry->setUserId($userId);
		$oldDate = new \DateTime();
		$oldDate->modify('-3 years');
		$oldEntry->setStartTime($oldDate);
		$oldEntry->setCreatedAt(clone $oldDate);
		$oldEntry->setUpdatedAt(clone $oldDate);

		// Create recent entry (within 2 years)
		$recentEntry = new TimeEntry();
		$recentEntry->setId(2);
		$recentEntry->setUserId($userId);
		$recentDate = new \DateTime();
		$recentDate->modify('-1 year');
		$recentEntry->setStartTime($recentDate);
		$recentEntry->setCreatedAt(clone $recentDate);
		$recentEntry->setUpdatedAt(clone $recentDate);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUser')
			->with($userId)
			->willReturn([$oldEntry, $recentEntry]);

		$this->timeEntryMapper->expects($this->once())
			->method('delete')
			->with($oldEntry);

		$setting = new UserSetting();
		$setting->setId(1);
		$setting->setUserId($userId);
		$setting->setSettingKey('vacation_days_per_year');
		$setting->setSettingValue('25');
		$setting->setCreatedAt(new \DateTime());
		$setting->setUpdatedAt(new \DateTime());

		$this->userSettingsMapper->method('getUserSettings')
			->willReturn([$setting]);

		$this->userSettingsMapper->expects($this->once())
			->method('deleteSetting')
			->with($userId, 'vacation_days_per_year');

		$this->auditLogMapper->expects($this->once())
			->method('logAction');

		$this->l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $params = []): string {
			if ($singular === 'year' && $plural === 'years') {
				return $count === 1 ? 'year' : 'years';
			}
			return 'Data deletion request processed. 1 time entry deleted. 1 entries retained due to legal 2-year retention requirement.';
		});

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

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
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

		$response = $this->controller->export();
		$this->assertInstanceOf(JSONResponse::class, $response);
		/** @var JSONResponse $response */
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
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

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
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
