<?php

declare(strict_types=1);

/**
 * Unit tests for AdminController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Class AdminControllerTest
 */
class AdminControllerTest extends TestCase
{
	/** @var AdminController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var WorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $workingTimeModelMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $appConfig;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$cspService = $this->createMock(CSPService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s, $p = []) => empty($p) ? $s : vsprintf($s, $p));
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$holidayMapper = $this->createMock(HolidayMapper::class);
		$holidayCalendarService = $this->createMock(HolidayService::class);

		$vacationYearBalanceMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class);
		$vacationAllocationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationAllocationService::class);
		$vacationAllocationService->method('applyCapToOpeningBalance')->willReturnCallback(fn (float $d) => $d);

		$this->controller = new AdminController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->auditLogMapper,
			$this->userManager,
			$this->appConfig,
			$userSettingsMapper,
			$teamMapper,
			$teamMemberMapper,
			$teamManagerMapper,
			$userSession,
			$cspService,
			$l10n,
			$urlGenerator,
			$holidayMapper,
			$holidayCalendarService,
			$vacationYearBalanceMapper,
			$vacationAllocationService
		);
	}

	/**
	 * Test dashboard returns template
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test users returns template
	 */
	public function testUsersReturnsTemplate(): void
	{
		$response = $this->controller->users();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test settings returns template
	 */
	public function testSettingsReturnsTemplate(): void
	{
		$response = $this->controller->settings();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test workingTimeModels returns template
	 */
	public function testWorkingTimeModelsReturnsTemplate(): void
	{
		$response = $this->controller->workingTimeModels();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test auditLog returns template
	 */
	public function testAuditLogReturnsTemplate(): void
	{
		$response = $this->controller->auditLog();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test getAdminSettings returns settings
	 */
	public function testGetAdminSettingsReturnsSettings(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, string $default = '') {
				$values = [
					'auto_compliance_check' => '1',
					'enable_violation_notifications' => '1',
					'export_midnight_split_enabled' => '1',
					'max_daily_hours' => '10',
					'min_rest_period' => '11',
					'german_state' => 'NW',
					'retention_period' => '2',
					'default_working_hours' => '8'
				];
				return $values[$key] ?? $default;
			});

		$response = $this->controller->getAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('settings', $data);
		$this->assertTrue($data['settings']['autoComplianceCheck']);
		$this->assertEquals(10.0, $data['settings']['maxDailyHours']);
	}

	/**
	 * Test updateAdminSettings updates settings
	 */
	public function testUpdateAdminSettingsUpdatesSettings(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'maxDailyHours' => 9.5,
				'germanState' => 'BY'
			]);

		$this->appConfig->expects($this->exactly(2))
			->method('setAppValueString')
			->withConsecutive(
				['max_daily_hours', '9.5'],
				['german_state', 'BY']
			);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('settings', $data);
	}

	/**
	 * Test updateAdminSettings validates maxDailyHours range
	 */
	public function testUpdateAdminSettingsValidatesMaxDailyHoursRange(): void
	{
		$this->request->method('getParams')
			->willReturn(['maxDailyHours' => 25]); // Invalid: > 24

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Maximum daily hours must be between', $data['error']);
	}

	/**
	 * Test updateAdminSettings validates minRestPeriod range
	 */
	public function testUpdateAdminSettingsValidatesMinRestPeriodRange(): void
	{
		$this->request->method('getParams')
			->willReturn(['minRestPeriod' => 25]); // Invalid: > 24

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Minimum rest period must be between', $data['error']);
	}

	/**
	 * Test updateAdminSettings validates German state code
	 */
	public function testUpdateAdminSettingsValidatesGermanState(): void
	{
		$this->request->method('getParams')
			->willReturn(['germanState' => 'XX']); // Invalid state code

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid German state code', $data['error']);
	}

	/**
	 * Test updateAdminSettings returns error when no settings provided
	 */
	public function testUpdateAdminSettingsReturnsErrorWhenNoSettings(): void
	{
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('No valid settings provided', $data['error']);
	}

