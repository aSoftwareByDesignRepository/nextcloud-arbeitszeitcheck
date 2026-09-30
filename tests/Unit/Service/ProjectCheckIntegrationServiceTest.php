<?php

declare(strict_types=1);

/**
 * Unit tests for ProjectCheckIntegrationService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal stand-in for a ProjectCheck `Project` entity.
 *
 * Crucially, the column getters are served through `__call()` magic exactly
 * like the real {@see \OCP\AppFramework\Db\Entity}, while a couple of helper
 * methods are declared concretely. This reproduces the real runtime shape so
 * the test catches the `method_exists()` vs `is_callable()` regression that
 * previously blanked every project label in the picker.
 *
 * @method int getId()
 * @method string getName()
 * @method string getCustomerName()
 */
class MagicGetterProjectStub
{
	/** @param array<string, mixed> $data */
	public function __construct(private array $data)
	{
	}

	public function __call(string $name, array $arguments): mixed
	{
		if (str_starts_with($name, 'get')) {
			$key = lcfirst(substr($name, 3));
			return $this->data[$key] ?? null;
		}
		throw new \BadMethodCallException($name);
	}

	// Declared concretely on the real entity, so these must keep working too.
	public function allowsTimeTracking(): bool
	{
		return (bool)($this->data['allowsTimeTracking'] ?? true);
	}

	public function getCostRateMode(): string
	{
		return (string)($this->data['costRateMode'] ?? 'project');
	}
}

/**
 * Class ProjectCheckIntegrationServiceTest
 */
class ProjectCheckIntegrationServiceTest extends TestCase
{
	/** @var ProjectCheckIntegrationService */
	private $service;

	/** @var IAppManager|\PHPUnit\Framework\MockObject\MockObject */
	private $appManager;

	/** @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $db;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	/** @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $appConfig;

	private string $integrationConfigValue = '1';
	private bool $integrationConfigUsesDefault = false;

	protected function setUp(): void
	{
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED) {
					if ($this->integrationConfigUsesDefault) {
						return $default;
					}
					return $this->integrationConfigValue;
				}
				return $default;
			});

		$this->service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			null,
			null,
		);
	}

	private function configureAppConfigIntegration(string $value): void
	{
		$this->integrationConfigUsesDefault = false;
		$this->integrationConfigValue = $value;
	}

	private function configureAppConfigIntegrationUnset(): void
	{
		$this->integrationConfigUsesDefault = true;
	}

	/**
	 * Stand-in for ProjectCheck's ProjectService. Unit tests must not require
	 * that app's class to be autoloaded (it is a separate install).
	 *
	 * @param list<string> $methods
	 */
	private function mockProjectCheckProjectService(array $methods = [
		'getProjectsForUserTimeEntry',
		'isActiveTeamMember',
		'getProject',
		'mayBillArbeitszeitCheckTimeForUser',
		'canUserAddTimeEntryForProject',
	]): object {
		return $this->getMockBuilder(\stdClass::class)
			->addMethods($methods)
			->getMock();
	}

	/**
	 * Test isProjectCheckAvailable returns true when app is enabled
	 */
	public function testIsProjectCheckAvailableWhenEnabled(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$result = $this->service->isProjectCheckAvailable();

		$this->assertTrue($result);
	}

	/**
	 * Test isProjectCheckAvailable returns false when app is disabled
	 */
	public function testIsProjectCheckAvailableWhenDisabled(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->isProjectCheckAvailable();

		$this->assertFalse($result);
	}

	/**
	 * Test getAvailableProjects returns empty array when ProjectCheck not available
	 */
	public function testGetAvailableProjectsWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$this->db->expects($this->never())
			->method('getQueryBuilder');

		$result = $this->service->getAvailableProjects('user1');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getAvailableProjects returns projects when ProjectService is wired
	 */
	public function testGetAvailableProjectsReturnsProjects(): void
	{
		$userId = 'user1';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$project = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getId', 'getName', 'getCustomerName', 'getCustomerId', 'getCostRateMode', 'allowsTimeTracking', 'isActiveTeamMember'])
			->getMock();
		$project->method('getId')->willReturn(1);
		$project->method('getName')->willReturn('Project 1');
		$project->method('getCustomerName')->willReturn('Customer A');
		$project->method('getCustomerId')->willReturn(10);
		$project->method('getCostRateMode')->willReturn('project');
		$project->method('allowsTimeTracking')->willReturn(true);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->expects($this->once())
			->method('getProjectsForUserTimeEntry')
			->with($userId, $this->isType('array'))
			->willReturn([$project]);

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$projects = $service->getAvailableProjects($userId);

		$this->assertCount(1, $projects);
		$this->assertEquals('1', $projects[0]['id']);
		$this->assertEquals('Project 1 (Customer A)', $projects[0]['displayName']);
	}

