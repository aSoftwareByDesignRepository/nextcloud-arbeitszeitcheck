<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\UserWorkingTimeAssignmentService;
use OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver;
use OCP\IConfig;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Service-level E2E against the real DB: the customer case that triggered
 * this feature. Two effective-dated windows (Oct Mo–Mi 5h, Nov Mo–Do 5h)
 * must segment the Soll to 130h (125h in Sachsen) instead of stretching
 * today's model backwards — plus the write-path guards end to end.
 */
class AssignmentWriteAndSegmentationIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_assign_int__';

	private IUserManager $userManager;
	private UserWorkingTimeAssignmentService $service;
	private UserWorkingTimeModelMapper $assignmentMapper;
	private WorkingTimeModelMapper $modelMapper;
	private UserSettingsMapper $userSettings;
	private IConfig $config;

	private int $modelAId;
	private int $modelBId;
	private string $previousGapPolicy = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = \OCP\Server::get(IUserManager::class);
		$this->service = \OCP\Server::get(UserWorkingTimeAssignmentService::class);
		$this->assignmentMapper = \OCP\Server::get(UserWorkingTimeModelMapper::class);
		$this->modelMapper = \OCP\Server::get(WorkingTimeModelMapper::class);
		$this->userSettings = \OCP\Server::get(UserSettingsMapper::class);
		$this->config = \OCP\Server::get(IConfig::class);

		if (!$this->userManager->userExists(self::TEST_USER)) {
			$this->userManager->createUser(self::TEST_USER, bin2hex(random_bytes(16)) . 'Aa1!');
		}

		$this->modelAId = $this->insertScheduleModel('AZC-INT 15h Mo–Mi', ['mon', 'tue', 'wed']);
		$this->modelBId = $this->insertScheduleModel('AZC-INT 20h Mo–Do', ['mon', 'tue', 'wed', 'thu']);

		$this->previousGapPolicy = (string)$this->config->getAppValue(
			'arbeitszeitcheck', 'assignments_gap_policy', ''
		);
		$this->cleanUp();
	}

	protected function tearDown(): void
	{
		$this->cleanUp();
		foreach ([$this->modelAId, $this->modelBId] as $id) {
			try {
				$this->modelMapper->delete($this->modelMapper->find($id));
			} catch (\Throwable) {
			}
		}
		$this->config->setAppValue('arbeitszeitcheck', 'assignments_gap_policy', $this->previousGapPolicy);
		if ($this->userManager->userExists(self::TEST_USER)) {
			$this->userManager->get(self::TEST_USER)?->delete();
		}
		parent::tearDown();
	}

	private function cleanUp(): void
	{
		foreach ($this->assignmentMapper->findByUser(self::TEST_USER) as $row) {
			try {
				$this->assignmentMapper->delete($row);
			} catch (\Throwable) {
			}
		}
		$this->userSettings->setSetting(self::TEST_USER, 'german_state', '');
		$this->config->setAppValue('arbeitszeitcheck', 'assignments_gap_policy', 'lenient');
		\OCP\Server::get(WorkingTimeModelAssignmentResolver::class)->invalidateUser(self::TEST_USER);
	}

	private function insertScheduleModel(string $name, array $workDays): int
	{
		$work = ['work' => true, 'start' => '09:00', 'end' => '14:00', 'breaks' => []];
		$off = ['work' => false];
		$days = [];
		foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
			$days[$d] = in_array($d, $workDays, true) ? $work : $off;
		}
		$model = new WorkingTimeModel();
		$model->setName($name);
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(count($workDays) * 5.0);
		$model->setDailyHours(5.0);
		$model->setWorkDaysPerWeek((float)count($workDays));
		$model->setBreakRulesArray(['weekday_schedule' => ['version' => 1, 'days' => $days]]);
		$model->setCreatedAt(new \DateTime());
		$model->setUpdatedAt(new \DateTime());
		$model = $this->modelMapper->insert($model);
		return (int)$model->getId();
	}

	private function overtimeService(): OvertimeService
	{
		return new OvertimeService(
			\OCP\Server::get(TimeEntryMapper::class),
			$this->modelMapper,
			$this->assignmentMapper,
			\OCP\Server::get(\OCP\L10N\IFactory::class)->get('arbeitszeitcheck'),
			\OCP\Server::get(HolidayService::class),
			\OCP\Server::get(UserOvertimeSettingsService::class),
			null,
			null,
			new WorkingTimeModelAssignmentResolver(
				$this->assignmentMapper,
				$this->modelMapper,
				$this->config,
			),
		);
	}

	private function seedPia(): void
	{
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelAId,
			'startDate' => '2026-10-01',
			'endDate' => '2026-10-31',
		], 'int-test');
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelBId,
			'startDate' => '2026-11-01',
		], 'int-test');
		\OCP\Server::get(WorkingTimeModelAssignmentResolver::class)->invalidateUser(self::TEST_USER);
	}

	public function testPiaScenarioSegmentsSollWithoutHoliday(): void
	{
		$this->seedPia();

		$r = $this->overtimeService()->calculateOvertime(
			self::TEST_USER, new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false
		);

		$this->assertSame('segmented', $r['required_hours_basis']);
		$this->assertCount(2, $r['soll_segments']);
		$this->assertEqualsWithDelta(130.0, $r['required_hours'], 0.01);
		$this->assertEqualsWithDelta(
			$r['required_hours'],
			array_sum(array_column($r['soll_segments'], 'hours')),
			0.01
		);
		$this->assertSame($this->modelAId, $r['soll_segments'][0]['model_id']);
		$this->assertSame($this->modelBId, $r['soll_segments'][1]['model_id']);
		// R-07: scalars mirror the contract covering period_end (model B).
		$this->assertEqualsWithDelta(20.0, $r['weekly_hours'], 0.01);
	}

	public function testPiaScenarioSachsenDropsBusUndBettag(): void
	{
		$this->seedPia();
		$this->userSettings->setSetting(self::TEST_USER, 'german_state', 'SN');
		// Sanity: Buß- und Bettag must be a SN statutory holiday in 2026 or
		// this test's premise is wrong (then fail loudly, not skip silently).
		$this->assertGreaterThan(
			0.0,
			\OCP\Server::get(HolidayService::class)->getHolidayWeightForUser(
				self::TEST_USER, new \DateTime('2026-11-18')
			),
			'2026-11-18 must be a holiday for SN — statutory seed missing'
		);

		$r = $this->overtimeService()->calculateOvertime(
			self::TEST_USER, new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false
		);

		$this->assertEqualsWithDelta(125.0, $r['required_hours'], 0.01);
	}

	public function testStrictGapPolicyZeroesUncoveredMonth(): void
	{
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelAId,
			'startDate' => '2026-10-01',
			'endDate' => '2026-10-31',
		], 'int-test');
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelBId,
			'startDate' => '2026-12-01',
		], 'int-test');
		$this->config->setAppValue('arbeitszeitcheck', 'assignments_gap_policy', 'strict');

		$r = $this->overtimeService()->calculateOvertime(
			self::TEST_USER, new \DateTime('2026-10-01'), new \DateTime('2026-12-04'), false
		);

		$gap = array_values(array_filter($r['soll_segments'], fn ($s) => $s['basis'] === 'unassigned_gap'));
		$this->assertCount(1, $gap);
		$this->assertSame('2026-11-01', $gap[0]['start']);
		$this->assertSame('2026-11-30', $gap[0]['end']);
		$this->assertEqualsWithDelta(0.0, $gap[0]['hours'], 0.01);
		// Oct 60h + gap 0 + Dec 1–4 (Tue–Thu, 3 × 5h) = 75.
		$this->assertEqualsWithDelta(75.0, $r['required_hours'], 0.01);
	}

	public function testCreateAutoEndsOpenPredecessorAndRejectsOverlap(): void
	{
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelAId,
			'startDate' => '2026-10-01',
		], 'int-test');

		// A window engulfing a later row collides → 409.
		$this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelBId,
			'startDate' => '2026-11-01',
		], 'int-test');
		$rows = $this->assignmentMapper->findByUser(self::TEST_USER);
		$this->assertCount(2, $rows);
		$first = array_values(array_filter($rows, fn ($r) => $r->getWorkingTimeModelId() === $this->modelAId))[0];
		$this->assertSame('2026-10-31', $first->getEndDate()->format('Y-m-d'));

		try {
			$this->service->createAssignment(self::TEST_USER, [
				'modelId' => $this->modelAId,
				'startDate' => '2026-10-15',
				'endDate' => '2027-01-15',
			], 'int-test');
			$this->fail('expected overlap exception');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
		}
		// Nothing partial was written by the rejected write.
		$this->assertCount(2, $this->assignmentMapper->findByUser(self::TEST_USER));
	}

	public function testDeleteRejectsStartedRowAcceptsFutureRow(): void
	{
		$started = $this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelAId,
			'startDate' => '2000-01-01',
		], 'int-test');
		try {
			$this->service->deleteAssignment(self::TEST_USER, (int)$started['assignment']['id'], 'int-test');
			$this->fail('expected not-deletable');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_NOT_DELETABLE, $e->errorCode);
		}

		$future = $this->service->createAssignment(self::TEST_USER, [
			'modelId' => $this->modelBId,
			'startDate' => '2099-01-01',
			'endDate' => '2099-02-01',
		], 'int-test');
		$deleted = $this->service->deleteAssignment(self::TEST_USER, (int)$future['assignment']['id'], 'int-test');
		$this->assertTrue($deleted['deleted']);
		$remaining = array_filter(
			$this->assignmentMapper->findByUser(self::TEST_USER),
			fn ($r) => $r->getId() === (int)$future['assignment']['id']
		);
		$this->assertCount(0, $remaining);
	}

	public function testUnknownUserIs404(): void
	{
		try {
			$this->service->createAssignment('__no_such_user__', [
				'modelId' => $this->modelAId,
				'startDate' => '2026-10-01',
			], 'int-test');
			$this->fail('expected 404');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(404, $e->httpStatus);
		}
	}
}