	/**
	 * Test getStatistics returns statistics
	 */
	public function testGetStatisticsReturnsStatistics(): void
	{
		$this->userManager->method('countUsersTotal')
			->willReturn(100);

		$this->timeEntryMapper->method('countDistinctUsersByDate')
			->willReturn(50);

		$this->violationMapper->method('count')
			->willReturn(5);

		$violation = new ComplianceViolation();
		$violation->setUserId('user1');

		$this->violationMapper->method('findUnresolved')
			->willReturn([$violation]);

		$response = $this->controller->getStatistics();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('statistics', $data);
		$this->assertEquals(100, $data['statistics']['total_users']);
		$this->assertEquals(50, $data['statistics']['active_users_today']);
		$this->assertEquals(5, $data['statistics']['unresolved_violations']);
	}

	/**
	 * Test getUsers returns users list
	 */
	public function testGetUsersReturnsUsersList(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')
			->willReturn([$user]);

		$this->userManager->method('countUsersTotal')
			->willReturn(1);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$this->timeEntryMapper->method('countDistinctUsersByDate')
			->willReturn(0);

		$response = $this->controller->getUsers();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('users', $data);
		$this->assertCount(1, $data['users']);
		$this->assertEquals('user1', $data['users'][0]['userId']);
	}

	/**
	 * Test getUsers applies search filter
	 */
	public function testGetUsersAppliesSearchFilter(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->once())
			->method('search')
			->with('test', 50, 0)
			->willReturn([$user]);

		$this->userManager->method('countUsersTotal')->willReturn(1);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->timeEntryMapper->method('countDistinctUsersByDate')->willReturn(0);