	/**
	 * Regression test: project labels must render for entities whose getters are
	 * provided through Entity `__call()` magic (the real ProjectCheck Project).
	 *
	 * With the previous `method_exists()` guards this produced empty <option>
	 * labels because `method_exists()` does not see magic methods.
	 */
	public function testGetAvailableProjectsRendersLabelsForMagicGetterEntities(): void
	{
		$userId = 'user1';

		$this->appManager->method('isInstalled')->willReturn(true);

		$project = new MagicGetterProjectStub([
			'id' => 6,
			'name' => 'KKK',
			'customerName' => 'Fritz Cola',
			'customerId' => 4,
			'costRateMode' => 'project',
			'allowsTimeTracking' => true,
		]);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('getProjectsForUserTimeEntry')->willReturn([$project]);

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$projects = $service->getAvailableProjects($userId);

		$this->assertCount(1, $projects);
		$this->assertSame('6', $projects[0]['id']);
		$this->assertSame('KKK', $projects[0]['name']);
		$this->assertSame('Fritz Cola', $projects[0]['customerName']);
		$this->assertSame('KKK (Fritz Cola)', $projects[0]['displayName']);
		$this->assertSame(4, $projects[0]['customerId']);
	}

	/**
	 * When entity getters return an empty name, fall back to the database row.
	 */
	public function testGetAvailableProjectsFallsBackToDatabaseName(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);

		$project = new MagicGetterProjectStub([
			'id' => 6,
			'name' => '',
			'customerName' => '',
			'costRateMode' => 'project',
			'allowsTimeTracking' => true,
		]);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('getProjectsForUserTimeEntry')->willReturn([$project]);

		$queryResult = $this->createMock(\OCP\DB\IResult::class);
		$queryResult->method('fetch')->willReturn([
			'id' => 6,
			'name' => 'KKK',
			'customer_name' => 'Fritz Cola',
			'customer_id' => 4,
			'status' => 'Active',
		]);
		$queryResult->method('closeCursor');

		$query = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnSelf();
		$query->method('leftJoin')->willReturnSelf();
		$query->method('where')->willReturnSelf();
		$query->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$query->method('createNamedParameter')->willReturn('p');
		$query->method('executeQuery')->willReturn($queryResult);

