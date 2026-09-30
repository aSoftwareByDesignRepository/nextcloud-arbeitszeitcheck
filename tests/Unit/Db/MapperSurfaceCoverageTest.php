<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — drives every uncovered mapper finder/mutator through
 * a fully-typed stubbed IDBConnection so the real QB-building code executes.
 *
 * The stubbed IResult reports an empty result set, so `findEntity`-style
 * methods legitimately end in DoesNotExistException — that still proves the
 * mapper's own lines (select/from/where/ordering) ran. Any *other* throwable
 * fails the test and points at a real defect.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshot;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCA\ArbeitszeitCheck\Db\MonthClosureMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosureRevision;
use OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class MapperSurfaceCoverageTest extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb());
	}

	/**
	 * The IQueryBuilder fluent methods carry no declared return types, so
	 * PHPUnit auto-stubbing would return null; wire them to chain on self and
	 * hand out expression/function-builder stubs. Typed methods
	 * (executeQuery/executeStatement/fetchAll/…) keep their generated stubs —
	 * an empty cursor by default.
	 */
	private function newQb(bool $withRow = true, ?array $rowOverride = null, ?array $fetchAllOverride = null): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectDistinct', 'selectAlias', 'addSelect', 'from', 'where', 'andWhere', 'orWhere',
			'join', 'innerJoin', 'leftJoin', 'rightJoin', 'groupBy', 'addGroupBy',
			'having', 'andHaving', 'orHaving', 'orderBy', 'addOrderBy',
			'setMaxResults', 'setFirstResult', 'setParameter', 'setParameters',
			'insert', 'values', 'setValue', 'delete', 'update', 'set',
		] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$qb->method('createFunction')->willReturn('computed');
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createParameter')->willReturn(':p');

		// fetch() is typed mixed -> auto-stub returns null, and mapper code that
		// loops on `fetch() !== false` would spin forever. Return one generic row
		// then stop: exercises hydration (fromRow) + the "found" branches while
		// still terminating every cursor loop.
		$result = $this->createMock(IResult::class);
		$row = $withRow ? ($rowOverride ?? [
			'id' => 7, 'user_id' => 'alice', 'setting_key' => 'k', 'setting_value' => '5',
			'year' => 2026, 'month' => 9, 'status' => 'completed', 'type' => 'vacation',
			'start_time' => '2026-09-01 08:00:00', 'end_time' => '2026-09-01 17:00:00',
			'start_date' => '2026-09-01', 'end_date' => '2026-09-05', 'date' => '2026-09-01',
			'terminal_id' => 'term-1', 'session_token' => 'tok', 'state' => 'BW',
			'scope' => 'state', 'parent_id' => null, 'team_id' => 3,
			'working_time_model_id' => 3, 'rule_set_id' => 5, 'name' => 'Standard',
			'entity_type' => 'time_entry', 'entity_id' => 7, 'action' => 'clock_in',
			'hours_delta' => 1.5, 'hours' => 1.5, 'period_key' => '2026-09',
			'tenant_id' => 'tenant', 'manager_user_id' => 'manager', 'token_hash' => 'h',
			'kiosk_terminal_id' => 'term-1', 'violation_type' => 'missing_break',
			'severity' => 'warning', 'resolved' => 0, 'client_request_id' => 'req-1',
		]) : null;
		$result->method('fetch')->willReturnOnConsecutiveCalls($row, false);
		// QBMapper::findEntity/findOneQuery consume fetchAssociative, not fetch.
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls($row !== null ? $row : false, false);
		$result->method('fetchAllAssociative')->willReturn($row ? [$row] : []);
		$result->method('fetchNumeric')->willReturn($row ? array_values($row) : false);
		$result->method('fetchOne')->willReturn($withRow ? '5' : false);
		$result->method('fetchColumn')->willReturn($withRow ? '5' : false);
		$result->method('fetchAll')->willReturn(
			$fetchAllOverride ?? ($withRow ? [
				['user_id' => 'alice', 'id' => 7, 'year_month' => '2026-09'],
			] : []),
		);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	/**
	 * DB whose first query builder returns the generic row and whose later
	 * builders return empty cursors — for cursor-loop traversals (recursive
	 * team walks) where a re-issued query must eventually come back empty.
	 */
	/**
	 * DB handing out the given builders in order — for multi-query flows like
	 * upsert retry (find → insert fails unique → re-find → update).
	 * @param list<IQueryBuilder> $qbs
	 */
	private function dbWithQbSequence(array $qbs): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$i = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($qbs, &$i): IQueryBuilder {
			return $qbs[min($i++, count($qbs) - 1)];
		});
		return $db;
	}

	/** QB whose write/execute calls throw — simulates a unique-key race on insert. */
	private function newQbThrowingOnWrite(): IQueryBuilder
	{
		$qb = $this->newQb(false);
		$ex = new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(
			$this->createMock(\Doctrine\DBAL\Driver\Exception::class),
			null,
		);
		$qb->method('executeStatement')->willThrowException($ex);
		return $qb;
	}

	private function dbWithRowThenEmpty(): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$first = true;
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$first): IQueryBuilder {
			$qb = $this->newQb($first);
			$first = false;
			return $qb;
		});
		return $db;
	}

	/**
	 * Run a mapper call; empty-result exceptions are the designed outcome of
	 * an empty stubbed cursor — everything else must fail the test.
	 */
	/**
	 * Build a hydratable DB row for a concrete entity: one snake_case column
	 * per declared property, values chosen to satisfy typed setters
	 * (DateTime-typed props get real instances; everything else a scalar).
	 */
	private function rowForEntity(string $entityClass): array
	{
		$row = ['id' => 7];
		foreach ((new \ReflectionClass($entityClass))->getProperties() as $prop) {
			// private props are runtime caches, not DB columns — hydration would
			// trip Entity's private-property guard; public props aren't columns
			if ($prop->isStatic() || !$prop->isProtected()) {
				continue;
			}
			$name = $prop->getName();
			if ($name === 'id' || str_starts_with($name, '_')) {
				continue;
			}
			$col = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
			$row[$col] = $this->scalarForProperty($prop);
		}
		return $row;
	}

	private function scalarForProperty(\ReflectionProperty $prop): mixed
	{
		$name = strtolower($prop->getName());
		$type = $prop->getType();
		$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
		return match (true) {
			$typeName === 'bool' => 0,
			$typeName === 'int' => 1,
			$typeName === 'float' => 1.5,
			$typeName === 'array' => '[]',
			in_array($typeName, [\DateTime::class, \DateTimeImmutable::class, \DateTimeInterface::class], true) => '2026-09-01 08:00:00',
			str_contains($name, 'userid') => 'alice',
			str_contains($name, 'time') || str_contains($name, 'date') || str_ends_with($name, 'at') => '2026-09-01 08:00:00',
			str_starts_with($name, 'is') || str_starts_with($name, 'has') => 0,
			str_contains($name, 'year') => 2026,
			str_contains($name, 'month') => 9,
			str_ends_with($name, 'json') || str_contains($name, 'payload') || str_contains($name, 'config') => '{}',
			str_contains($name, 'hour') || str_contains($name, 'day')
				|| str_contains($name, 'amount') || str_contains($name, 'balance')
				|| str_contains($name, 'count') || str_contains($name, 'minute')
				|| str_contains($name, 'rate') || str_contains($name, 'id') => 1.5,
			default => 'x',
		};
	}

	/** Every query builder from this DB hydrates one row of the given entity. */
	private function dbForEntity(string $entityClass): IDBConnection
	{
		$row = $this->rowForEntity($entityClass);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb(true, $row));
		return $db;
	}

	private function invoke(object $mapper, string $method, array $args = []): mixed
	{
		try {
			return $mapper->{$method}(...$args);
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
	}

	private function d(string $str = '2026-09-01 08:00:00'): \DateTime
	{
		return new \DateTime($str);
	}

	public function testAbsenceMapper(): void
	{
		$m = new AbsenceMapper($this->dbForEntity(Absence::class));
		$this->assertIsArray($this->invoke($m, 'findByUserAndDateRange', ['alice', $this->d(), $this->d()]));
		$this->assertIsArray($this->invoke($m, 'findByUsersAndDateRange', [['alice'], $this->d(), $this->d()]));
		$this->assertIsArray($this->invoke($m, 'findByUsersAndDateRange', [['alice'], $this->d(), $this->d(), 'approved', 'vacation', 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findPendingForUsers', [['alice']]));
		$this->assertIsArray($this->invoke($m, 'findPendingForUsers', [['alice'], 5, 0]));
		$this->assertIsArray($this->invoke($m, 'findActive'));
		$this->assertIsArray($this->invoke($m, 'findActive', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findByDateRange', [$this->d(), $this->d('2026-09-30')]));
		$this->invoke($m, 'countPendingForUsers', [['alice']]);
		$this->assertIsArray($this->invoke($m, 'findSubstitutePendingForUser', ['alice']));
		$this->invoke($m, 'getSickLeaveDays', ['alice', 2026]);
		$this->invoke($m, 'getUserStats', ['alice', 2026]);
		$this->invoke($m, 'deleteByUser', ['alice']);
		$this->invoke($m, 'clearSubstituteForUser', ['alice']);
		$this->invoke($m, 'getVacationDaysUsed', ['alice', 2026]);
		$this->assertIsArray($this->invoke($m, 'findVacationApprovedSpanningYearBoundary', ['alice', 2026]));
		$this->assertIsArray($this->invoke($m, 'findVacationApprovedOverlappingRange', ['alice', $this->d(), $this->d()]));
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d()]));
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d(), 3]));
		$this->invoke($m, 'lockUserAbsenceWindow', ['alice', $this->d(), $this->d()]);
		$this->invoke($m, 'countByUser', ['alice']);
		$this->invoke($m, 'countByUserTypeAndYear', ['alice', 'vacation', 2026]);
		$this->addToAssertionCount(1);
	}

	public function testAuditLogMapper(): void
	{
		$m = new AuditLogMapper($this->dbForEntity(AuditLog::class));
		$this->assertIsArray($this->invoke($m, 'findByEntity', ['time_entry']));
		$this->assertIsArray($this->invoke($m, 'findByEntity', ['time_entry', 5, 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findByAction', ['clock_in']));
		$this->assertIsArray($this->invoke($m, 'findByAction', ['clock_in', 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findByDateRange', [$this->d(), $this->d('2026-09-30')]));
		$this->assertIsArray($this->invoke($m, 'findByDateRange', [$this->d(), $this->d('2026-09-30'), 'alice', 'clock_in', 'time_entry']));
		$this->assertIsArray($this->invoke($m, 'searchByDateRange', [$this->d(), $this->d('2026-09-30'), ['user_id' => 'alice']]));
		$this->invoke($m, 'countByDateRange', [$this->d(), $this->d('2026-09-30'), ['action' => 'clock_in']]);
		$this->invoke($m, 'getStatistics', [[]]);
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->invoke($m, 'count', [[]]);
		$this->invoke($m, 'count', [['action' => 'clock_in', 'user_id' => 'alice']]);
		$this->invoke($m, 'cleanupOldLogs');
		$this->invoke($m, 'deleteByUser', ['alice']);
		$this->addToAssertionCount(1);
	}

	public function testComplianceViolationMapper(): void
	{
		$m = new ComplianceViolationMapper($this->dbForEntity(ComplianceViolation::class));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', true, 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', false]));
		$this->assertIsArray($this->invoke($m, 'findUnresolved'));
		$this->assertIsArray($this->invoke($m, 'findUnresolved', [10, 0]));
		$this->invoke($m, 'deleteByUser', ['alice']);
		$this->assertIsArray($this->invoke($m, 'findByType', ['missing_break']));
		$this->assertIsArray($this->invoke($m, 'findByType', ['missing_break', true, 10, 0]));
		$this->invoke($m, 'count', [[]]);
		$this->invoke($m, 'count', [['resolved' => false]]);
		$this->invoke($m, 'getStatistics', [[]]);

		// isTruthyDbValue arms: every driver-specific BOOLEAN materialisation
		$boolDb = $this->createMock(IDBConnection::class);
		$boolDb->method('getQueryBuilder')->willReturnCallback(
			fn(): IQueryBuilder => $this->newQb(true, null, [
				['cnt' => 2, 'resolved' => 't', 'violation_type' => 'missing_break', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => 'true', 'violation_type' => 'missing_break', 'severity' => 'error'],
				['cnt' => 1, 'resolved' => 'yes', 'violation_type' => 'long_shift', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => true, 'violation_type' => 'long_shift', 'severity' => 'error'],
				['cnt' => 3, 'resolved' => 1, 'violation_type' => 'missing_break', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => 0, 'violation_type' => 'missing_break', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => 'f', 'violation_type' => 'missing_break', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => null, 'violation_type' => 'missing_break', 'severity' => 'warning'],
				['cnt' => 1, 'resolved' => '0', 'violation_type' => 'missing_break', 'severity' => 'warning'],
			]),
		);
		$mBool = new ComplianceViolationMapper($boolDb);
		$stats = $this->invoke($mBool, 'getStatistics', [[]]);
		$this->assertSame(12, $stats['total_violations']);
		$this->assertSame(8, $stats['resolved_violations']);
		$this->assertSame(4, $stats['unresolved_violations']);
		$this->invoke($m, 'find', [5]);
		$this->invoke($m, 'resolveViolation', [5, 'manager1']);
		$this->invoke($m, 'createViolation', ['alice', 'missing_break', 'desc', $this->d()]);
		$this->invoke($m, 'createViolation', ['alice', 'missing_break', 'desc', $this->d(), 7, 'error']);
		$this->invoke($m, 'deleteByTimeEntryId', [7]);
		$this->assertIsArray($this->invoke($m, 'findByDateRange', [$this->d(), $this->d('2026-09-30')]));
		$this->assertIsArray($this->invoke($m, 'findByDateRange', [$this->d(), $this->d('2026-09-30'), 'alice', false]));
		$this->addToAssertionCount(1);
	}

	public function testEntitlementComputationSnapshotMapper(): void
	{
		$m = new EntitlementComputationSnapshotMapper($this->dbForEntity(EntitlementComputationSnapshot::class));
		$this->invoke($m, 'findLatestForUserAndPeriod', ['alice', '2026-09']);
		$this->invoke($m, 'findByUserPeriodAndAsOfDate', ['alice', '2026-09', $this->d()]);
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', 5]));

		$snapshot = new EntitlementComputationSnapshot();
		$snapshot->setUserId('alice');
		$snapshot->setPeriodKey('2026-09');
		$this->invoke($m, 'upsertSnapshot', [$snapshot]);

		// insert arm: no existing snapshot → insert
		$emptyDb = $this->createMock(IDBConnection::class);
		$emptyDb->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb(false));
		$mInsert = new EntitlementComputationSnapshotMapper($emptyDb);
		$this->invoke($mInsert, 'upsertSnapshot', [$snapshot]);

		// unique-race retry: find empty → insert throws → re-find row → update
		$raceRow = $this->rowForEntity(EntitlementComputationSnapshot::class);
		$raceDb = $this->dbWithQbSequence([
			$this->newQb(false),
			$this->newQbThrowingOnWrite(),
			$this->newQb(true, $raceRow),
			$this->newQb(true, $raceRow),
		]);
		$mRace = new EntitlementComputationSnapshotMapper($raceDb);
		$this->invoke($mRace, 'upsertSnapshot', [$snapshot]);
		$this->addToAssertionCount(1);
	}

	public function testHolidayMapper(): void
	{
		$m = new HolidayMapper($this->dbForEntity(Holiday::class));
		$this->assertIsArray($this->invoke($m, 'findByStateAndRange', ['BW', $this->d(), $this->d('2026-12-31')]));
		$this->invoke($m, 'hasHolidaysForStateAndYear', ['BW', 2026]);
		$this->invoke($m, 'existsForStateDateScope', ['BW', '2026-01-01', 'state']);
		$this->invoke($m, 'findIdForStateDateScope', ['BW', '2026-01-01', 'state']);
		$this->invoke($m, 'hasStatutoryHolidaysForStateAndYear', ['BW', 2026]);
		$this->addToAssertionCount(1);
	}

	public function testMonthClosureMapper(): void
	{
		$m = new MonthClosureMapper($this->dbForEntity(MonthClosure::class));
		$this->invoke($m, 'findDistinctFinalizedYearMonthsForUserIds', [['alice']]);
		$this->invoke($m, 'findDistinctFinalizedYearMonths');
		$this->invoke($m, 'findUserIdsWithFinalizedMonth', [2026, 9, null]);
		$this->invoke($m, 'findUserIdsWithFinalizedMonth', [2026, 9, ['alice']]);
		$this->invoke($m, 'findLatestFinalizedBefore', ['alice', 2026, 9]);
		$this->assertIsArray($this->invoke($m, 'findFinalizedByUserId', ['alice']));
		$this->addToAssertionCount(1);
	}

	public function testMonthClosureRevisionMapper(): void
	{
		$m = new MonthClosureRevisionMapper($this->dbForEntity(MonthClosureRevision::class));
		$this->assertIsArray($this->invoke($m, 'findByClosureId', [5]));
	}

	public function testTimeEntryMapper(): void
	{
		$config = $this->createMock(IConfig::class);
		$m = new TimeEntryMapper($this->dbForEntity(TimeEntry::class), $config);

		// private yearMonthFromStartTimeSql() is exercised transitively here
		$this->invoke($m, 'userHasTimeEntryInCalendarMonth', ['alice', 2026, 9]);
		$this->invoke($m, 'findActiveByUser', ['alice']);
		$this->assertIsArray($this->invoke($m, 'findLiveStatusByUserIds', [['alice', 'bob']]));
		$this->invoke($m, 'findPausedOrUnfinishedTodayByUser', ['alice']);
		$this->invoke($m, 'findPausedOrUnfinishedTodayByUser', ['alice', $this->d(), $this->d('2026-09-02')]);
		$this->invoke($m, 'findPendingOpenSessionByUser', ['alice']);
		$this->assertIsArray($this->invoke($m, 'findStalePausedAutomaticEntries', ['alice', $this->d()]));
		$this->assertIsArray($this->invoke($m, 'getTimeEntriesWithProjectInfo', [[]]));
		$this->assertIsArray($this->invoke($m, 'getTimeEntriesWithProjectInfo', [['user_id' => 'alice']]));
		$this->assertIsArray($this->invoke($m, 'findPendingApproval'));
		$this->assertIsArray($this->invoke($m, 'findPendingApproval', [10, 0]));
		$this->invoke($m, 'hasEntriesOnDate', ['alice', $this->d()]);
		$this->invoke($m, 'countDistinctUsersByDate', [$this->d()]);
		$this->invoke($m, 'findDistinctUserIdsByDate', [$this->d()]);
		$this->assertIsArray($this->invoke($m, 'findPendingApprovalForUsers', [['alice']]));
		$this->invoke($m, 'countPendingApprovalForUsers', [['alice']]);
		$this->assertIsArray($this->invoke($m, 'findByUsersAndDateRange', [['alice'], $this->d(), $this->d('2026-09-30')]));
		$this->assertIsArray($this->invoke($m, 'findByUsersAndDateRange', [['alice'], $this->d(), $this->d('2026-09-30'), 'completed', 10, 0]));
		$this->invoke($m, 'countByUsersAndDateRange', [['alice'], $this->d(), $this->d('2026-09-30')]);
		$this->invoke($m, 'countByUsersAndDateRange', [['alice'], $this->d(), $this->d('2026-09-30'), 'completed']);
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d('2026-09-01 12:00')]));
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d('2026-09-01 12:00'), 3]));
		$this->invoke($m, 'findLastCompletedBeforeTime', ['alice', $this->d()]);
		$this->invoke($m, 'findLastCompletedBeforeTime', ['alice', $this->d(), 3]);
		$this->invoke($m, 'findLastCompletedByUser', ['alice']);
		$this->invoke($m, 'findLastPausedWithinHours', ['alice']);
		$this->invoke($m, 'findLastPausedWithinHours', ['alice', 24]);
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', 10, 0]));
		$this->assertIsArray($this->invoke($m, 'findDistinctYearMonthStringsForUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findAllPausedByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUserAndStatus', ['alice', 'completed']));
		$this->invoke($m, 'getTotalBreakHoursByUserAndDateRange', ['alice', $this->d(), $this->d('2026-09-30')]);
		$this->invoke($m, 'countByUser', ['alice']);
		$this->invoke($m, 'count', [[]]);
		$this->invoke($m, 'count', [['user_id' => 'alice', 'status' => 'completed']]);

		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$this->invoke($m, 'updateIfPendingApproval', [$entry]);
		$this->addToAssertionCount(1);
	}

	public function testUserWorkingTimeModelMapper(): void
	{
		$m = new UserWorkingTimeModelMapper($this->dbForEntity(UserWorkingTimeModel::class));
		$this->invoke($m, 'endCurrentAssignment', ['alice']);
		$this->invoke($m, 'endCurrentAssignment', ['alice', $this->d()]);
		$this->invoke($m, 'deleteByUser', ['alice']);
		$this->assertIsArray($this->invoke($m, 'findByWorkingTimeModel', [3]));
		$this->assertIsArray($this->invoke($m, 'findByWorkingTimeModel', [3, false]));
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d('2026-09-30')]));
		$this->assertIsArray($this->invoke($m, 'findOverlapping', ['alice', $this->d(), $this->d('2026-09-30'), 3]));
		$this->addToAssertionCount(1);
	}

	public function testWorkingTimeModelMapper(): void
	{
		$m = new WorkingTimeModelMapper($this->dbForEntity(WorkingTimeModel::class));
		$this->assertIsArray($this->invoke($m, 'findByType', ['fixed']));
		$this->assertIsArray($this->invoke($m, 'searchByName', ['full']));
		$this->invoke($m, 'count');
		$this->invoke($m, 'findDefault');
		$this->invoke($m, 'clearDefaults');
		$this->invoke($m, 'clearDefaults', [7]);
		$this->addToAssertionCount(1);
	}
}
