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
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s, $p = []) => $p ? (string)vsprintf($s, $p) : $s);

		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'UTC',
			default => $default,
		});
		$tzDateTime = $this->createMock(IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());
		$idempotency = $this->createMock(MobileStampIdempotencyMapper::class);
		$idempotency->method('findByUserAndRequestId')->willReturn(null);
		$idempotency->method('tryInsert')->willReturn(true);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(time());
		$stampReplay = new MobileStampReplayService(
			$this->timeTrackingService,
			$idempotency,
			new StampOccurredAtParser($timeZoneService, $tzConfig),
			$timeFactory,
		);

		$this->controller = new TimeTrackingController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeTrackingService,
			$stampReplay,
			$this->userSession,
			$l10n
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
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Disabled stamping maps to HTTP 403 with a stable error_code (not 400).
	 */
	public function testClockInReturnsForbiddenWhenStampingDisabled(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->willThrowException(new TimeCaptureForbiddenException(
				'Clock in/out (stamping) is not enabled for your account.',
				TimeCaptureForbiddenException::CODE_CLOCK_STAMPING_DISABLED,
			));

		$response = $this->controller->clockIn();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame(TimeCaptureForbiddenException::CODE_CLOCK_STAMPING_DISABLED, $data['error_code']);
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
			->willThrowException(new BusinessRuleException('User is already clocked in', 'already_clocked_in'));

		$response = $this->controller->clockIn();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User is already clocked in', $data['error']);
		$this->assertEquals('User is already clocked in', $data['message']);
		$this->assertEquals('already_clocked_in', $data['error_code']);
	}

	/**
	 * Concurrent mutation lock maps to HTTP 423 with stable error_code.
	 */
	public function testClockInLockedExceptionMapsTo423(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->willThrowException(new \OCP\Lock\LockedException('user-clock'));

		$response = $this->controller->clockIn();
		$this->assertEquals(Http::STATUS_LOCKED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame($data['error'], $data['message']);
		$this->assertSame('locked', $data['error_code']);
	}

	/**
	 * Generic, non-business-rule exceptions must NOT leak to the client.
	 * They map to a sanitized 500 with a localized generic message.
	 */
	public function testClockInGenericExceptionMapsTo500(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->timeTrackingService->expects($this->once())
			->method('clockIn')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02]: leak'));

		$response = $this->controller->clockIn();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringNotContainsString('SQLSTATE', $data['error']);
	}

	/**
	 * Test clockOut returns success response
	 */
	public function testClockOutReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->clockOut();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testStartBreakReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->startBreak();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testEndBreakReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->endBreak();

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

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
			->willThrowException(new BusinessRuleException('User is not currently clocked in'));

		$response = $this->controller->clockOut();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User is not currently clocked in', $data['error']);
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
			->willThrowException(new BusinessRuleException('User is not currently clocked in'));

		$response = $this->controller->startBreak();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User is not currently clocked in', $data['error']);
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
			->willThrowException(new BusinessRuleException('User is not currently on break'));

		$response = $this->controller->endBreak();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User is not currently on break', $data['error']);
	}

	public function testGetBreakStatusSuccess(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeTrackingService->expects($this->once())
			->method('getBreakStatus')
			->with('testuser')
			->willReturn(['onBreak' => false]);

		$response = $this->controller->getBreakStatus();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertFalse($response->getData()['breakStatus']['onBreak']);
	}

	public function testGetBreakStatusUnauthorized(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->getBreakStatus();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testEnforceDailyMaximumSuccess(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeTrackingService->method('isAtOrAboveDailyMaximum')->willReturn(false);
		$this->timeTrackingService->method('enforceDailyMaximumForUser')->willReturn(null);
		$this->timeTrackingService->method('getStatus')->willReturn(['status' => 'clocked_out']);

		$response = $this->controller->enforceDailyMaximum();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['enforced']);
	}

	public function testEnforceDailyMaximumUnauthorized(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->enforceDailyMaximum();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
