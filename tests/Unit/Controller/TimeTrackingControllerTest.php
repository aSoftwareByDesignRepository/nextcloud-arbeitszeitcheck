<?php

declare(strict_types=1);

/**
 * Unit tests for TimeTrackingController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\TimeTrackingController;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Class TimeTrackingControllerTest
 */
class TimeTrackingControllerTest extends TestCase
{
	/** @var TimeTrackingController */
	private $controller;

	/** @var TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeTrackingService;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new TimeTrackingController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeTrackingService,
			$this->userSession
		);
	}

	/**
	 * Test clockIn returns success response
	 */
	public function testClockInSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getSummary')
			->willReturn([
				'id' => 1,
				'user_id' => $userId,
				'status' => 'active'
			]);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->with($userId, null, null)
			->willReturn($timeEntry);

		$response = $this->controller->clockIn();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('timeEntry', $data);
	}

	/**
	 * Test clockIn with project and description
	 */
	public function testClockInWithProjectAndDescription(): void
	{
		$userId = 'testuser';
		$projectId = 'project123';
		$description = 'Working on feature';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->with($userId, $projectId, $description)
			->willReturn($timeEntry);

		$response = $this->controller->clockIn($projectId, $description);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test clockIn returns error when user not authenticated
	 */
	public function testClockInReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->clockIn();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test clockIn handles service exceptions
	 */
	public function testClockInHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->willThrowException(new \Exception('Already clocked in'));

		$response = $this->controller->clockIn();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Already clocked in', $data['error']);
	}

	/**
	 * Test clockOut returns success response
	 */
	public function testClockOutSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getSummary')
			->willReturn([
				'id' => 1,
				'status' => 'completed'
			]);

		$this->timeTrackingService->expects($this->once())
			->method('clockOut')
			->with($userId)
			->willReturn($timeEntry);

		$response = $this->controller->clockOut();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('timeEntry', $data);
	}

	/**
	 * Test clockOut handles exceptions
	 */
	public function testClockOutHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('clockOut')
			->willThrowException(new \Exception('No active time entry'));

		$response = $this->controller->clockOut();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test getStatus returns current status
	 */
	public function testGetStatusSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$status = [
			'hasActiveEntry' => true,
			'currentEntry' => ['id' => 1]
		];

		$this->timeTrackingService->expects($this->once())
			->method('getStatus')
			->with($userId)
			->willReturn($status);

		$response = $this->controller->getStatus();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals($status, $data['status']);
	}

	/**
	 * Test startBreak returns success response
	 */
	public function testStartBreakSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getSummary')->willReturn(['id' => 1, 'status' => 'break']);

		$this->timeTrackingService->expects($this->once())
			->method('startBreak')
			->with($userId)
			->willReturn($timeEntry);

		$response = $this->controller->startBreak();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('timeEntry', $data);
	}

	/**
	 * Test endBreak returns success response
	 */
	public function testEndBreakSuccess(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getSummary')->willReturn(['id' => 1, 'status' => 'active']);

		$this->timeTrackingService->expects($this->once())
			->method('endBreak')
			->with($userId)
			->willReturn($timeEntry);

		$response = $this->controller->endBreak();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('timeEntry', $data);
	}

	/**
	 * Test startBreak handles exceptions
	 */
	public function testStartBreakHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('startBreak')
			->willThrowException(new \Exception('No active time entry'));

		$response = $this->controller->startBreak();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test endBreak handles exceptions
	 */
	public function testEndBreakHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('endBreak')
			->willThrowException(new \Exception('No active break'));

		$response = $this->controller->endBreak();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}
}
