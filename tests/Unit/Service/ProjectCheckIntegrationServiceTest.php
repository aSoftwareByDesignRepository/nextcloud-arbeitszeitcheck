<?php

declare(strict_types=1);

/**
 * Unit tests for ProjectCheckIntegrationService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IResult;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

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

	protected function setUp(): void
	{
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->service = new ProjectCheckIntegrationService(
			$this->appManager,
			$this->db,
			$this->l10n
		);
	}

	/**
	 * Test isProjectCheckAvailable returns true when app is enabled
	 */
	public function testIsProjectCheckAvailableWhenEnabled(): void
	{
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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
			->method('isEnabledForUser')
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
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(false);

		$this->db->expects($this->never())
			->method('getQueryBuilder');

		$result = $this->service->getAvailableProjects('user1');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getAvailableProjects returns projects when available
	 */
	public function testGetAvailableProjectsReturnsProjects(): void
	{
		$userId = 'user1';

		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		$queryBuilder->expects($this->once())
			->method('select')
			->with($this->isType('array'))
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('from')
			->with('projectcheck_projects', 'p')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(2))
			->method('leftJoin')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('andWhere')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('orderBy')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(3))
			->method('createNamedParameter')
			->willReturnCallback(function ($value) {
				return ':' . $value;
			});

		$queryBuilder->expects($this->once())
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		// Mock result rows
		$result->expects($this->exactly(3))
			->method('fetch')
			->willReturnOnConsecutiveCalls(
				[
					'id' => '1',
					'name' => 'Project 1',
					'customer_id' => '10',
					'customer_name' => 'Customer A'
				],
				[
					'id' => '2',
					'name' => 'Project 2',
					'customer_id' => null,
					'customer_name' => null
				],
				false // End of results
			);

		$result->expects($this->once())
			->method('closeCursor');

		$projects = $this->service->getAvailableProjects($userId);

		$this->assertIsArray($projects);
		$this->assertCount(2, $projects);
		$this->assertEquals('1', $projects[0]['id']);
		$this->assertEquals('Project 1', $projects[0]['name']);
		$this->assertEquals('Customer A', $projects[0]['customerName']);
		$this->assertEquals('Project 1 (Customer A)', $projects[0]['displayName']);
		$this->assertEquals('2', $projects[1]['id']);
		$this->assertEquals('Project 2', $projects[1]['name']);
		$this->assertEquals('No Customer', $projects[1]['customerName']);
		$this->assertEquals('Project 2', $projects[1]['displayName']);
	}

	/**
	 * Test getAvailableProjects handles exceptions gracefully
	 */
	public function testGetAvailableProjectsHandlesException(): void
	{
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(true);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willThrowException(new \Exception('Database error'));

		$result = $this->service->getAvailableProjects('user1');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getProjectDetails returns null when ProjectCheck not available
	 */
	public function testGetProjectDetailsWhenNotAvailable(): void
	{
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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
		$projectId = 'project1';

		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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

		$queryBuilder->expects($this->once())
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$result->expects($this->once())
			->method('fetch')
			->willReturn([
				'id' => $projectId,
				'name' => 'Test Project',
				'description' => 'Test Description',
				'customer_id' => '10',
				'customer_name' => 'Customer A',
				'status' => 'active',
				'budget' => 10000.0,
				'hourly_rate' => 50.0,
				'start_date' => '2024-01-01',
				'end_date' => '2024-12-31'
			]);

		$result->expects($this->once())
			->method('closeCursor');

		$project = $this->service->getProjectDetails($projectId);

		$this->assertIsArray($project);
		$this->assertEquals($projectId, $project['id']);
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
			->method('isEnabledForUser')
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
			->method('isEnabledForUser')
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
		$projectId = 'project1';

		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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
			->with('projectcheck_time_entries')
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
			->method('isEnabledForUser')
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
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->exactly(3))
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		// First query: Get ArbeitszeitCheck entries
		$queryBuilder->expects($this->exactly(2))
			->method('select')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(2))
			->method('from')
			->willReturnOnConsecutiveCalls('at_entries', 'projectcheck_time_entries');

		$queryBuilder->expects($this->exactly(3))
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(2))
			->method('andWhere')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(6))
			->method('createNamedParameter')
			->willReturnCallback(function ($value) {
				return ':' . (is_string($value) ? $value : 'param');
			});

		$queryBuilder->expects($this->exactly(2))
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->exactly(2))
			->method('executeQuery')
			->willReturn($result);

		$queryBuilder->expects($this->once())
			->method('insert')
			->with('projectcheck_time_entries')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('values')
			->willReturnSelf();

		$queryBuilder->expects($this->once())
			->method('executeStatement');

		// Mock result: One entry to sync
		$result->expects($this->exactly(2))
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
				false // No existing entry in ProjectCheck
			);

		$result->expects($this->once())
			->method('closeCursor');

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
			->method('isEnabledForUser')
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
		$projectId = 'project1';

		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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
			->with('projectcheck_projects')
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
				'budget' => 10000.0,
				'hourly_rate' => 50.0
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
		$projectId = 'project1';

		$this->appManager->expects($this->exactly(2))
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(true);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$result = $this->createMock(IResult::class);

		$this->db->expects($this->exactly(2))
			->method('getQueryBuilder')
			->willReturn($queryBuilder);

		// Mock ArbeitszeitCheck stats query
		$queryBuilder->expects($this->exactly(2))
			->method('select')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(2))
			->method('from')
			->willReturnOnConsecutiveCalls('at_entries', 'projectcheck_time_entries');

		$queryBuilder->expects($this->exactly(2))
			->method('where')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(2))
			->method('andWhere')
			->willReturnSelf();

		$queryBuilder->expects($this->exactly(3))
			->method('createNamedParameter')
			->willReturn(':param');

		$queryBuilder->expects($this->exactly(2))
			->method('createFunction')
			->willReturn('SUM(hours)');

		$queryBuilder->expects($this->exactly(2))
			->method('expr')
			->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));

		$queryBuilder->expects($this->exactly(2))
			->method('executeQuery')
			->willReturn($result);

		// Mock results
		$result->expects($this->exactly(2))
			->method('fetch')
			->willReturnOnConsecutiveCalls(
				[
					'total_hours' => 40.0,
					'total_cost' => 2000.0,
					'entries_count' => 5
				],
				[
					'total_hours' => 20.0,
					'total_cost' => 1000.0,
					'entries_count' => 3
				]
			);

		$result->expects($this->exactly(2))
			->method('closeCursor');

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
			->method('isEnabledForUser')
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
		$projectId = 'project1';

		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
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
			->with('projectcheck_projects')
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
			->willReturn(['id' => $projectId]);

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
			->method('isEnabledForUser')
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

		$exists = $this->service->projectExists('nonexistent');

		$this->assertFalse($exists);
	}

	/**
	 * Test projectExists handles exceptions gracefully
	 */
	public function testProjectExistsHandlesException(): void
	{
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('projectcheck')
			->willReturn(true);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willThrowException(new \Exception('Database error'));

		$exists = $this->service->projectExists('project1');

		$this->assertFalse($exists);
	}
}
