<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Service\UserWorkingTimeAssignmentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Write-path semantics: auto-end on create (Wechsel), strict no-overlap on
 * PATCH, delete only for future rows, uniform-404 ownership, and the legacy
 * payload's edit-in-place / end-on-clear behaviour (MH-05..MH-08, BQ-05).
 */
class AssignmentWriteServiceTest extends TestCase
{
	private IUserManager $userManager;
	private UserWorkingTimeModelMapper $assignments;
	private WorkingTimeModelMapper $models;
	private AuditLogMapper $audit;
	private IL10N $l10n;
	private IDBConnection $db;
	private UserWorkingTimeAssignmentService $service;

	/** @var array<int, UserWorkingTimeModel> simulated table */
	private array $store = [];
	private int $seq = 0;
	/** @var list<array{action: string, entityId: ?int, oldValues: mixed, newValues: mixed}> */
	private array $auditEvents = [];

	/** @var array<string, string> uid => display name for removal lines */
	private array $displayNames = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->assignments = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->models = $this->createMock(WorkingTimeModelMapper::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'missing') {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn($this->displayNames[$uid] ?? '');
				return $user;
			}
		);
		$this->models->method('findPersonalByUser')->willReturn([]);
		$this->l10n->method('t')->willReturnArgument(0);

		// Simulated table: findByUser/find/insert/update/delete all hit $store.
		$this->assignments->method('findByUser')->willReturnCallback(
			fn (string $uid): array => array_values(array_filter(
				$this->store, fn (UserWorkingTimeModel $r) => $r->getUserId() === $uid
			))
		);
		$this->assignments->method('find')->willReturnCallback(function (int $id): UserWorkingTimeModel {
			if (!isset($this->store[$id])) {
				throw new DoesNotExistException('nope');
			}
			return $this->store[$id];
		});
		$this->assignments->method('insert')->willReturnCallback(function (UserWorkingTimeModel $r): UserWorkingTimeModel {
			$r->setId(++$this->seq + 100);
			$this->store[$r->getId()] = $r;
			return $r;
		});
		$this->assignments->method('update')->willReturnCallback(function (UserWorkingTimeModel $r): UserWorkingTimeModel {
			$this->store[$r->getId()] = $r;
			return $r;
		});
		$this->assignments->method('delete')->willReturnCallback(function (UserWorkingTimeModel $r): UserWorkingTimeModel {
			unset($this->store[$r->getId()]);
			return $r;
		});
		$this->assignments->method('findEditableByUser')->willReturnCallback(function (string $uid): ?UserWorkingTimeModel {
			$today = (new \DateTime('today'))->format('Y-m-d');
			$rows = array_values(array_filter($this->store, fn (UserWorkingTimeModel $r) => $r->getUserId() === $uid));
			foreach ($rows as $r) {
				$s = $r->getStartDate()?->format('Y-m-d');
				$e = $r->getEndDate()?->format('Y-m-d');
				if ($s !== null && $s <= $today && ($e === null || $e >= $today)) {
					return $r;
				}
			}
			usort($rows, fn ($a, $b) => [$b->getStartDate()?->format('Y-m-d') ?? '', $b->getId()] <=> [$a->getStartDate()?->format('Y-m-d') ?? '', $a->getId()]);
			return $rows[0] ?? null;
		});

		$this->audit->method('logAction')->willReturnCallback(
			function (string $uid, string $action, string $entity, ?int $entityId, $oldValues, $newValues, string $performedBy): \OCA\ArbeitszeitCheck\Db\AuditLog {
				$this->auditEvents[] = [
					'action' => $action,
					'entityId' => $entityId,
					'oldValues' => $oldValues,
					'newValues' => $newValues,
				];
				$log = new \OCA\ArbeitszeitCheck\Db\AuditLog();
				$log->setId(count($this->auditEvents));
				$log->setUserId($uid);
				$log->setAction($action);
				$log->setEntityType($entity);
				if ($entityId !== null) {
					$log->setEntityId($entityId);
				}
				$log->setOldValues(is_array($oldValues) ? (string)json_encode($oldValues) : null);
				$log->setPerformedBy($performedBy);
				$log->setCreatedAt(new \DateTime('2026-11-25 12:00:00'));
				$log->setIpAddress('203.0.113.9');
				$log->setUserAgent('secret-agent');
				return $log;
			}
		);

		$this->service = new UserWorkingTimeAssignmentService(
			$this->userManager,
			$this->assignments,
			$this->models,
			$this->audit,
			$this->l10n,
			$this->db,
		);
	}

	private function row(int $id, int $modelId, string $start, ?string $end = null): UserWorkingTimeModel
	{
		$r = new UserWorkingTimeModel();
		$r->setId($id);
		$r->setUserId('u1');
		$r->setWorkingTimeModelId($modelId);
		$r->setVacationDaysPerYear(25);
		$r->setStartDate(new \DateTime($start));
		if ($end !== null) {
			$r->setEndDate(new \DateTime($end));
		}
		$r->setCreatedAt(new \DateTime());
		$r->setUpdatedAt(new \DateTime());
		return $r;
	}

	private function model(int $id, ?string $owner = null): WorkingTimeModel
	{
		$m = new WorkingTimeModel();
		$m->setId($id);
		$m->setName('Model ' . $id);
		$m->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$m->setWeeklyHours(40.0);
		$m->setDailyHours(8.0);
		$m->setWorkDaysPerWeek(5.0);
		if ($owner !== null) {
			$m->setUserId($owner);
		}
		return $m;
	}

	private function useModels(array $models): void
	{
		$this->models->method('find')->willReturnCallback(function (int $id) use ($models): WorkingTimeModel {
			if (!isset($models[$id])) {
				throw new DoesNotExistException('nope');
			}
			return $models[$id];
		});
	}

	private function auditActions(): array
	{
		return array_column($this->auditEvents, 'action');
	}

	// ------------------------------------------------------------------

	public function testCreateInsertsRowAndAudits(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->createAssignment('u1', [
			'modelId' => 9,
			'startDate' => '2026-10-01',
			'vacationDaysPerYear' => 30,
		], 'admin');

		$this->assertTrue($result['assignment_changed']);
		$this->assertSame(9, $result['assignment']['model_id']);
		$this->assertSame('2026-10-01', $result['assignment']['start_date']);
		$this->assertNull($result['assignment']['end_date']);
		$this->assertEqualsWithDelta(30.0, $result['assignment']['vacation_days_per_year'], 1e-9);
		$this->assertSame([], $result['finalized_months_affected']);
		$this->assertSame(['user_working_time_model_created'], $this->auditActions());
	}

	public function testCreateAutoEndsImmediatePredecessorAndAuditsSplit(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', null);
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->createAssignment('u1', [
			'modelId' => 10,
			'startDate' => '2026-11-01',
		], 'admin');

		$this->assertSame('2026-10-31', $this->store[1]->getEndDate()->format('Y-m-d'));
		$actions = $this->auditActions();
		$this->assertContains('user_working_time_model_updated', $actions);
		$this->assertContains('user_working_time_model_created', $actions);
		// MH-08: the change itself is auditable as one split event.
		$this->assertContains('user_working_time_model_assignment_split', $actions);
		$split = $this->auditEvents[array_search('user_working_time_model_assignment_split', $actions)];
		$this->assertSame(10, $split['newValues']['assignment']['workingTimeModelId'] ?? null);
	}

	public function testCreateKeepsPredecessorTailAfterNewEnd(): void
	{
		// Predecessor end lies beyond the new window end — truncating would
		// silently drop coverage, so it must NOT auto-end → overlap 409.
		$this->store[1] = $this->row(1, 9, '2026-01-01', '2027-06-30');
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		try {
			$this->service->createAssignment('u1', [
				'modelId' => 10,
				'startDate' => '2026-11-01',
				'endDate' => '2026-11-30',
			], 'admin');
			$this->fail('expected overlap exception');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(409, $e->httpStatus);
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
		}
		$this->assertSame('2027-06-30', $this->store[1]->getEndDate()->format('Y-m-d'));
	}

	public function testCreateInsideExistingFutureRowAutoEndsIt(): void
	{
		// A future open row is still the "immediate predecessor": inserting
		// a window inside it truncates it at start−1, even though neither
		// window has taken effect yet (Wechsel semantics are date-only).
		$this->store[1] = $this->row(1, 9, '2026-12-01', null);
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->createAssignment('u1', [
			'modelId' => 10,
			'startDate' => '2026-12-15',
		], 'admin');
		$this->assertTrue($result['assignment_changed']);
		$this->assertSame('2026-12-14', $this->store[1]->getEndDate()->format('Y-m-d'));
	}

	public function testCreateEngulfingExistingRowIs409(): void
	{
		// The colliding row starts AFTER the new window's start → it is not
		// a predecessor → hard overlap, nothing auto-ended, nothing inserted.
		$this->store[1] = $this->row(1, 9, '2026-12-15', null);
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		try {
			$this->service->createAssignment('u1', [
				'modelId' => 10,
				'startDate' => '2026-12-01',
				'endDate' => '2026-12-31',
			], 'admin');
			$this->fail('expected overlap exception');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
			$this->assertNotEmpty($e->details);
		}
		$this->assertNull($this->store[1]->getEndDate());
	}

	public function testCreateSingleDayWindowIsValid(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$result = $this->service->createAssignment('u1', [
			'modelId' => 9,
			'startDate' => '2026-10-01',
			'endDate' => '2026-10-01',
		], 'admin');
		$this->assertTrue($result['assignment_changed']);
		$this->assertSame('2026-10-01', $result['assignment']['end_date']);
	}

	public function testCreateSameStartAsExistingRowIs409(): void
	{
		// Equal start is not a predecessor (start must be strictly earlier)
		// → hard overlap; auto-ending it would corrupt its own window.
		$this->store[1] = $this->row(1, 9, '2026-11-01', null);
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		try {
			$this->service->createAssignment('u1', [
				'modelId' => 10,
				'startDate' => '2026-11-01',
			], 'admin');
			$this->fail('expected overlap');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
		}
		$this->assertNull($this->store[1]->getEndDate());
	}

	public function testCreateAutoEndsPredecessorEndingOnNewEnd(): void
	{
		// Predecessor end == new end: no tail is lost by truncating at
		// start−1, so the split is allowed.
		$this->store[1] = $this->row(1, 9, '2026-01-01', '2026-11-30');
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->createAssignment('u1', [
			'modelId' => 10,
			'startDate' => '2026-11-01',
			'endDate' => '2026-11-30',
		], 'admin');
		$this->assertTrue($result['assignment_changed']);
		$this->assertSame('2026-10-31', $this->store[1]->getEndDate()->format('Y-m-d'));
	}

	public function testCreateAdjacentWindowsDontOverlap(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', '2026-10-31');
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->createAssignment('u1', [
			'modelId' => 10,
			'startDate' => '2026-11-01',
		], 'admin');
		$this->assertTrue($result['assignment_changed']);
		$this->assertCount(2, $this->store);
	}

	public function testCreateRejectsUnknownAndForeignPersonalModel(): void
	{
		$this->models->method('find')->willReturnCallback(function (int $id) {
			if ($id === 7) {
				return $this->model(7, 'other_user');
			}
			throw new DoesNotExistException('nope');
		});

		foreach ([999, 7] as $modelId) {
			try {
				$this->service->createAssignment('u1', [
					'modelId' => $modelId,
					'startDate' => '2026-10-01',
				], 'admin');
				$this->fail('expected 404 for model ' . $modelId);
			} catch (AdminUserProfileUpdateException $e) {
				$this->assertSame(404, $e->httpStatus);
				$this->assertSame(UserWorkingTimeAssignmentService::ERROR_MODEL_NOT_FOUND, $e->errorCode);
			}
		}
	}

	public function testCreateRejectsBadWindowAndDates(): void
	{
		$this->useModels([9 => $this->model(9)]);

		try {
			$this->service->createAssignment('u1', [
				'modelId' => 9,
				'startDate' => '2026-10-01',
				'endDate' => '2026-09-01',
			], 'admin');
			$this->fail('expected empty-window rejection');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_EMPTY_WINDOW, $e->errorCode);
			$this->assertArrayHasKey('end_date', $e->fieldErrors);
		}

		foreach (['bogus', '2026-13-40', '1969-01-01', '2101-01-01'] as $bad) {
			try {
				$this->service->createAssignment('u1', ['modelId' => 9, 'startDate' => $bad], 'admin');
				$this->fail('expected date rejection for ' . $bad);
			} catch (AdminUserProfileUpdateException $e) {
				$this->assertSame(UserWorkingTimeAssignmentService::ERROR_VALIDATION, $e->errorCode);
				$this->assertArrayHasKey('start_date', $e->fieldErrors);
			}
		}
	}

	public function testUpdateOverlappingRowIs409AndNeverAutoEnds(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', '2026-10-31');
		$this->store[2] = $this->row(2, 10, '2026-11-01', null);
		$this->useModels([9 => $this->model(9)]);

		try {
			// Move row 1's end INTO row 2's window — must 409, not truncate.
			$this->service->updateAssignment('u1', 1, ['endDate' => '2026-12-31'], 'admin');
			$this->fail('expected overlap');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
		}
		$this->assertSame('2026-10-31', $this->store[1]->getEndDate()->format('Y-m-d'));
	}

	public function testUpdateOwnWindowSucceeds(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', '2026-10-31');
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->updateAssignment('u1', 1, [
			'endDate' => '2026-09-30',
			'modelId' => 10,
			'vacationDaysPerYear' => 28,
		], 'admin');

		$this->assertSame('2026-09-30', $result['assignment']['end_date']);
		$this->assertSame(10, $result['assignment']['model_id']);
		$this->assertEqualsWithDelta(28.0, $result['assignment']['vacation_days_per_year'], 1e-9);
		$this->assertContains('user_working_time_model_updated', $this->auditActions());
	}

	public function testUpdateRowWithDeletedModelStaysEditable(): void
	{
		// A row whose model was deleted must still accept date/vacation edits
		// — re-sending the same model_id is a no-op, not a fresh assignment.
		$this->store[1] = $this->row(1, 99, '2026-01-01', '2026-10-31');
		$this->models->method('find')->willThrowException(new DoesNotExistException('gone'));

		$result = $this->service->updateAssignment('u1', 1, [
			'modelId' => 99,
			'endDate' => '2026-09-30',
		], 'admin');

		$this->assertSame('2026-09-30', $result['assignment']['end_date']);
		$this->assertSame(99, $this->store[1]->getWorkingTimeModelId());
	}

	public function testUpdateCrossUserRowIs404(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', null);
		$this->store[1]->setUserId('other_user');
		$this->useModels([9 => $this->model(9)]);

		try {
			$this->service->updateAssignment('u1', 1, ['endDate' => '2026-09-30'], 'admin');
			$this->fail('expected uniform 404');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(404, $e->httpStatus);
		}
	}

	public function testDeleteOnlyForFutureRows(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$this->store[1] = $this->row(1, 9, '2000-01-01', null); // started long ago
		$this->store[2] = $this->row(2, 9, '2999-01-01', null); // future

		try {
			$this->service->deleteAssignment('u1', 1, 'admin');
			$this->fail('expected not-deletable');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(409, $e->httpStatus);
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_NOT_DELETABLE, $e->errorCode);
		}
		$this->assertArrayHasKey(1, $this->store);

		$result = $this->service->deleteAssignment('u1', 2, 'admin');
		$this->assertTrue($result['deleted']);
		$this->assertArrayNotHasKey(2, $this->store);
		$this->assertContains('user_working_time_model_deleted', $this->auditActions());
		$this->assertSame('2999-01-01', $result['removed']['start_date']);
		$this->assertNull($result['removed']['end_date']);
		$this->assertSame('Model 9', $result['removed']['model_name']);
		$this->assertSame('admin', $result['removed']['removed_by']);
		$this->assertSame(
			['id', 'model_name', 'start_date', 'end_date', 'vacation_days_per_year', 'removed_at', 'removed_by'],
			array_keys($result['removed'])
		);
		$deletedAudit = null;
		foreach ($this->auditEvents as $event) {
			if ($event['action'] === 'user_working_time_model_deleted') {
				$deletedAudit = $event;
			}
		}
		$this->assertIsArray($deletedAudit);
		$this->assertSame('Model 9', $deletedAudit['oldValues']['modelName']);
		$this->assertFalse($deletedAudit['oldValues']['modelIsPersonal']);
	}

	// ---------------- legacy single-row payload (R-05/E-03) ----------------

	public function testLegacyPayloadEditsEditableRowInPlace(): void
	{
		// E-03: the profile save edits the active row — no auto-split,
		// no new row, same id.
		$this->store[1] = $this->row(1, 9, '2026-01-01', null);
		$this->useModels([9 => $this->model(9), 10 => $this->model(10)]);

		$result = $this->service->applyLegacyAssignmentPayload('u1', [
			'workingTimeModelId' => 10,
			'vacationDaysPerYear' => 28,
		], 'admin');

		$this->assertTrue($result['assignment_changed']);
		$this->assertCount(1, $this->store);
		$this->assertSame(10, $this->store[1]->getWorkingTimeModelId());
		$this->assertSame(28, $this->store[1]->getVacationDaysPerYear());
		$this->assertNull($this->store[1]->getEndDate());
		$this->assertContains('user_working_time_model_updated', $this->auditActions());
		$this->assertNotContains('user_working_time_model_created', $this->auditActions());
	}

	public function testLegacyPayloadUnchangedAssignmentSkipsWrite(): void
	{
		$this->store[1] = $this->row(1, 9, '2026-01-01', null);
		$this->useModels([9 => $this->model(9)]);

		$result = $this->service->applyLegacyAssignmentPayload('u1', [
			'workingTimeModelId' => 9,
		], 'admin');

		$this->assertFalse($result['assignment_changed']);
		$this->assertSame([], $this->auditActions());
	}

	public function testLegacyPayloadClearedModelEndsStartedRow(): void
	{
		// "No Model Assigned" on a started row closes it today — history kept.
		$this->store[1] = $this->row(1, 9, '2020-01-01', null);
		$this->useModels([9 => $this->model(9)]);

		$result = $this->service->applyLegacyAssignmentPayload('u1', [
			'workingTimeModelId' => '',
		], 'admin');

		$this->assertTrue($result['assignment_changed']);
		$this->assertSame(
			(new \DateTime('today'))->format('Y-m-d'),
			$this->store[1]->getEndDate()->format('Y-m-d')
		);
		$this->assertContains('user_working_time_model_ended', $this->auditActions());
	}

	public function testLegacyPayloadClearedModelDeletesFutureRow(): void
	{
		$this->store[1] = $this->row(1, 9, '2999-01-01', null);
		$this->useModels([9 => $this->model(9)]);

		$result = $this->service->applyLegacyAssignmentPayload('u1', [
			'workingTimeModelId' => '',
		], 'admin');

		$this->assertTrue($result['assignment_changed']);
		$this->assertSame([], $this->store);
		$this->assertContains('user_working_time_model_deleted', $this->auditActions());
	}

	public function testLegacyPayloadInsertsWhenNoRowExists(): void
	{
		$this->useModels([9 => $this->model(9)]);

		$result = $this->service->applyLegacyAssignmentPayload('u1', [
			'workingTimeModelId' => 9,
			'startDate' => '2026-01-01',
		], 'admin');

		$this->assertTrue($result['assignment_changed']);
		$this->assertCount(1, $this->store);
		$this->assertContains('user_working_time_model_created', $this->auditActions());
	}

	public function testLegacyPayloadOverlapGuardRejectsCollision(): void
	{
		// NN-10: the legacy path must still 409 when the edited window would
		// collide with a second row.
		$this->store[1] = $this->row(1, 9, '2026-01-01', null);   // editable (covers today)
		$this->store[2] = $this->row(2, 9, '2027-01-01', null);   // future row
		// Row 2 starts in the future but inside row 1's open window — store
		// it manually since creation would have auto-ended row 1.
		$this->useModels([9 => $this->model(9)]);

		try {
			// Moving row 1's end to 2027-06-30 collides with row 2.
			$this->service->applyLegacyAssignmentPayload('u1', [
				'workingTimeModelId' => 9,
				'endDate' => '2027-06-30',
			], 'admin');
			$this->fail('expected overlap');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_OVERLAP, $e->errorCode);
		}
	}

	public function testDeleteTodayStartIsNotDeletable(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$this->store[1] = $this->row(1, 9, (new \DateTime('today'))->format('Y-m-d'), null);

		try {
			$this->service->deleteAssignment('u1', 1, 'admin');
			$this->fail('expected not-deletable for today-start');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_NOT_DELETABLE, $e->errorCode);
		}
	}

	public function testRowCapRejectsBeyond500(): void
	{
		$this->useModels([9 => $this->model(9)]);
		for ($i = 1; $i <= UserWorkingTimeAssignmentService::MAX_ROWS_PER_USER; $i++) {
			$this->store[$i] = $this->row($i, 9, '2020-01-01', '2020-01-02');
		}
		try {
			$this->service->createAssignment('u1', ['modelId' => 9, 'startDate' => '2026-10-01'], 'admin');
			$this->fail('expected row-cap rejection');
		} catch (AdminUserProfileUpdateException $e) {
			$this->assertSame(UserWorkingTimeAssignmentService::ERROR_VALIDATION, $e->errorCode);
		}
	}

	public function testListRemovedDropsOtherUsersActionsEntitiesAndGarbage(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$this->audit->method('findByUserAction')->willReturn([
			$this->removedLog(1, 'u1', 'user_working_time_model_deleted', '2026-11-01 09:00:00', [
				'startDate' => '2999-04-01',
				'endDate' => '2999-04-30',
				'modelName' => 'Office',
				'vacationDaysPerYear' => 20,
			]),
			$this->removedLog(2, 'other', 'user_working_time_model_deleted', '2026-11-02 09:00:00', [
				'startDate' => '2999-05-01',
				'modelName' => 'Foreign employee',
			]),
			$this->removedLog(3, 'u1', 'user_working_time_model_updated', '2026-11-03 09:00:00', [
				'startDate' => '2999-06-01',
				'modelName' => 'Not a deletion',
			]),
			$this->removedLog(4, 'u1', 'user_working_time_model_deleted', '2026-11-04 09:00:00', [
				'startDate' => '2999-07-01',
				'modelName' => 'Wrong entity',
			], 'admin', 'working_time_model'),
			$this->removedLog(5, 'u1', 'user_working_time_model_deleted', '2026-11-05 09:00:00', [
				'startDate' => 'not-a-date',
				'modelName' => 'Bad date',
			]),
			$this->brokenLog(6),
		]);

		$result = $this->service->listRemovedForUser('u1');

		$this->assertFalse($result['truncated']);
		$this->assertCount(1, $result['entries']);
		$entry = $result['entries'][0];
		$this->assertSame('Office', $entry['model_name']);
		$this->assertSame('2999-04-01', $entry['start_date']);
		$this->assertSame('2999-04-30', $entry['end_date']);
		$this->assertSame(20.0, $entry['vacation_days_per_year']);
		$this->assertSame(
			['id', 'model_name', 'start_date', 'end_date', 'vacation_days_per_year', 'removed_at', 'removed_by'],
			array_keys($entry)
		);
		$this->assertStringNotContainsString('Foreign employee', json_encode($result));
		$this->assertStringNotContainsString('203.0.113.9', json_encode($result));
		$this->assertStringNotContainsString('secret-agent', json_encode($result));
	}

	public function testListRemovedResolvesPersonalLookupAndDeletedNames(): void
	{
		$this->useModels([9 => $this->model(9)]);
		$this->audit->method('findByUserAction')->willReturn([
			$this->removedLog(1, 'u1', 'user_working_time_model_deleted', '2026-11-01 09:00:00', [
				'startDate' => '2999-01-01',
				'modelName' => 'Office',
				'modelIsPersonal' => true,
			]),
			$this->removedLog(2, 'u1', 'user_working_time_model_deleted', '2026-11-02 09:00:00', [
				'startDate' => '2999-02-01',
				'workingTimeModelId' => 9,
			]),
			$this->removedLog(3, 'u1', 'user_working_time_model_deleted', '2026-11-03 09:00:00', [
				'startDate' => '2999-03-01',
				'workingTimeModelId' => 404,
			]),
		]);

		$names = array_column($this->service->listRemovedForUser('u1')['entries'], 'model_name');

		$this->assertSame(['Deleted model', 'Model 9', 'Individual contract'], $names);
	}

	public function testListRemovedCapsAtFiftyNewestFirst(): void
	{
		$logs = [];
		for ($i = 1; $i <= 51; $i++) {
			$logs[] = $this->removedLog($i, 'u1', 'user_working_time_model_deleted', sprintf('2026-01-%02d 08:00:00', min($i, 28)), [
				'startDate' => '2999-01-01',
				'modelName' => 'Row ' . $i,
			]);
		}
		$logs[] = $this->removedLog(9000, 'u1', 'user_working_time_model_deleted', '2099-01-01 08:00:00', [
			'startDate' => '2999-12-01',
			'modelName' => 'Newest',
		]);
		$this->audit->method('findByUserAction')->willReturn($logs);

		$result = $this->service->listRemovedForUser('u1');

		$this->assertTrue($result['truncated']);
		$this->assertCount(50, $result['entries']);
		$this->assertSame(9000, $result['entries'][0]['id']);
		$this->assertSame('Newest', $result['entries'][0]['model_name']);
		$this->assertNotContains(1, array_column($result['entries'], 'id'));
	}

	public function testListRemovedUsesDisplayNameThenUserId(): void
	{
		$this->displayNames['ada'] = 'Ada Admin';
		$this->audit->method('findByUserAction')->willReturn([
			$this->removedLog(1, 'u1', 'user_working_time_model_deleted', '2026-11-02 09:00:00', [
				'startDate' => '2999-02-01',
				'modelName' => 'Named',
			], 'ada'),
			$this->removedLog(2, 'u1', 'user_working_time_model_deleted', '2026-11-01 09:00:00', [
				'startDate' => '2999-01-01',
				'modelName' => 'Plain',
			], 'plain'),
		]);

		$by = array_column($this->service->listRemovedForUser('u1')['entries'], 'removed_by');

		$this->assertSame(['Ada Admin', 'plain'], $by);
	}

	public function testRemovedAuditQueryFiltersUserActionAndEntity(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/AuditLogMapper.php');
		$start = strpos($src, 'function findByUserAction');
		$end = strpos($src, 'function findByEntity');
		$this->assertNotFalse($start);
		$this->assertNotFalse($end);
		$body = substr($src, (int)$start, (int)$end - (int)$start);
		$this->assertStringContainsString("eq('user_id'", $body);
		$this->assertStringContainsString("eq('action'", $body);
		$this->assertStringContainsString("eq('entity_type'", $body);
		$this->assertStringContainsString('setMaxResults($limit)', $body);
		$this->assertStringContainsString("orderBy('created_at', 'DESC')", $body);
	}

	/**
	 * @param array<string, mixed> $old
	 */
	private function removedLog(
		int $id,
		string $userId,
		string $action,
		string $when,
		array $old,
		string $actor = 'admin',
		string $entityType = 'user_working_time_model'
	): \OCA\ArbeitszeitCheck\Db\AuditLog {
		$log = new \OCA\ArbeitszeitCheck\Db\AuditLog();
		$log->setId($id);
		$log->setUserId($userId);
		$log->setAction($action);
		$log->setEntityType($entityType);
		$log->setOldValues((string)json_encode($old));
		$log->setPerformedBy($actor);
		$log->setCreatedAt(new \DateTime($when));
		$log->setIpAddress('203.0.113.9');
		$log->setUserAgent('secret-agent');
		return $log;
	}

	private function brokenLog(int $id): \OCA\ArbeitszeitCheck\Db\AuditLog
	{
		$log = new \OCA\ArbeitszeitCheck\Db\AuditLog();
		$log->setId($id);
		$log->setUserId('u1');
		$log->setAction('user_working_time_model_deleted');
		$log->setEntityType('user_working_time_model');
		$log->setOldValues('{not json');
		$log->setPerformedBy('admin');
		$log->setCreatedAt(new \DateTime('2026-11-06 09:00:00'));
		return $log;
	}
}
