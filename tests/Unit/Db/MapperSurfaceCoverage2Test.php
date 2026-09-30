<?php

declare(strict_types=1);

/**
 * Atlas coverage lane, part 2 — kiosk/vacation/overtime/team/misc mappers.
 * Same contract as MapperSurfaceCoverageTest: the stubbed cursor reports an
 * empty result set; DoesNotExist/MultipleObjectsReturned are the designed
 * outcomes, every other throwable is a real failure.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollment;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskSession;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskStampIdempotency;
use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Db\LicenseState;
use OCA\ArbeitszeitCheck\Db\LicenseStateMapper;
use OCA\ArbeitszeitCheck\Db\MobileSeat;
use OCA\ArbeitszeitCheck\Db\MobileSeatMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotency;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefault;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefault;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionToken;
use OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModule;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSet;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamManager;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicy;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper;
use OCA\ArbeitszeitCheck\Db\TerminalDevice;
use OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper;
use OCA\ArbeitszeitCheck\Db\UserSetting;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLog;
use OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalance;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class MapperSurfaceCoverage2Test extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb());
	}

	/** See MapperSurfaceCoverageTest::newQb — fluent methods must chain on self. */
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

	public function testKioskCredMapper(): void
	{
		$m = new KioskCredMapper($this->dbForEntity(KioskCred::class));
		$this->invoke($m, 'findByUserAndType', ['alice', 'pin']);
		$this->invoke($m, 'findByLookupHash', ['hash123']);
		$this->invoke($m, 'findById', [5]);
		$this->assertIsArray($this->invoke($m, 'findAll'));
		$this->assertIsArray($this->invoke($m, 'findByUserId', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findAllWithPin'));
		$this->addToAssertionCount(1);
	}

	public function testKioskEnrollmentMapper(): void
	{
		$m = new KioskEnrollmentMapper($this->dbForEntity(KioskEnrollment::class));
		$this->invoke($m, 'findActiveByTerminalId', ['term-1', $this->d()]);
		$this->invoke($m, 'findIncompleteByTerminalId', ['term-1']);
		$this->invoke($m, 'cancelForTerminal', ['term-1']);
		$this->invoke($m, 'deleteExpiredIncomplete', [$this->d()]);
		$this->invoke($m, 'findLatestCompletedByTerminalId', ['term-1']);
		$this->invoke($m, 'claimComplete', [5, $this->d(), $this->d()]);
		$this->addToAssertionCount(1);
	}

	public function testKioskSessionMapper(): void
	{
		$m = new KioskSessionMapper($this->dbForEntity(KioskSession::class));
		$this->invoke($m, 'deleteExpiredForTerminal', ['term-1', $this->d()]);
		$this->invoke($m, 'deleteUnusedForTerminal', ['term-1']);
		$this->invoke($m, 'deleteByUserId', ['alice']);
		$this->invoke($m, 'findValidSession', ['term-1', 'token', $this->d()]);
		$this->invoke($m, 'findUsedSession', ['term-1', 'token', $this->d()]);

		$session = new KioskSession();
		$session->setId(9);
		$this->invoke($m, 'markUsed', [$session]);
		$this->invoke($m, 'claimUnused', [$session, $this->d()]);
		$this->invoke($m, 'releaseClaim', [$session, $this->d()]);
		$this->addToAssertionCount(1);
	}

	public function testKioskStampIdempotencyMapper(): void
	{
		$m = new KioskStampIdempotencyMapper($this->dbForEntity(KioskStampIdempotency::class));
		$this->invoke($m, 'findByTerminalAndRequestId', ['term-1', 'req-1']);
		$this->invoke($m, 'tryInsert', ['term-1', 'req-1', '{"ok":true}', 123]);
		$this->invoke($m, 'updateResponseJson', ['term-1', 'req-1', '{"ok":true}']);
		$this->invoke($m, 'deleteByTerminalAndRequestId', ['term-1', 'req-1']);
		$this->addToAssertionCount(1);
	}

	public function testKioskTerminalMapper(): void
	{
		$m = new KioskTerminalMapper($this->dbForEntity(KioskTerminal::class));
		$this->invoke($m, 'findByTerminalId', ['term-1']);
		$this->assertIsArray($this->invoke($m, 'findAllActive'));
		$this->assertIsArray($this->invoke($m, 'findPendingPairing'));
		$this->invoke($m, 'claimPendingAsActive', ['term-1', 'tokenhash', $this->d(), 'Front desk']);
		$this->invoke($m, 'claimPendingAsActive', ['term-1', 'tokenhash', null]);
		$this->addToAssertionCount(1);
	}

	public function testLicenseStateMapper(): void
	{
		$m = new LicenseStateMapper($this->dbForEntity(LicenseState::class));
		$state = new LicenseState();
		$this->invoke($m, 'upsert', [$state]);

		// insert arm: empty cursor → findCurrent() null → insert + commit
		$emptyDb = $this->createMock(IDBConnection::class);
		$emptyDb->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb(false));
		$m2 = new LicenseStateMapper($emptyDb);
		$this->invoke($m2, 'upsert', [new LicenseState()]);
		$this->addToAssertionCount(1);
	}

	public function testMobileSeatMapper(): void
	{
		$m = new MobileSeatMapper($this->dbForEntity(MobileSeat::class));
		$this->assertIsArray($this->invoke($m, 'findAllOrdered'));
	}

	public function testMobileStampIdempotencyMapper(): void
	{
		$m = new MobileStampIdempotencyMapper($this->dbForEntity(MobileStampIdempotency::class));
		$this->invoke($m, 'deleteByUserAndRequestId', ['alice', 'req-1']);
		$this->addToAssertionCount(1);
	}

	public function testModelVacationDefaultMapper(): void
	{
		$m = new ModelVacationDefaultMapper($this->dbForEntity(ModelVacationDefault::class));
		$this->invoke($m, 'findActiveByModelAndDate', [3, $this->d()]);
		$this->assertIsArray($this->invoke($m, 'findByModelId', [3]));
		$this->invoke($m, 'find', [5]);
		$this->assertIsArray($this->invoke($m, 'findAll'));
		$this->invoke($m, 'deleteByModelId', [3]);
		$this->addToAssertionCount(1);
	}

	public function testOrgVacationDefaultMapper(): void
	{
		$m = new OrgVacationDefaultMapper($this->dbForEntity(OrgVacationDefault::class));
		$this->invoke($m, 'find', [5]);
		$this->invoke($m, 'countActiveByDate', [$this->d()]);
		$this->assertIsArray($this->invoke($m, 'findAll'));
		$this->assertIsArray($this->invoke($m, 'findOverlappingRanges', [$this->d(), null]));
		$this->assertIsArray($this->invoke($m, 'findOverlappingRanges', [$this->d(), $this->d('2026-12-31'), 5]));
		$this->invoke($m, 'closeOverlappingOpenRows', [$this->d()]);
		$this->addToAssertionCount(1);
	}

	public function testOutlookIcalSubscriptionTokenMapper(): void
	{
		$m = new OutlookIcalSubscriptionTokenMapper($this->dbForEntity(OutlookIcalSubscriptionToken::class));
		$this->invoke($m, 'findForScope', ['tenant', 'manager', 5]);
		$this->invoke($m, 'findActiveByTokenHash', ['tenant', 'manager', 5, 'hash']);
		$this->invoke($m, 'findActiveFor', ['tenant', 'manager', 5]);
		$this->invoke($m, 'findActiveByTeamAndTokenHash', ['tenant', 5, 'hash']);
		$this->assertIsArray($this->invoke($m, 'findAllActiveForTenant', ['tenant']));
		$this->invoke($m, 'revokeActiveFor', ['tenant', 'manager', 5, $this->d()]);
		$this->addToAssertionCount(1);
	}

	public function testOvertimeAdjustmentMapper(): void
	{
		$m = new OvertimeAdjustmentMapper($this->dbForEntity(OvertimeAdjustment::class));
		$this->invoke($m, 'sumHoursDeltaForYearThroughDate', ['alice', 2026, $this->d()]);
		$this->invoke($m, 'sumHoursDeltaForYear', ['alice', 2026]);
		$this->assertIsArray($this->invoke($m, 'findByUserAndYear', ['alice', 2026]));
		$this->assertIsArray($this->invoke($m, 'findByUserAndYear', ['alice', 2026, 10, 0]));
		$this->invoke($m, 'countByUserAndYear', ['alice', 2026]);
		$this->invoke($m, 'deleteByUserId', ['alice']);
		$this->invoke($m, 'insertAdjustment', [new \OCA\ArbeitszeitCheck\Db\OvertimeAdjustment()]);
		$this->addToAssertionCount(1);
	}

	public function testOvertimePayoutMapper(): void
	{
		$m = new OvertimePayoutMapper($this->dbForEntity(OvertimePayout::class));
		$this->invoke($m, 'existsForUserAndMonth', ['alice', 2026, 9]);
		$this->invoke($m, 'findByUserAndMonth', ['alice', 2026, 9]);
		$this->invoke($m, 'sumHoursPaidForYear', ['alice', 2026]);
		$this->invoke($m, 'sumHoursPaidForYear', ['alice', 2026, 6]);
		$this->invoke($m, 'sumHoursPaidForYearThroughMonth', ['alice', 2026, 9]);
		$this->assertIsArray($this->invoke($m, 'findFiltered', [null, null, null]));
		$this->assertIsArray($this->invoke($m, 'findFiltered', [2026, 9, 'alice', 50, 0]));
		$this->invoke($m, 'countFiltered', [null, null, null]);
		$this->invoke($m, 'countFiltered', [2026, 9, 'alice']);
		$this->assertIsArray($this->invoke($m, 'findByYearAndMonth', [2026, 9]));

		$payout = new OvertimePayout();
		$payout->setUserId('alice');
		$this->invoke($m, 'insertPayout', [$payout]);

		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByUser', ['alice', 10, 0]));
		$this->invoke($m, 'countByUser', ['alice']);
		$this->invoke($m, 'findLatestForUserInYear', ['alice', 2026]);
		$this->invoke($m, 'deleteByUserId', ['alice']);
		$this->addToAssertionCount(1);
	}

	public function testTariffRuleModuleMapper(): void
	{
		$m = new TariffRuleModuleMapper($this->dbForEntity(TariffRuleModule::class));
		$this->assertIsArray($this->invoke($m, 'findByRuleSetId', [5]));
		$this->invoke($m, 'deleteByRuleSetId', [5]);
		$this->addToAssertionCount(1);
	}

	public function testTariffRuleSetMapper(): void
	{
		$m = new TariffRuleSetMapper($this->dbForEntity(TariffRuleSet::class));
		$this->invoke($m, 'find', [5]);
		$this->invoke($m, 'findByCodeAndVersion', ['tv_laden', '2026']);
		$this->assertIsArray($this->invoke($m, 'findAllOrdered'));
		$this->assertIsArray($this->invoke($m, 'findActiveForDate', [$this->d()]));
		$this->assertIsArray($this->invoke($m, 'findActiveByTariffCode', ['tv_laden']));
		$this->addToAssertionCount(1);
	}

	public function testTeamManagerMapper(): void
	{
		$m = new TeamManagerMapper($this->dbForEntity(TeamManager::class));
		$this->invoke($m, 'removeManager', [5, 'alice']);
		$this->assertIsArray($this->invoke($m, 'findByUserId', ['alice']));
		$this->invoke($m, 'deleteByTeamId', [5]);
		$this->invoke($m, 'findDistinctManagerUserIds');
		$this->addToAssertionCount(1);
	}

	public function testTeamMapper(): void
	{
		$m = new TeamMapper($this->dbForEntity(Team::class));
		$this->assertIsArray($this->invoke($m, 'findAll'));
		$this->assertIsArray($this->invoke($m, 'findAll', [10, 0]));
		$this->invoke($m, 'find', [5]);
		$this->invoke($m, 'getParentMap');
		$this->assertIsArray($this->invoke($m, 'findByParentId', [null]));
		$this->assertIsArray($this->invoke($m, 'findByParentId', [3]));
		$this->assertSame(-1, $m->computeDepth(99, []));
		$this->assertSame(1, $m->computeDepth(3, [3 => 1, 1 => null]));
		$this->assertSame(-1, $m->computeDepth(3, [3 => 4, 4 => 3])); // cycle guard

		// traversal: first level yields the generic row, deeper levels come
		// back empty so the loop terminates as it would on a real DB.
		$m2 = new TeamMapper($this->dbWithRowThenEmpty(Team::class));
		$this->assertIsArray($this->invoke($m2, 'getIdsWithDescendants', [5]));
	}

	public function testTeamMemberMapper(): void
	{
		$m = new TeamMemberMapper($this->dbForEntity(TeamMember::class));
		$this->assertIsArray($this->invoke($m, 'findByUserId', ['alice']));
		$this->assertIsArray($this->invoke($m, 'findByTeamId', [5]));
		$this->invoke($m, 'deleteByTeamId', [5]);
	}

	public function testTeamVacationPolicyMapper(): void
	{
		$m = new TeamVacationPolicyMapper($this->dbForEntity(TeamVacationPolicy::class));
		$this->assertIsArray($this->invoke($m, 'findActiveByTeamIds', [[1, 2], $this->d()]));
		$this->assertIsArray($this->invoke($m, 'findByTeamId', [5]));
		$this->assertIsArray($this->invoke($m, 'findAll'));
		$this->invoke($m, 'find', [5]);
		$this->invoke($m, 'deleteByTeamId', [5]);
		$this->addToAssertionCount(1);
	}

	public function testTerminalDeviceMapper(): void
	{
		$m = new TerminalDeviceMapper($this->dbForEntity(TerminalDevice::class));
		$this->invoke($m, 'countActive');
		$this->assertIsArray($this->invoke($m, 'findAllActive'));
		$this->invoke($m, 'findByKioskTerminalId', ['term-1']);
		$this->invoke($m, 'findUnlinkedSlot');
		$this->addToAssertionCount(1);
	}

	public function testUserSettingsMapper(): void
	{
		$m = new UserSettingsMapper($this->dbForEntity(UserSetting::class));
		// getSetting→null → insert path
		$this->invoke($m, 'setSetting', ['alice', 'k', 'v']);
		$this->invoke($m, 'getBooleanSetting', ['alice', 'k', true]);
		$this->invoke($m, 'getIntegerSetting', ['alice', 'k', 7]);
		$this->invoke($m, 'getFloatSetting', ['alice', 'k', 1.5]);

		// null/empty stored values fall back to the caller's default
		$nullDb = $this->createMock(IDBConnection::class);
		$nullDb->method('getQueryBuilder')->willReturnCallback(
			fn(): IQueryBuilder => $this->newQb(true, ['setting_value' => null]),
		);
		$mNull = new UserSettingsMapper($nullDb);
		$this->assertSame(7, $this->invoke($mNull, 'getIntegerSetting', ['alice', 'k', 7]));
		$this->assertSame(1.5, $this->invoke($mNull, 'getFloatSetting', ['alice', 'k', 1.5]));
		$emptyDb = $this->createMock(IDBConnection::class);
		$emptyDb->method('getQueryBuilder')->willReturnCallback(
			fn(): IQueryBuilder => $this->newQb(true, ['setting_value' => '']),
		);
		$mEmpty = new UserSettingsMapper($emptyDb);
		$this->assertSame(7, $this->invoke($mEmpty, 'getIntegerSetting', ['alice', 'k', 7]));
		$this->assertSame(1.5, $this->invoke($mEmpty, 'getFloatSetting', ['alice', 'k', 1.5]));
		$this->assertIsArray($this->invoke($m, 'getUserSettings', ['alice']));
		$this->invoke($m, 'countDistinctUsersWithNonEmptySetting', ['k']);
		$this->invoke($m, 'findUserIdsWithNonEmptySetting', ['k']);
		$this->addToAssertionCount(1);
	}

	public function testVacationRolloverLogMapper(): void
	{
		$m = new VacationRolloverLogMapper($this->dbForEntity(VacationRolloverLog::class));
		$this->invoke($m, 'existsForUserAndYears', ['alice', 2025, 2026]);
		$this->invoke($m, 'deleteByUserAndYears', ['alice', 2025, 2026]);
		$this->addToAssertionCount(1);
	}

	public function testVacationYearBalanceMapper(): void
	{
		$m = new VacationYearBalanceMapper($this->dbForEntity(VacationYearBalance::class));
		$this->invoke($m, 'getCarryoverAmount', ['alice', 2026, true]);
		$this->invoke($m, 'getCarryoverAmount', ['alice', 2026, false]);
		$this->invoke($m, 'deleteByUserId', ['alice']);

		// upsert update arm: row found → setters + update
		$this->invoke($m, 'upsert', ['alice', 2026, 10.0, 5.0]);
		$this->invoke($m, 'upsert', ['alice', 2026, 10.0, null, true]);

		// upsert insert arm: empty cursor → DoesNotExist → insert
		$emptyDb = $this->createMock(IDBConnection::class);
		$emptyDb->method('getQueryBuilder')
			->willReturnCallback(fn(): IQueryBuilder => $this->newQb(false));
		$mInsert = new VacationYearBalanceMapper($emptyDb);
		$this->invoke($mInsert, 'upsert', ['alice', 2026, 10.0, 5.0]);
		$this->invoke($mInsert, 'upsert', ['alice', 2026, 10.0, null, true]);

		// unique-race retry: find empty → insert throws → re-find row → update
		$raceRow = $this->rowForEntity(VacationYearBalance::class);
		$raceDb = $this->dbWithQbSequence([
			$this->newQb(false),
			$this->newQbThrowingOnWrite(),
			$this->newQb(true, $raceRow),
			$this->newQb(true, $raceRow),
		]);
		$mRace = new VacationYearBalanceMapper($raceDb);
		$this->invoke($mRace, 'upsert', ['alice', 2026, 10.0, 5.0]);
		$this->addToAssertionCount(1);
	}
}
