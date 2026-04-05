<?php

declare(strict_types=1);

/**
 * Unit tests for TimeEntryController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\TimeEntryController;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Class TimeEntryControllerTest
 */
class TimeEntryControllerTest extends TestCase
{
	/** @var TimeEntryController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var OvertimeService|\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeService;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var ComplianceService|\PHPUnit\Framework\MockObject\MockObject */
	private $complianceService;

	/** @var TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeTrackingService;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn ($s, $p = []) => $p ? (string)vsprintf($s, $p) : $s);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());
		$this->config = $this->createMock(IConfig::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($r) => $r);
		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn([
			'valid' => true,
			'message' => '',
		]);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->timeTrackingService->method('calculateAndSetAutomaticBreak');
		$this->timeTrackingService->method('adjustEndTimeForDailyMaximum');
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getColleagueIds')->willReturn([]);
		$this->notificationService = $this->createMock(NotificationService::class);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->controller = new TimeEntryController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->userSession,
			$this->overtimeService,
			$this->urlGenerator,
			$this->l10n,
			$this->auditLogMapper,
			$this->config,
			$this->cspService,
			$this->complianceService,
			$this->timeTrackingService,
			$this->teamResolver,
			$this->notificationService
		);
	}

	/**
	 * Test index returns time entries with filters
	 */
	public function testIndexReturnsEntries(): void
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
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('count')
			->willReturn(1);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->index('2024-01-01', '2024-01-31');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entries', $data);
		$this->assertEquals(1, $data['total']);
	}

	/**
	 * Test index applies status filter
	 */
	public function testIndexAppliesStatusFilter(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$completedEntry = new TimeEntry();
		$completedEntry->setId(1);
		$completedEntry->setUserId($userId);
		$completedEntry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$completedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$completedEntry->setIsManualEntry(false);
		$completedEntry->setCreatedAt(new \DateTime());
		$completedEntry->setUpdatedAt(new \DateTime());

		$activeEntry = new TimeEntry();
		$activeEntry->setId(2);
		$activeEntry->setUserId($userId);
		$activeEntry->setStartTime(new \DateTime('2024-01-16 09:00:00'));
		$activeEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$activeEntry->setIsManualEntry(false);
		$activeEntry->setCreatedAt(new \DateTime());
		$activeEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('count')->willReturn(1);
		$this->timeEntryMapper->method('findByUser')
			->willReturn([$completedEntry]);

		$response = $this->controller->index(null, null, 'completed');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['entries']); // Only completed entry
	}

	/**
	 * Test index applies pagination
	 */
	public function testIndexAppliesPagination(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entries = [];
		for ($i = 1; $i <= 10; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->method('count')->willReturn(10);
		$this->timeEntryMapper->method('findByUser')
			->willReturn($entries);

		$response = $this->controller->index(null, null, null, 5, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(5, $data['entries']); // Limited to 5
	}

	/**
	 * Test show returns entry when user owns it
	 */
	public function testShowReturnsEntryWhenOwned(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('find')
			->with($entryId)
			->willReturn($entry);

		$response = $this->controller->show($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
	}

	/**
	 * Test show returns forbidden when user doesn't own entry
	 */
	public function testShowReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->show($entryId);

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Access denied', $data['error']);
	}

	/**
	 * Test store creates manual time entry
	 */
	public function testStoreCreatesManualEntry(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$savedEntry = $this->createMock(TimeEntry::class);
		$savedEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function ($entry) use ($userId) {
				return $entry instanceof TimeEntry
					&& $entry->getUserId() === $userId
					&& $entry->getIsManualEntry() === true
					&& $entry->getStatus() === TimeEntry::STATUS_COMPLETED;
			}))
			->willReturn($savedEntry);

		$response = $this->controller->store('2024-01-15', 8.0, 'Work description');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
	}

	/**
	 * Test store calculates end time from hours
	 */
	public function testStoreCalculatesEndTimeFromHours(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$savedEntry = $this->createMock(TimeEntry::class);
		$savedEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function ($entry) {
				$startTime = $entry->getStartTime();
				$endTime = $entry->getEndTime();
				if (!$startTime || !$endTime) {
					return false;
				}
				// End time should be 8 hours after start time (9:00 + 8 hours = 17:00)
				$diff = $endTime->getTimestamp() - $startTime->getTimestamp();
				return abs($diff - (8 * 3600)) < 60; // Allow 1 minute tolerance
			}))
			->willReturn($savedEntry);

		$response = $this->controller->store('2024-01-15', 8.0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test update modifies entry when user owns it
	 */
	public function testUpdateModifiesEntryWhenOwned(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setJustification('Initial manual entry justification');
		$entryStart = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$entryEnd = (clone $entryStart)->modify('+8 hours');
		$entry->setStartTime($entryStart);
		$entry->setEndTime($entryEnd);
		$entry->setDescription('Original description');
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($userId);
		$updatedEntry->setIsManualEntry(true);
		$updatedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$updatedEntry->setJustification('Initial manual entry justification');
		$updatedStart = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$updatedEnd = (clone $updatedStart)->modify('+7 hours 30 minutes');
		$updatedEntry->setStartTime($updatedStart);
		$updatedEntry->setEndTime($updatedEnd);
		$updatedEntry->setDescription('Updated description');
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->request->method('getParams')->willReturn([
			'justification' => 'Fix manual entry',
		]);
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->update($entryId, $updatedStart->format('Y-m-d'), 7.5, 'Updated description');
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
		$this->assertArrayHasKey('entry', $data);
	}

	/**
	 * Test update returns forbidden when user doesn't own entry
	 */
	public function testUpdateReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->update($entryId, '2024-01-16');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test update returns error when entry is not manual
	 */
	public function testUpdateReturnsErrorWhenNotManual(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->update($entryId, '2024-01-16');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Only entries from the last 2 weeks', $data['error']);
	}

	/**
	 * Test requestCorrection creates correction request
	 */
	public function testRequestCorrectionCreatesRequest(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getColleagueIds')->willReturn(['manager1']);
		$this->request->expects($this->once())->method('getParams')->willReturn([
			'justification' => 'Wrong time recorded',
			'newDate' => '2024-01-16',
			'newHours' => 7.5,
			'newDescription' => 'Corrected description',
		]);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setDescription('Original');
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($userId);
		$updatedEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$updatedEntry->setStartTime(new \DateTime('2024-01-16 09:00:00'));
		$updatedEntry->setEndTime(new \DateTime('2024-01-16 16:30:00'));
		$updatedEntry->setDescription('Corrected description');
		$updatedEntry->setJustification('Wrong time recorded');
		$updatedEntry->setIsManualEntry(false);
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->requestCorrection($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test requestCorrection returns error when already pending
	 */
	public function testRequestCorrectionReturnsErrorWhenPending(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->requestCorrection($entryId, 'Justification');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('already pending', $data['error']);
	}

	/**
	 * Test requestCorrection requires justification
	 */
	public function testRequestCorrectionRequiresJustification(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->requestCorrection($entryId, '');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Justification is required', $data['error']);
	}

	/**
	 * Test delete removes manual entry
	 */
	public function testDeleteRemovesManualEntry(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())
			->method('delete')
			->with($entry);

		$response = $this->controller->delete($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test delete returns error when entry is not manual
	 */
	public function testDeleteReturnsErrorWhenNotManual(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->delete($entryId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Cannot delete automatic', $data['error']);
	}

	// NOTE: A legacy `stats()` endpoint existed previously but is no longer part of `TimeEntryController`.

	/**
	 * Test getOvertime returns overtime data
	 */
	public function testGetOvertimeReturnsData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getOvertime('monthly');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('overtime', $data);
	}

	/**
	 * Test getOvertimeBalance returns balance
	 */
	public function testGetOvertimeBalanceReturnsBalance(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getOvertimeBalance();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('balance', $data);
	}

	/**
	 * Test apiStore creates entry from JSON body
	 */
	public function testApiStoreCreatesEntryFromJson(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn([
				'date' => '2024-01-15',
				'hours' => 8.0,
				'description' => 'Work',
				'projectCheckProjectId' => 'project123'
			]);

		$savedEntry = $this->createMock(TimeEntry::class);
		$savedEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($savedEntry);

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
	}

	/**
	 * Test apiStore returns error when date or hours missing
	 */
	public function testApiStoreReturnsErrorWhenMissingRequired(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['date' => '2024-01-15']); // Missing hours

		$response = $this->controller->apiStore();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Either (date and hours) or (startTime and endTime) are required', $data['error']);
	}
}