		$this->db->method('getQueryBuilder')->willReturn($query);

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$projects = $service->getAvailableProjects('user1');
		$this->assertCount(1, $projects);
		$this->assertSame('6', $projects[0]['id']);
		$this->assertSame('KKK', $projects[0]['name']);
		$this->assertSame('Fritz Cola', $projects[0]['customerName']);
	}

	/**
	 * Defensive: a project whose name is empty must be skipped, never rendered
	 * as a blank, unreadable option.
	 */
	public function testGetAvailableProjectsSkipsProjectsWithEmptyName(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);

		$blank = new MagicGetterProjectStub([
			'id' => 7,
			'name' => '',
			'customerName' => 'Fritz Cola',
			'costRateMode' => 'project',
			'allowsTimeTracking' => true,
		]);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('getProjectsForUserTimeEntry')->willReturn([$blank]);

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$this->assertSame([], $service->getAvailableProjects('user1'));
	}

	/**
	 * Test getAvailableProjects excludes per-person projects when user is not on team
	 */
	public function testGetAvailableProjectsExcludesPerPersonWhenNotOnTeam(): void
	{
		$userId = 'user1';

		$this->appManager->method('isInstalled')->willReturn(true);

		$project = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getId', 'getName', 'getCustomerName', 'getCustomerId', 'getCostRateMode', 'allowsTimeTracking'])
			->getMock();
		$project->method('getId')->willReturn(2);
		$project->method('getName')->willReturn('Per person');
		$project->method('getCustomerName')->willReturn('');
		$project->method('getCustomerId')->willReturn(null);
		$project->method('getCostRateMode')->willReturn('project_member');
		$project->method('allowsTimeTracking')->willReturn(true);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('getProjectsForUserTimeEntry')->willReturn([$project]);
		$projectService->method('isActiveTeamMember')->with(2, $userId)->willReturn(false);

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$this->assertSame([], $service->getAvailableProjects($userId));
	}

	/**
	 * Test getAvailableProjects handles exceptions gracefully
	 */
	public function testGetAvailableProjectsHandlesException(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->expects($this->once())
			->method('getProjectsForUserTimeEntry')
			->willThrowException(new \Exception('Service error'));

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$result = $service->getAvailableProjects('user1');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getProjectDetails returns null when ProjectCheck not available
	 */
	public function testGetProjectDetailsWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->getProjectDetails('project1');

		$this->assertNull($result);
	}

	/**
	 * Test getProjectDetails returns project data when found
	 */
	public function testGetProjectDetailsReturnsProject(): void
	{
		$projectId = '42';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->expects($this->once())
			->method('select')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('from')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('leftJoin')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('createNamedParameter')
			->with($projectId)
			->willReturn(':project1');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');
		$queryBuilder->method('expr')
			->willReturn($expr);

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn([
				'id' => 42,
				'name' => 'Test Project',
				'short_description' => 'Test Description',
				'customer_id' => 10,
				'customer_name' => 'Customer A',
				'status' => 'Active',
				'total_budget' => 10000.0,
				'hourly_rate' => 50.0,
				'start_date' => '2024-01-01',
				'end_date' => '2024-12-31',
				'cost_rate_mode' => 'project',
			]);

		$result->expects($this->once())
			->method('closeCursor');

		$project = $this->service->getProjectDetails($projectId);

		$this->assertIsArray($project);
		$this->assertEquals('42', $project['id']);
		$this->assertEquals('Test Project', $project['name']);
		$this->assertEquals('Test Description', $project['description']);
		$this->assertEquals(10000.0, $project['budget']);
		$this->assertEquals(50.0, $project['hourlyRate']);
	}

	/**
	 * Test getProjectDetails returns null when project not found
	 */
	public function testGetProjectDetailsReturnsNullWhenNotFound(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('leftJoin')->willReturnSelf();
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('createNamedParameter')->willReturn(':param');
		$queryBuilder->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$queryBuilder->method('executeQuery')->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn(false);

		$result->expects($this->once())
			->method('closeCursor');

		$project = $this->service->getProjectDetails('nonexistent');

		$this->assertNull($project);
	}

	/**
	 * Test getProjectCheckTimeEntries returns empty array when not available
	 */
	public function testGetProjectCheckTimeEntriesWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->getProjectCheckTimeEntries('project1');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getProjectCheckTimeEntries returns entries
	 */
	public function testGetProjectCheckTimeEntriesReturnsEntries(): void
	{
		$projectId = '42';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->expects($this->once())
			->method('select')
			->with('*')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('from')
			->with('pc_time_entries')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('orderBy')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('createNamedParameter')
			->with($projectId)
			->willReturn(':project1');

		$queryBuilder->expects($this->once())
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$result->expects($this->exactly(2))
			->method('fetch')
			->willReturnOnConsecutiveCalls(
				[
					'id' => '1',
					'project_id' => $projectId,
					'user_id' => 'user1',
					'date' => '2024-01-15',
					'hours' => 8.0,
					'description' => 'Work done',
					'hourly_rate' => 50.0,
					'created_at' => '2024-01-15 10:00:00'
				],
				false
			);

		$result->expects($this->once())
			->method('closeCursor');

		$entries = $this->service->getProjectCheckTimeEntries($projectId);

		$this->assertIsArray($entries);
		$this->assertCount(1, $entries);
		$this->assertEquals('1', $entries[0]['id']);
		$this->assertEquals($projectId, $entries[0]['projectId']);
		$this->assertEquals(8.0, $entries[0]['hours']);
		$this->assertEquals('projectcheck', $entries[0]['source']);
	}

	/**
	 * Test syncTimeEntriesToProjectCheck returns error when not available
	 */
	public function testSyncTimeEntriesToProjectCheckWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->syncTimeEntriesToProjectCheck('user1');

		$this->assertIsArray($result);
		$this->assertFalse($result['success']);
		$this->assertEquals('ProjectCheck not available', $result['error']);
	}

	/**
	 * Test syncTimeEntriesToProjectCheck syncs entries successfully
	 */
	public function testSyncTimeEntriesToProjectCheckSyncsEntries(): void
	{
		$userId = 'user1';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$mainQb = $this->createMock(IQueryBuilder::class);
		$existingQb = $this->createMock(IQueryBuilder::class);
		$insertQb = $this->createMock(IQueryBuilder::class);

		$mainResult = $this->createMock(IResult::class);
		$existingResult = $this->createMock(IResult::class);

		$this->db->expects($this->exactly(3))
			->method('getQueryBuilder')
			->willReturnOnConsecutiveCalls($mainQb, $existingQb, $insertQb);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');
		$expr->method('isNotNull')->willReturn('expr');
		$expr->method('gte')->willReturn('expr');

		foreach ([$mainQb, $existingQb] as $qb) {
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('createNamedParameter')->willReturn(':param');
			$qb->method('expr')->willReturn($expr);
		}

		$mainQb->method('executeQuery')->willReturn($mainResult);
		$existingQb->method('executeQuery')->willReturn($existingResult);

		$insertQb->expects($this->once())
			->method('insert')
			->with('pc_time_entries')
			->willReturnSelf();
		$insertQb->expects($this->once())
			->method('values')
			->willReturnSelf();
		$insertQb->method('createNamedParameter')->willReturn(':param');
		$insertQb->expects($this->once())
			->method('executeStatement');

		$mainResult->expects($this->exactly(2))
			->method('fetch')
			->willReturnOnConsecutiveCalls(
				[
					'id' => '1',
					'project_check_project_id' => 'project1',
					'user_id' => $userId,
					'start_time' => '2024-01-15 08:00:00',
					'hours' => 8.0,
					'description' => 'Work',
					'hourly_rate' => 50.0,
					'created_at' => '2024-01-15 10:00:00',
					'status' => 'completed'
				],
				false
			);
		$mainResult->expects($this->once())->method('closeCursor');

		$existingResult->expects($this->once())
			->method('fetch')
			->willReturn(false);

		$syncResult = $this->service->syncTimeEntriesToProjectCheck($userId);

		$this->assertIsArray($syncResult);
		$this->assertTrue($syncResult['success']);
		$this->assertEquals(1, $syncResult['synced']);
		$this->assertEquals(0, $syncResult['errors']);
	}

	/**
	 * Test getProjectBudgetInfo returns null when not available
	 */
	public function testGetProjectBudgetInfoWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->getProjectBudgetInfo('project1');

		$this->assertNull($result);
	}

	/**
	 * Test getProjectBudgetInfo returns budget information
	 */
	public function testGetProjectBudgetInfoReturnsBudget(): void
	{
		$projectId = '42';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->expects($this->once())
			->method('select')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('from')
			->with('pc_projects')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('createNamedParameter')
			->with($projectId)
			->willReturn(':project1');

		$queryBuilder->expects($this->once())
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn([
				'total_budget' => 10000.0,
				'hourly_rate' => 50.0,
			]);

		$result->expects($this->once())
			->method('closeCursor');

		$budget = $this->service->getProjectBudgetInfo($projectId);

		$this->assertIsArray($budget);
		$this->assertEquals(10000.0, $budget['budget']);
		$this->assertEquals(50.0, $budget['hourlyRate']);
	}

	/**
	 * Test getProjectTimeStats combines stats from both apps
	 */
	public function testGetProjectTimeStatsCombinesStats(): void
	{
		$projectId = '42';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$atQb = $this->createMock(IQueryBuilder::class);
		$pcQb = $this->createMock(IQueryBuilder::class);
		$atResult = $this->createMock(IResult::class);
		$pcResult = $this->createMock(IResult::class);

		$this->db->expects($this->exactly(2))
			->method('getQueryBuilder')
			->willReturnOnConsecutiveCalls($atQb, $pcQb);

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');

		foreach ([$atQb, $pcQb] as $qb) {
			$qb->method('select')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('createNamedParameter')->willReturn(':param');
			$qb->method('createFunction')->willReturn('FUNC');
			$qb->method('expr')->willReturn($expr);
		}

		$atQb->method('executeQuery')->willReturn($atResult);
		$pcQb->method('executeQuery')->willReturn($pcResult);

		$atResult->method('fetch')->willReturn([
			'total_hours' => 40.0,
			'total_cost' => 2000.0,
			'entries_count' => 5
		]);
		$pcResult->method('fetch')->willReturn([
			'total_hours' => 20.0,
			'total_cost' => 1000.0,
			'entries_count' => 3
		]);

		$atResult->expects($this->once())->method('closeCursor');
		$pcResult->expects($this->once())->method('closeCursor');

		$stats = $this->service->getProjectTimeStats($projectId);

		$this->assertIsArray($stats);
		$this->assertEquals($projectId, $stats['projectId']);
		$this->assertEquals(40.0, $stats['arbeitszeitcheck']['totalHours']);
		$this->assertEquals(20.0, $stats['projectcheck']['totalHours']);
		$this->assertEquals(60.0, $stats['combined']['totalHours']);
		$this->assertEquals(3000.0, $stats['combined']['totalCost']);
		$this->assertEquals(8, $stats['combined']['entriesCount']);
	}

	/**
	 * Test projectExists returns false when not available
	 */
	public function testProjectExistsWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(false);

		$result = $this->service->projectExists('project1');

		$this->assertFalse($result);
	}

	/**
	 * Test projectExists returns true when project exists
	 */
	public function testProjectExistsReturnsTrue(): void
	{
		$projectId = '42';

		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->expects($this->once())
			->method('select')
			->with('id')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('from')
			->with('pc_projects')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('setMaxResults')
			->with(1)
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('createNamedParameter')
			->with(42, $this->anything())
			->willReturn(':p42');

		$queryBuilder->expects($this->once())
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn(['id' => 42]);

		$result->expects($this->once())
			->method('closeCursor');

		$exists = $this->service->projectExists($projectId);

		$this->assertTrue($exists);
	}

	/**
	 * Test projectExists returns false when project does not exist
	 */
	public function testProjectExistsReturnsFalse(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('setMaxResults')->willReturnSelf();
		$queryBuilder->method('createNamedParameter')->willReturn(':param');
		$queryBuilder->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$queryBuilder->method('executeQuery')->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn(false);

		$result->expects($this->once())
			->method('closeCursor');

		$exists = $this->service->projectExists('999');

		$this->assertFalse($exists);
	}

	/**
	 * Test projectExists handles exceptions gracefully
	 */
	public function testProjectExistsHandlesException(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with('projectcheck')
			->willReturn(true);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willThrowException(new \Exception('Database error'));

		$exists = $this->service->projectExists('999');

		$this->assertFalse($exists);
	}

	/**
	 * Admin integration defaults to OFF when the config key has never been stored.
	 */
	public function testIsLinkingDisabledDefaultsOffWhenAdminConfigUnset(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegrationUnset();

		$this->assertFalse($this->service->isLinkingEnabledForUser('user1'));
		$this->assertFalse($this->service->isAdminIntegrationEnabled());
	}

	/**
	 * Admin stores literal '0' to disable linking for everyone.
	 */
	public function testIsLinkingDisabledWhenAdminConfigIsZero(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('0');

		$this->assertFalse($this->service->isLinkingEnabledForUser('user1'));
	}

	/**
	 * Linking is never enabled when ProjectCheck itself is unavailable.
	 */
	public function testIsLinkingDisabledWhenProjectCheckUnavailable(): void
	{
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->configureAppConfigIntegration('1');

		$this->assertFalse($this->service->isLinkingEnabledForUser('user1'));
	}

	public function testIsProjectCheckAvailableIgnoresCurrentUserGroupRestriction(): void
	{
		$this->appManager->expects($this->once())
			->method('isInstalled')
			->with(Constants::APP_ID_PROJECTCHECK)
			->willReturn(true);
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$this->assertTrue($this->service->isProjectCheckAvailable());
	}

	public function testIsProjectCheckEnabledForUserRequiresInstallAndUserAccess(): void
	{
		$this->appManager->method('isInstalled')->with(Constants::APP_ID_PROJECTCHECK)->willReturn(true);
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with(Constants::APP_ID_PROJECTCHECK, null)
			->willReturn(false);

		$this->assertFalse($this->service->isProjectCheckEnabledForUser(null));
	}

	public function testAdminIntegrationEnabledWhenInstalledEvenIfCurrentUserLacksApp(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->configureAppConfigIntegration('1');

		$this->assertTrue($this->service->isAdminIntegrationEnabled());
		$this->assertTrue($this->service->isLinkingEnabledForUser('employee1'));
	}

	/**
	 * Security: when ProjectService is unavailable, attach is rejected even if the id exists in pc_projects.
	 */
	public function testUserMayAttachFailsClosedWhenProjectServiceUnavailable(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);

		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertFalse($this->service->userMayAttachProjectCheckProjectToOwnTime('user1', '42'));
	}

	/**
	 * Security: when the admin switch is off, users cannot attach a project via API.
	 */
	public function testUserMayAttachReturnsFalseWhenAdminIntegrationDisabled(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('0');

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->expects($this->never())->method('getProject');

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$this->assertFalse($service->userMayAttachProjectCheckProjectToOwnTime('user1', '6'));
	}

	/**
	 * Security: admin "connection off" blocks manager on-behalf project links too.
	 */
	public function testManagerMayAttachReturnsFalseWhenAdminIntegrationDisabled(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('0');

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->expects($this->never())->method('mayBillArbeitszeitCheckTimeForUser');

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$this->assertFalse($service->managerMayAttachProjectCheckProjectForEmployee('mgr1', 'emp1', '6'));
		$this->assertSame([], $service->getAssignableProjectsForManagerOnBehalfOfEmployee('mgr1', 'emp1'));
	}

	/**
	 * Attach gate must prefer ProjectCheck's canUserAddTimeEntryForProject so the
	 * picker and clock-in cannot drift (picker uses getProjectsForUserTimeEntry).
	 */
	public function testUserMayAttachDelegatesToCanUserAddTimeEntryForProject(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		$projectService = $this->getMockBuilder(\stdClass::class)
			->addMethods(['canUserAddTimeEntryForProject', 'getProject'])
			->getMock();
		$projectService->expects($this->once())
			->method('canUserAddTimeEntryForProject')
			->with('user1', 42)
			->willReturn(true);
		$projectService->expects($this->never())->method('getProject');

		$service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);

		$this->assertTrue($service->userMayAttachProjectCheckProjectToOwnTime('user1', '42'));
	}

	public function testUserMayAttachReadStringGetterArms(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		// arm: getCostRateMode missing entirely -> '' -> falls through to canUserAccessProject
		$projNoMode = $this->getMockBuilder(\stdClass::class)
			->addMethods(['allowsTimeTracking'])
			->getMock();
		$projNoMode->method('allowsTimeTracking')->willReturn(true);
		$svcA = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getProject', 'canUserAccessProject'])
			->getMock();
		$svcA->method('getProject')->willReturn($projNoMode);
		$svcA->method('canUserAccessProject')->willReturn(true);
		$this->assertTrue(
			$this->serviceWithFacade($svcA)->userMayAttachProjectCheckProjectToOwnTime('user1', '42')
		);

		// arm: getCostRateMode throws -> '' (debug-logged) -> canUserAccessProject
		$projThrowing = $this->getMockBuilder(\stdClass::class)
			->addMethods(['allowsTimeTracking', 'getCostRateMode'])
			->getMock();
		$projThrowing->method('allowsTimeTracking')->willReturn(true);
		$projThrowing->method('getCostRateMode')
			->willThrowException(new \RuntimeException('getter broken'));
		$svcB = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getProject', 'canUserAccessProject'])
			->getMock();
		$svcB->method('getProject')->willReturn($projThrowing);
		$svcB->method('canUserAccessProject')->willReturn(true);
		$this->assertTrue(
			$this->serviceWithFacade($svcB)->userMayAttachProjectCheckProjectToOwnTime('user1', '42')
		);

		// arm: getCostRateMode returns null -> ''
		$projNull = $this->getMockBuilder(\stdClass::class)
			->addMethods(['allowsTimeTracking', 'getCostRateMode'])
			->getMock();
		$projNull->method('allowsTimeTracking')->willReturn(true);
		$projNull->method('getCostRateMode')->willReturn(null);
		$svcC = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getProject', 'canUserAccessProject'])
			->getMock();
		$svcC->method('getProject')->willReturn($projNull);
		$svcC->method('canUserAccessProject')->willReturn(false);
		$this->assertFalse(
			$this->serviceWithFacade($svcC)->userMayAttachProjectCheckProjectToOwnTime('user1', '42')
		);
	}

	private function serviceWithFacade(object $projectService): ProjectCheckIntegrationService
	{
		return new ProjectCheckIntegrationService(
			$this->appManager,
			$this->appConfig,
			$this->db,
			$this->l10n,
			$this->logger,
			$projectService,
			null,
		);
	}

	public function testManagerMayAttachDelegatesToFacade(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->expects($this->exactly(2))
			->method('mayBillArbeitszeitCheckTimeForUser')
			->willReturnCallback(static fn (string $mgr, string $emp, int $pid) => $pid === 6);

		$service = $this->serviceWithFacade($projectService);
		$this->assertTrue($service->managerMayAttachProjectCheckProjectForEmployee('mgr1', 'emp1', '6'));
		$this->assertFalse($service->managerMayAttachProjectCheckProjectForEmployee('mgr1', 'emp1', '9'));
		$this->assertFalse($service->managerMayAttachProjectCheckProjectForEmployee('mgr1', 'emp1', 'abc'));
	}

	public function testManagerMayAttachFailsClosedOnFacadeException(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('mayBillArbeitszeitCheckTimeForUser')
			->willThrowException(new \RuntimeException('facade down'));

		$service = $this->serviceWithFacade($projectService);
		$this->assertFalse($service->managerMayAttachProjectCheckProjectForEmployee('mgr1', 'emp1', '6'));
	}

	public function testGetAssignableProjectsFiltersByManagerBillingRight(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		$mkProject = function (int $id, string $name, bool $tracking = true): object {
			$p = $this->getMockBuilder(\stdClass::class)
				->addMethods(['getId', 'getName', 'allowsTimeTracking', 'getCostRateMode', 'getCustomerName'])
				->getMock();
			$p->method('getId')->willReturn($id);
			$p->method('getName')->willReturn($name);
			$p->method('allowsTimeTracking')->willReturn($tracking);
			$p->method('getCostRateMode')->willReturn('project');
			$p->method('getCustomerName')->willReturn('ACME');
			return $p;
		};

		$projectService = $this->mockProjectCheckProjectService();
		$projectService->method('getProjectsForUserTimeEntry')->willReturn([
			$mkProject(1, 'Alpha'),
			$mkProject(2, 'Beta'),
			$mkProject(3, 'NoTime', false),      // tracking disabled -> dropped
			'garbage',                          // non-object -> dropped
		]);
		// manager may bill on project 2 only
		$projectService->method('mayBillArbeitszeitCheckTimeForUser')
			->willReturnCallback(static fn (string $m, string $e, int $pid) => $pid === 2);

		$service = $this->serviceWithFacade($projectService);
		$rows = $service->getAssignableProjectsForManagerOnBehalfOfEmployee('mgr1', 'emp1');
		$this->assertCount(1, $rows);
		$this->assertSame(2, (int)$rows[0]['id']);
	}

	public function testUserMayAttachFallsBackToProjectInspection(): void
	{
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->configureAppConfigIntegration('1');

		// facade WITHOUT canUserAddTimeEntryForProject -> getProject path
		$projectService = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getProject', 'isActiveTeamMember', 'canUserAccessProject'])
			->getMock();

		$memberModeProject = $this->getMockBuilder(\stdClass::class)
			->addMethods(['allowsTimeTracking', 'getCostRateMode'])
			->getMock();
		$memberModeProject->method('allowsTimeTracking')->willReturn(true);
		$memberModeProject->method('getCostRateMode')
			->willReturn('project_member');

		$projectService->method('getProject')->willReturn($memberModeProject);
		$projectService->method('isActiveTeamMember')
			->willReturnCallback(static fn (int $pid, string $uid) => $uid === 'member1');

		$service = $this->serviceWithFacade($projectService);
		$this->assertTrue($service->userMayAttachProjectCheckProjectToOwnTime('member1', '6'));
		$this->assertFalse($service->userMayAttachProjectCheckProjectToOwnTime('outsider', '6'));
	}
}