		$response = $this->controller->getUsers('test', 50, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test getUser returns user details
	 */
	public function testGetUserReturnsUserDetails(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')
			->with($userId)
			->willReturn($user);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Full-time');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);

		$this->workingTimeModelMapper->method('findAll')
			->willReturn([$model]);

		$response = $this->controller->getUser($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('user', $data);
		$this->assertEquals($userId, $data['user']['userId']);
		$this->assertArrayHasKey('availableWorkingTimeModels', $data['user']);
	}

	/**
	 * Test getUser returns not found when user doesn't exist
	 */
	public function testGetUserReturnsNotFoundWhenUserMissing(): void
	{
		$userId = 'nonexistent';

		$this->userManager->method('get')
			->with($userId)
			->willReturn(null);

		$response = $this->controller->getUser($userId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User not found', $data['error']);
	}

	/**
	 * Test getWorkingTimeModels returns models list
	 */
	public function testGetWorkingTimeModelsReturnsModelsList(): void
	{
		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Full-time');
		$model->setDescription('40 hours per week');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(true);

		$this->workingTimeModelMapper->expects($this->once())
			->method('findAll')
			->willReturn([$model]);

		$response = $this->controller->getWorkingTimeModels();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('models', $data);
		$this->assertCount(1, $data['models']);
		$this->assertEquals('Full-time', $data['models'][0]['name']);
	}

	/**
	 * Test getWorkingTimeModel returns model details
	 */
	public function testGetWorkingTimeModelReturnsModelDetails(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Full-time');
		$model->setDescription('40 hours per week');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setBreakRulesArray([]);
		$model->setOvertimeRulesArray([]);
		$model->setIsDefault(true);

		$this->workingTimeModelMapper->expects($this->once())
			->method('find')
			->with($modelId)
			->willReturn($model);

		$response = $this->controller->getWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('model', $data);
		$this->assertEquals($modelId, $data['model']['id']);
	}

	/**
	 * Test getWorkingTimeModel returns not found when model doesn't exist
	 */
	public function testGetWorkingTimeModelReturnsNotFoundWhenModelMissing(): void
	{
		$modelId = 999;

		$this->workingTimeModelMapper->expects($this->once())
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Model not found'));

		$response = $this->controller->getWorkingTimeModel($modelId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Working time model not found', $data['error']);
	}

	/**
	 * Test createWorkingTimeModel creates model
	 */
	public function testCreateWorkingTimeModelCreatesModel(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'Part-time',
				'type' => 'part_time',
				'weeklyHours' => 20.0,
				'dailyHours' => 4.0,
				'isDefault' => false
			]);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Part-time');
		$model->setType(WorkingTimeModel::TYPE_PART_TIME);
		$model->setWeeklyHours(20.0);
		$model->setDailyHours(4.0);
		$model->setIsDefault(false);

		$this->workingTimeModelMapper->method('findDefault')->willReturn(null);
		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->willReturn($model);

		$response = $this->controller->createWorkingTimeModel();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$this->assertArrayHasKey('model', $data);
		$this->assertEquals('Part-time', $data['model']['name']);
	}

	/**
	 * Test createWorkingTimeModel unsets other defaults when setting as default
	 */
	public function testCreateWorkingTimeModelUnsetsOtherDefaults(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'New Default',
				'isDefault' => true
			]);

		$currentDefault = new WorkingTimeModel();
		$currentDefault->setId(1);
		$currentDefault->setName('Old Default');
		$currentDefault->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$currentDefault->setWeeklyHours(40.0);
		$currentDefault->setDailyHours(8.0);
		$currentDefault->setIsDefault(true);
		$currentDefault->setUpdatedAt(new \DateTime());

		$newModel = new WorkingTimeModel();
		$newModel->setId(2);
		$newModel->setName('New Default');
		$newModel->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$newModel->setWeeklyHours(40.0);
		$newModel->setDailyHours(8.0);
		$newModel->setIsDefault(true);

		$this->workingTimeModelMapper->method('findDefault')
			->willReturn($currentDefault);

		$this->workingTimeModelMapper->expects($this->once())
			->method('update')
			->with($currentDefault);

		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->willReturn($newModel);

		$response = $this->controller->createWorkingTimeModel();
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test updateWorkingTimeModel updates model
	 */
	public function testUpdateWorkingTimeModelUpdatesModel(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Updated Name');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(false);
		$model->setUpdatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn(['name' => 'Updated Name']);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->workingTimeModelMapper->expects($this->once())
			->method('update')
			->with($model)
			->willReturn($model);

		$response = $this->controller->updateWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('model', $data);
	}

	/**
	 * Test deleteWorkingTimeModel deletes model
	 */
	public function testDeleteWorkingTimeModelDeletesModel(): void
	{
		$modelId = 1;
		$model = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel::class);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->userWorkingTimeModelMapper->method('findByWorkingTimeModel')
			->with($modelId, false)
			->willReturn([]);

		$this->workingTimeModelMapper->expects($this->once())
			->method('delete')
			->with($model);

		$response = $this->controller->deleteWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test deleteWorkingTimeModel returns error when users assigned
	 */
	public function testDeleteWorkingTimeModelReturnsErrorWhenUsersAssigned(): void
	{
		$modelId = 1;
		$model = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel::class);

		$userModel = $this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel::class);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->userWorkingTimeModelMapper->method('findByWorkingTimeModel')
			->with($modelId, false)
			->willReturn([$userModel]);

		$response = $this->controller->deleteWorkingTimeModel($modelId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Cannot delete working time model', $data['error']);
	}

