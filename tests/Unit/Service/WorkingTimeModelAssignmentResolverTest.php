<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The single resolution authority: covering row → gap-policy branch →
 * lenient chain → legacy — strict (money) vs lenient (assist), plus the
 * range segmentation and the one-fetch query budget (NN-02).
 */
class WorkingTimeModelAssignmentResolverTest extends TestCase
{
	private UserWorkingTimeModelMapper $assignments;
	private WorkingTimeModelMapper $models;
	private IConfig $config;
	private string $gapPolicy = 'lenient';
	private WorkingTimeModelAssignmentResolver $resolver;

	protected function setUp(): void
	{
		parent::setUp();
		$this->assignments = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->models = $this->createMock(WorkingTimeModelMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, ?string $default = null) => $key === 'assignments_gap_policy' ? $this->gapPolicy : $default
		);
		$this->resolver = new WorkingTimeModelAssignmentResolver(
			$this->assignments,
			$this->models,
			$this->config
		);
	}

	private function row(int $id, int $modelId, ?string $start, ?string $end = null, ?int $vacationDays = 25): UserWorkingTimeModel
	{
		$row = new UserWorkingTimeModel();
		$row->setId($id);
		$row->setUserId('u1');
		$row->setWorkingTimeModelId($modelId);
		$row->setVacationDaysPerYear($vacationDays);
		if ($start !== null) {
			$row->setStartDate(new \DateTime($start));
		}
		if ($end !== null) {
			$row->setEndDate(new \DateTime($end));
		}
		return $row;
	}

	private function model(int $id, string $type = WorkingTimeModel::TYPE_FULL_TIME, float $weekly = 40.0, float $daily = 8.0, array $schedule = null): WorkingTimeModel
	{
		$model = new WorkingTimeModel();
		$model->setId($id);
		$model->setName('Model ' . $id);
		$model->setType($type);
		$model->setWeeklyHours($weekly);
		$model->setDailyHours($daily);
		$model->setWorkDaysPerWeek(5.0);
		if ($schedule !== null) {
			$model->setBreakRulesArray($schedule);
		}
		return $model;
	}

	/** map model ids → models; missing ids throw like the real mapper. */
	private function modelsById(array $models): void
	{
		$this->models->method('find')->willReturnCallback(function (int $id) use ($models): WorkingTimeModel {
			if (!isset($models[$id])) {
				throw new DoesNotExistException('nope');
			}
			return $models[$id];
		});
	}

	public function testCoveringRowResolvesItsModel(): void
	{
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31', 30),
		]);
		$m = $this->model(9, weekly: 20.0, daily: 5.0);
		$this->modelsById([9 => $m]);

		$c = $this->resolver->resolveStrict('u1', new \DateTime('2026-10-15'));
		$this->assertSame($m, $c->model);
		$this->assertSame(5, $c->assignmentId);
		$this->assertSame('weekly_contract', $c->basis);
		$this->assertEqualsWithDelta(20.0, $c->weeklyHours, 1e-9);
		$this->assertEqualsWithDelta(5.0, $c->dailyHours, 1e-9);
		$this->assertFalse($c->lenientFallback);
		$this->assertEqualsWithDelta(30.0, $c->vacationDaysPerYear, 1e-9);
	}

	public function testEndDateIsInclusive(): void
	{
		$this->gapPolicy = 'strict';
		$this->resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $this->config);
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31'),
		]);
		$this->modelsById([9 => $this->model(9)]);
		$this->models->method('findAll')->willReturn([]);

		$this->assertSame(5, $this->resolver->resolveStrict('u1', new \DateTime('2026-10-31'))->assignmentId);
		$this->assertSame('unassigned_gap', $this->resolver->resolveStrict('u1', new \DateTime('2026-11-01'))->basis);
	}

	public function testStrictGapPolicyOnUncoveredDay(): void
	{
		$this->gapPolicy = 'strict';
		$this->resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $this->config);
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31'),
			$this->row(6, 9, '2026-12-01', null),
		]);
		$this->modelsById([9 => $this->model(9)]);

		$c = $this->resolver->resolveStrict('u1', new \DateTime('2026-11-15'));
		$this->assertSame('unassigned_gap', $c->basis);
		$this->assertNull($c->model);
		$this->assertNull($c->assignmentId);
		$this->assertEqualsWithDelta(0.0, $c->weeklyHours, 1e-9);
		$this->assertFalse($c->lenientFallback);
	}

	public function testLenientGapPolicyFallsBackToRowCoveringToday(): void
	{
		// Default lenient: a gap day resolves through the assignment active
		// today — the pre-feature findCurrentByUser semantics. The resolved
		// day is before the row's start so it cannot cover it, while its
		// open window always covers "today" (run-date independent).
		$this->assignments->method('findByUser')->willReturn([
			$this->row(6, 10, '2000-01-01', null),
		]);
		$current = $this->model(10, weekly: 30.0, daily: 6.0);
		$this->modelsById([10 => $current]);

		$c = $this->resolver->resolveStrict('u1', new \DateTime('1999-06-15'));
		$this->assertSame($current, $c->model);
		$this->assertSame(6, $c->assignmentId);
		$this->assertTrue($c->lenientFallback);
		$this->assertEqualsWithDelta(30.0, $c->weeklyHours, 1e-9);
	}

	public function testZeroRowsYieldsLegacyContract(): void
	{
		$this->assignments->method('findByUser')->willReturn([]);
		$this->models->method('findAll')->willReturn([]); // org has no models

		$c = $this->resolver->resolveStrict('u1', new \DateTime('2026-11-15'));
		$this->assertSame('weekly_contract', $c->basis);
		$this->assertEqualsWithDelta(40.0, $c->weeklyHours, 1e-9);
		$this->assertEqualsWithDelta(8.0, $c->dailyHours, 1e-9);
		$this->assertFalse($c->lenientFallback);
	}

	public function testZeroRowsWithOrgModelsIsDegraded(): void
	{
		$this->assignments->method('findByUser')->willReturn([]);
		$this->models->method('findAll')->willReturn([$this->model(1)]);

		$this->assertSame('degraded', $this->resolver->resolveStrict('u1', new \DateTime('2026-11-15'))->basis);
	}

	public function testLenientNeverProducesUnassignedGap(): void
	{
		// Strict policy + a day before the only (open) row: strict marks
		// it unassigned_gap, lenient still answers a usable contract.
		$this->gapPolicy = 'strict';
		$this->resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $this->config);
		$this->assignments->method('findByUser')->willReturn([
			$this->row(6, 10, '2000-01-01', null), // covers today, not the resolved day
		]);
		$this->modelsById([10 => $this->model(10)]);
		$this->models->method('findAll')->willReturn([]);

		$this->assertSame('unassigned_gap', $this->resolver->resolveStrict('u1', new \DateTime('1999-06-01'))->basis);
		$c = $this->resolver->resolveLenient('u1', new \DateTime('1999-06-01'));
		$this->assertNotSame('unassigned_gap', $c->basis);
		$this->assertEqualsWithDelta(40.0, $c->weeklyHours, 1e-9);
		$this->assertTrue($c->lenientFallback);
	}

	public function testOverlapTiebreakLatestStartThenHighestId(): void
	{
		// E-13: two rows covering the same day → latest start wins; tie → id.
		$this->assignments->method('findByUser')->willReturn([
			$this->row(1, 9, '2026-10-01', null),
			$this->row(2, 10, '2026-10-15', null),
			$this->row(3, 11, '2026-10-15', null),
		]);
		$this->modelsById([9 => $this->model(9), 10 => $this->model(10), 11 => $this->model(11)]);

		$this->assertSame(3, $this->resolver->resolveStrict('u1', new \DateTime('2026-10-20'))->assignmentId);
		$this->assertSame(11, $this->resolver->resolveStrict('u1', new \DateTime('2026-10-20'))->model->getId());
	}

	public function testOnDemandModelCarriesZeroSoll(): void
	{
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', null),
		]);
		$this->modelsById([9 => $this->model(9, type: WorkingTimeModel::TYPE_ON_DEMAND, weekly: 0.0, daily: 0.0)]);

		$c = $this->resolver->resolveStrict('u1', new \DateTime('2026-10-15'));
		$this->assertSame('on_demand', $c->basis);
		$this->assertEqualsWithDelta(0.0, $c->weeklyHours, 1e-9);
		$this->assertEqualsWithDelta(0.0, $c->dailyHours, 1e-9);
	}

	public function testDeletedModelDegradesButKeepsAssignmentId(): void
	{
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', null),
		]);
		$this->models->method('find')->willThrowException(new DoesNotExistException('gone'));

		$c = $this->resolver->resolveStrict('u1', new \DateTime('2026-10-15'));
		$this->assertSame('degraded', $c->basis);
		$this->assertSame(5, $c->assignmentId);
		$this->assertNull($c->model);
		$this->assertEqualsWithDelta(40.0, $c->weeklyHours, 1e-9);
	}

	public function testMalformedRowCountsAsRowButCannotCover(): void
	{
		// R-09: a row with null start cannot cover, but its existence still
		// selects the gap branch (not the zero-row legacy branch).
		$this->gapPolicy = 'strict';
		$this->resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $this->config);
		$row = new UserWorkingTimeModel();
		$row->setId(7);
		$row->setUserId('u1');
		$row->setWorkingTimeModelId(9);
		// no start date — malformed
		$this->assignments->method('findByUser')->willReturn([$row]);
		$this->modelsById([9 => $this->model(9)]);

		$this->assertSame('unassigned_gap', $this->resolver->resolveStrict('u1', new \DateTime('2026-10-15'))->basis);
	}

	public function testResolveRangeSplitsAtWindowBoundary(): void
	{
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31'),
			$this->row(6, 10, '2026-11-01', null),
		]);
		$this->modelsById([9 => $this->model(9, weekly: 15.0), 10 => $this->model(10, weekly: 20.0)]);

		$segments = $this->resolver->resolveRange('u1', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'));
		$this->assertCount(2, $segments);
		$this->assertSame('2026-10-01', $segments[0]->start->format('Y-m-d'));
		$this->assertSame('2026-10-31', $segments[0]->end->format('Y-m-d'));
		$this->assertSame(5, $segments[0]->contract->assignmentId);
		$this->assertSame('2026-11-01', $segments[1]->start->format('Y-m-d'));
		$this->assertSame('2026-11-24', $segments[1]->end->format('Y-m-d'));
		$this->assertSame(6, $segments[1]->contract->assignmentId);
	}

	public function testResolveRangeMergesAdjacentSameContractRows(): void
	{
		// Two rows, same model → one segment (no spurious boundary).
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31'),
			$this->row(6, 9, '2026-11-01', null),
		]);
		$this->modelsById([9 => $this->model(9)]);

		$segments = $this->resolver->resolveRange('u1', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'));
		$this->assertCount(1, $segments);
		$this->assertSame('2026-10-01', $segments[0]->start->format('Y-m-d'));
		$this->assertSame('2026-11-24', $segments[0]->end->format('Y-m-d'));
	}

	public function testResolveRangeStrictGapSegmentBetweenRows(): void
	{
		$this->gapPolicy = 'strict';
		$this->resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $this->config);
		$this->assignments->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-10-01', '2026-10-31'),
			$this->row(6, 9, '2026-12-01', null),
		]);
		$this->modelsById([9 => $this->model(9)]);

		$segments = $this->resolver->resolveRange('u1', new \DateTime('2026-10-01'), new \DateTime('2026-12-10'));
		$this->assertCount(3, $segments);
		$this->assertSame('unassigned_gap', $segments[1]->contract->basis);
		$this->assertSame('2026-11-01', $segments[1]->start->format('Y-m-d'));
		$this->assertSame('2026-11-30', $segments[1]->end->format('Y-m-d'));
	}

	public function testResolveRangeFetchesAssignmentsOnceForAYearRange(): void
	{
		// NN-02 query budget: one findByUser for the whole range — no
		// per-day queries.
		$this->assignments->expects($this->exactly(1))->method('findByUser')->willReturn([
			$this->row(5, 9, '2026-01-01', null),
		]);
		$this->models->expects($this->once())->method('find')->willReturn($this->model(9));

		$segments = $this->resolver->resolveRange('u1', new \DateTime('2026-01-01'), new \DateTime('2026-12-31'));
		$this->assertCount(1, $segments);
	}

	public function testInvalidateUserRefetches(): void
	{
		$this->assignments->expects($this->exactly(2))->method('findByUser')->willReturn([]);
		$this->models->method('findAll')->willReturn([]);

		$this->resolver->resolveStrict('u1', new \DateTime('2026-10-15'));
		$this->resolver->invalidateUser('u1');
		$this->resolver->resolveStrict('u1', new \DateTime('2026-10-16'));
	}
}
