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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new TimeEntryController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->userSession
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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getSummary')->willReturn(['id' => 1]);
		$entry->method('getStatus')->willReturn('completed');

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

		$completedEntry = $this->createMock(TimeEntry::class);
		$completedEntry->method('getSummary')->willReturn(['id' => 1]);
		$completedEntry->method('getStatus')->willReturn('completed');

		$activeEntry = $this->createMock(TimeEntry::class);
		$activeEntry->method('getSummary')->willReturn(['id' => 2]);
		$activeEntry->method('getStatus')->willReturn('active');

		$this->timeEntryMapper->method('count')->willReturn(1);
		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturn([$completedEntry, $activeEntry]);

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
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getSummary')->willReturn(['id' => $i]);
			$entry->method('getStatus')->willReturn('completed');
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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getSummary')->willReturn(['id' => $entryId]);

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($otherUserId);

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getIsManualEntry')->willReturn(true);
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('setStartTime')->willReturnSelf();
		$entry->method('setEndTime')->willReturnSelf();
		$entry->method('setDescription')->willReturnSelf();
		$entry->method('setUpdatedAt')->willReturnSelf();

		$updatedEntry = $this->createMock(TimeEntry::class);
		$updatedEntry->method('getSummary')->willReturn(['id' => $entryId]);

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->update($entryId, '2024-01-16', 7.5, 'Updated description');
		$data = $response->getData();

		$this->assertTrue($data['success']);
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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($otherUserId);

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getIsManualEntry')->willReturn(false);
		$entry->method('getStatus')->willReturn('completed');

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->update($entryId, '2024-01-16');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Cannot edit automatic', $data['error']);
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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getStatus')->willReturn('completed');
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('getDurationHours')->willReturn(8.0);
		$entry->method('getDescription')->willReturn('Original');
		$entry->method('setJustification')->willReturnSelf();
		$entry->method('setStatus')->willReturnSelf();
		$entry->method('setStartTime')->willReturnSelf();
		$entry->method('setEndTime')->willReturnSelf();
		$entry->method('setDescription')->willReturnSelf();
		$entry->method('setUpdatedAt')->willReturnSelf();

		$updatedEntry = $this->createMock(TimeEntry::class);
		$updatedEntry->method('getSummary')->willReturn(['id' => $entryId]);

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->requestCorrection(
			$entryId,
			'Wrong time recorded',
			'2024-01-16',
			7.5,
			'Corrected description'
		);
		$data = $response->getData();

		$this->assertTrue($data['success']);
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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getStatus')->willReturn('pending_approval');

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getStatus')->willReturn('completed');

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getIsManualEntry')->willReturn(true);

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

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getUserId')->willReturn($userId);
		$entry->method('getIsManualEntry')->willReturn(false);

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->delete($entryId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Cannot delete automatic', $data['error']);
	}

	/**
	 * Test stats returns statistics
	 */
	public function testStatsReturnsStatistics(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeEntryMapper->expects($this->once())
			->method('getTotalHoursByUserAndDateRange')
			->willReturn(160.0);

		$this->timeEntryMapper->expects($this->once())
			->method('getTotalBreakHoursByUserAndDateRange')
			->willReturn(20.0);

		$this->timeEntryMapper->expects($this->once())
			->method('countByUser')
			->willReturn(20);

		$response = $this->controller->stats('2024-01-01', '2024-01-31');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('stats', $data);
		$this->assertEquals(160.0, $data['stats']['total_hours']);
		$this->assertEquals(20.0, $data['stats']['total_break_hours']);
		$this->assertEquals(20, $data['stats']['total_entries']);
	}

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

		$this->assertTrue($data['success']);
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
		$this->assertStringContainsString('Date and hours are required', $data['error']);
	}
}