	/**
	 * Test updateUserWorkingTimeModel ends assignment when workingTimeModelId is null (No Model Assigned)
	 */
	public function testUpdateUserWorkingTimeModelRemovesAssignmentWhenNull(): void
	{
		$userId = 'admin';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$currentAssignment = new UserWorkingTimeModel();
		$currentAssignment->setId(1);
		$currentAssignment->setUserId($userId);
		$currentAssignment->setWorkingTimeModelId(1);
		$currentAssignment->setVacationDaysPerYear(25);
		$currentAssignment->setStartDate(new \DateTime('2024-01-01'));
		$currentAssignment->setUpdatedAt(new \DateTime());

		$endedAssignment = new UserWorkingTimeModel();
		$endedAssignment->setId(1);
		$endedAssignment->setUserId($userId);
		$endedAssignment->setWorkingTimeModelId(1);
		$endedAssignment->setVacationDaysPerYear(25);
		$endedAssignment->setStartDate(new \DateTime('2024-01-01'));
		$endedAssignment->setEndDate(new \DateTime('2024-01-02'));
		$endedAssignment->setUpdatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn([
				'workingTimeModelId' => null,
				'vacationDaysPerYear' => 25,
				'startDate' => null,
				'endDate' => null
			]);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->with($userId)
			->willReturn($currentAssignment);
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('endCurrentAssignment')
			->with($userId, $this->isInstanceOf(\DateTime::class))
			->willReturn($endedAssignment);

		$response = $this->controller->updateUserWorkingTimeModel($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('userWorkingTimeModel', $data);
	}

	/**
	 * Test updateUserWorkingTimeModel succeeds when no assignment and null model (nothing to do)
	 */
	public function testUpdateUserWorkingTimeModelSucceedsWhenNoAssignmentAndNullModel(): void
	{
		$userId = 'admin';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->request->method('getParams')
			->willReturn([
				'workingTimeModelId' => null,
				'vacationDaysPerYear' => 25,
				'startDate' => null,
				'endDate' => null
			]);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);
		$this->userWorkingTimeModelMapper->expects($this->never())
			->method('endCurrentAssignment');

		$response = $this->controller->updateUserWorkingTimeModel($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertNull($data['userWorkingTimeModel']);
	}

	/**
	 * Test getAuditLogs returns audit logs
	 */
	public function testGetAuditLogsReturnsAuditLogs(): void
	{
		$log = new AuditLog();
		$log->setId(1);
		$log->setUserId('user1');
		$log->setAction('time_entry_created');
		$log->setEntityType('time_entry');
		$log->setEntityId(1);
		$log->setOldValues(null);
		$log->setNewValues('{"id":1}');
		$log->setPerformedBy('user1');
		$log->setIpAddress('127.0.0.1');
		$log->setUserAgent('Test');
		$log->setCreatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('User One');

		$this->userManager->method('get')
			->willReturn($user);

		$this->auditLogMapper->expects($this->once())
			->method('findByDateRange')
			->willReturn([$log]);

		$response = $this->controller->getAuditLogs();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('logs', $data);
		$this->assertCount(1, $data['logs']);
	}

	/**
	 * Test getAuditLogStats returns statistics
	 */
	public function testGetAuditLogStatsReturnsStatistics(): void
	{
		$stats = [
			'total_actions' => 100,
			'actions_by_type' => []
		];

		$this->request->method('getParams')->willReturn([]);

		$this->auditLogMapper->expects($this->once())
			->method('getStatistics')
			->willReturn($stats);

		$response = $this->controller->getAuditLogStats();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('statistics', $data);
		$this->assertEquals(100, $data['statistics']['total_actions']);
	}

	/**
	 * Test exportUsers exports users data
	 */
	public function testExportUsersExportsUsersData(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')
			->willReturn([$user]);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$response = $this->controller->exportUsers('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('users-export-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	/**
	 * Test exportAuditLogs exports audit logs
	 */
	public function testExportAuditLogsExportsAuditLogs(): void
	{
		$log = new AuditLog();
		$log->setId(1);
		$log->setUserId('user1');
		$log->setAction('time_entry_created');
		$log->setEntityType('time_entry');
		$log->setEntityId(1);
		$log->setOldValues(null);
		$log->setNewValues('{"id":1}');
		$log->setPerformedBy('user1');
		$log->setIpAddress('127.0.0.1');
		$log->setUserAgent('Test');
		$log->setCreatedAt(new \DateTime());

		$this->request->method('getParams')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('User One');

		$this->userManager->method('get')->willReturn($user);

		$this->auditLogMapper->method('findByDateRange')
			->willReturn([$log]);

		$response = $this->controller->exportAuditLogs('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('audit-logs-export-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	/**
	 * Test getAdminSettings handles exceptions
	 */
	public function testGetAdminSettingsHandlesException(): void
	{
		$this->appConfig->expects($this->once())
			->method('getAppValueString')
			->willThrowException(new \Exception('Config error'));

		$response = $this->controller->getAdminSettings();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
	}

	/**
	 * Test getStatistics handles exceptions
	 */
	public function testGetStatisticsHandlesException(): void
	{
		$this->userManager->expects($this->once())
			->method('countUsersTotal')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->getStatistics();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}
}
