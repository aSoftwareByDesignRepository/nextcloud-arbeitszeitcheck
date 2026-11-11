<?php

declare(strict_types=1);

/**
 * US-402 — RecomputeAbsenceDaysSchedule repair step: schedule-aware
 * recompute of open absences. Idempotent writes, audit per row,
 * fraction/finalized/computed-zero skips, NULL backfill, CAS-light drift
 * skip under the user mutation lock. The real ScheduledWorkdayCounter is
 * used (final class) — the computed value is steered via HolidayService.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Repair\RecomputeAbsenceDaysSchedule;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class RecomputeAbsenceDaysScheduleTest extends TestCase
{
	private function absence(int $id, string $user, ?float $days, string $stamp = '2026-11-10T10:00:00+00:00'): Absence
	{
		$a = new Absence();
		$a->setId($id);
		$a->setUserId($user);
		$a->setStartDate(new \DateTime('2026-11-16'));
		$a->setEndDate(new \DateTime('2026-11-20'));
		$a->setDays($days);
		$a->setUpdatedAt(new \DateTime($stamp));
		$a->setStatus('approved');
		return $a;
	}

	private function db(array $idBatches): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('x IN (?)');
		$expr->method('gte')->willReturn('x >= ?');
		$i = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($expr, &$i, $idBatches) {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('expr')->willReturn($expr);
			foreach (['select','from','where','andWhere','orderBy','setMaxResults','setFirstResult'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => ':p');
			$res = $this->createMock(IResult::class);
			$res->method('fetchAll')->willReturn($idBatches[$i++] ?? []);
			$qb->method('executeQuery')->willReturn($res);
			return $qb;
		});
		return $db;
	}

	private function counterReturning(float $days): ScheduledWorkdayCounter
	{
		$uw = $this->createMock(UserWorkingTimeModelMapper::class);
		$uw->method('findCurrentByUser')->willReturn(null); // no schedule → Mon–Fri fallback path
		$wm = $this->createMock(WorkingTimeModelMapper::class);
		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn($days);
		return new ScheduledWorkdayCounter($uw, $wm, $holiday);
	}

	private function step(
		AbsenceMapper $mapper,
		AuditLogMapper $audit,
		ScheduledWorkdayCounter $counter,
		MonthClosureService $closure,
		?ILockingProvider $locks = null,
		?IDBConnection $db = null,
	): RecomputeAbsenceDaysSchedule {
		return new RecomputeAbsenceDaysSchedule(
			$db ?? $this->db([]),
			$mapper,
			$audit,
			$counter,
			$closure,
			$locks ?? $this->createMock(ILockingProvider::class),
		);
	}

	private function locksOk(): ILockingProvider
	{
		$l = $this->createMock(ILockingProvider::class);
		$l->method('acquireLock');
		$l->method('releaseLock');
		return $l;
	}

	private function closureOk(): MonthClosureService
	{
		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('assertDateRangeMutable');
		return $closure;
	}

	public function testChangedRowIsUpdatedAndAudited(): void
	{
		$a = $this->absence(7, 'alice', 5.0);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $x) => abs($x->getDays() - 4.0) < 0.001));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with('system', 'absence_days_recomputed', 'absence', 7,
				['days' => 5.0], ['days' => 4.0], 'system');

		$this->step($mapper, $audit, $this->counterReturning(4.0), $this->closureOk(),
			$this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testIdempotentSecondRunWritesNothing(): void
	{
		$a = $this->absence(7, 'alice', 4.0);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		$this->step($mapper, $audit, $this->counterReturning(4.0), $this->closureOk(),
			$this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testHalfDayFractionRowIsNeverTouched(): void
	{
		// Single-day row stored at half the schedule weight — a day_fraction
		// debit is request input, not calendar weight (FM-02).
		$a = $this->absence(7, 'alice', 0.5);
		$a->setEndDate(new \DateTime('2026-11-16'));
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(1.0),
			$this->createMock(MonthClosureService::class), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testQuarterFractionOnHalfWeightDayIsNeverTouched(): void
	{
		// 0.25 stored on a 0.5-weight day = a half-day — the old literal
		// days≈0.5 test would have re-inflated it to the full weight.
		$a = $this->absence(7, 'alice', 0.25);
		$a->setEndDate(new \DateTime('2026-11-16'));
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(0.5),
			$this->createMock(MonthClosureService::class), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testMultiDayUnderStoredRowIsRecomputed(): void
	{
		// A multi-day row stored below schedule weight is corruption/schedule
		// expansion, never a fraction — repair must still fix it.
		$a = $this->absence(7, 'alice', 0.5);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $x) => abs((float)$x->getDays() - 4.0) < 0.001));

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(4.0),
			$this->createMock(MonthClosureService::class), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testSingleDayNonFractionUnderStoreIsRepaired(): void
	{
		// 0.3 stored on a 1.0-weight single day is no valid fraction ratio
		// ({0.25,0.5,0.75} only) — corruption the repair must fix, not keep.
		$a = $this->absence(7, 'alice', 0.3);
		$a->setEndDate(new \DateTime('2026-11-16'));
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $x) => abs((float)$x->getDays() - 1.0) < 0.001));

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(1.0),
			$this->createMock(MonthClosureService::class), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testFinalizedMonthIsSkipped(): void
	{
		$a = $this->absence(7, 'alice', 5.0);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$closure = $this->createMock(MonthClosureService::class);
		$closure->method('assertDateRangeMutable')
			->willThrowException(new MonthFinalizedException('sealed'));

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(4.0),
			$closure, $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testNullDaysAreBackfilledWhenComputedPositive(): void
	{
		$a = $this->absence(7, 'alice', null);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Absence $x) => abs($x->getDays() - 4.0) < 0.001));

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with('system', 'absence_days_recomputed', 'absence', 7,
				['days' => null], ['days' => 4.0], 'system');

		$this->step($mapper, $audit, $this->counterReturning(4.0), $this->closureOk(),
			$this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testComputedZeroNeverZerosPositiveStored(): void
	{
		$a = $this->absence(7, 'alice', 5.0);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(0.0),
			$this->closureOk(), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testDriftedRowIsSkippedUnderLock(): void
	{
		$stale = $this->absence(7, 'alice', 5.0, '2026-11-10T10:00:00+00:00');
		$fresh = $this->absence(7, 'alice', 5.0, '2026-11-10T11:00:00+00:00');
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturnOnConsecutiveCalls($stale, $fresh);
		$mapper->expects($this->never())->method('update');

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(4.0),
			$this->closureOk(), $this->locksOk(), $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}

	public function testBusyLockSkipsRowWithoutFailure(): void
	{
		$a = $this->absence(7, 'alice', 5.0);
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($a);
		$mapper->expects($this->never())->method('update');

		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new \RuntimeException('locked'));

		$this->step($mapper, $this->createMock(AuditLogMapper::class), $this->counterReturning(4.0),
			$this->closureOk(), $locks, $this->db([[7]]))
			->run($this->createMock(IOutput::class));
	}
}
