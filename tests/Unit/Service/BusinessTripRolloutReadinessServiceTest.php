<?php

declare(strict_types=1);

/**
 * Rollout preflight contract (Phase 6): the legacy cutover is refused
 * while pending legacy trip requests exist, or while no reviewed+active
 * rule set reachable by at least one effective assignment exists.
 * Submitted new-domain trips and non-org scoping are reported evidence,
 * never gates — otherwise a healthy cutover could never go green.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class BusinessTripRolloutReadinessServiceTest extends TestCase
{
	private IDBConnection $db;
	private BusinessTripRuleSetMapper $ruleSets;
	private BusinessTripRuleAssignmentMapper $assignments;
	private BusinessTripRolloutReadinessService $service;

	/** @var list<int> counts returned in order (legacy pending, submitted) */
	private array $counts = [];

	protected function setUp(): void
	{
		$this->counts = [];
		$this->db = $this->createMock(IDBConnection::class);
		$this->ruleSets = $this->createMock(BusinessTripRuleSetMapper::class);
		$this->assignments = $this->createMock(BusinessTripRuleAssignmentMapper::class);
		$this->service = new BusinessTripRolloutReadinessService(
			$this->db,
			$this->ruleSets,
			$this->assignments,
		);
	}

	private function stubCounts(): void
	{
		$counts = $this->counts;
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$counts) {
			$count = array_shift($counts) ?? 0;
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($count);
			$queryFn = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
			$func = $this->createMock(IFunctionBuilder::class);
			$func->method('count')->willReturn($queryFn);
			$composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			// This NC version: scalar comparators return string, combinators
			// and `in` return ICompositeExpression.
			foreach (['eq', 'in', 'lte', 'gte', 'isNull'] as $m) {
				$expr->method($m)->willReturn('expr');
			}
			$expr->method('orX')->willReturn($composite);
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('func')->willReturn($func);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $m) {
				$qb->method($m)->willReturn($qb);
			}
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
	}

	private function activeReviewedRuleSet(): BusinessTripRuleSet
	{
		$s = new BusinessTripRuleSet();
		$s->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		$s->setReviewedAt(new \DateTime());
		$s->setReviewedBy('legal@corp');
		return $s;
	}

	private function orgAssignment(): BusinessTripRuleAssignment
	{
		$a = new BusinessTripRuleAssignment();
		$a->setScopeType(BusinessTripRuleAssignment::SCOPE_ORG);
		$a->setScopeId(BusinessTripRuleAssignment::ORG_SCOPE_ID);
		return $a;
	}

	public function testReadyWhenAllGreen(): void
	{
		$this->counts = [0, 0];
		$this->stubCounts();
		$this->ruleSets->method('findAll')->willReturn([$this->activeReviewedRuleSet()]);
		$this->assignments->method('findEffective')->willReturn([$this->orgAssignment()]);
		$p = $this->service->preflight();
		$this->assertTrue($p['ready']);
		$this->assertSame(0, $p['pendingLegacyTripAbsences']);
		$this->assertSame(1, $p['activeRuleSets']);
		$this->assertTrue($p['orgScopeCoverage']);
	}

	public function testPendingLegacyBlocks(): void
	{
		$this->counts = [2, 0];
		$this->stubCounts();
		$this->ruleSets->method('findAll')->willReturn([$this->activeReviewedRuleSet()]);
		$this->assignments->method('findEffective')->willReturn([$this->orgAssignment()]);
		$this->assertFalse($this->service->preflight()['ready']);
	}

	public function testUndecidedTripsAreReportedButDoNotBlock(): void
	{
		// Trips already in the new domain are the replacement working —
		// gating on them would make cutover unreachable in an active org.
		$this->counts = [0, 3];
		$this->stubCounts();
		$this->ruleSets->method('findAll')->willReturn([$this->activeReviewedRuleSet()]);
		$this->assignments->method('findEffective')->willReturn([$this->orgAssignment()]);
		$p = $this->service->preflight();
		$this->assertTrue($p['ready']);
		$this->assertSame(3, $p['submittedTripsUndecided']);
	}

	public function testUnreviewedActiveSetDoesNotCount(): void
	{
		$this->counts = [0, 0];
		$this->stubCounts();
		$unreviewed = new BusinessTripRuleSet();
		$unreviewed->setStatus(BusinessTripRuleSet::STATUS_ACTIVE); // legacy row, pre-1056
		$this->ruleSets->method('findAll')->willReturn([$unreviewed]);
		$this->assignments->method('findEffective')->willReturn([$this->orgAssignment()]);
		$p = $this->service->preflight();
		$this->assertSame(0, $p['activeRuleSets']);
		$this->assertFalse($p['ready']);
	}

	public function testNoEffectiveAssignmentBlocks(): void
	{
		// A reviewed+active rule set that reaches zero scopes cannot
		// evaluate anyone — cutover would strand every new trip.
		$this->counts = [0, 0];
		$this->stubCounts();
		$this->ruleSets->method('findAll')->willReturn([$this->activeReviewedRuleSet()]);
		$this->assignments->method('findEffective')->willReturn([]);
		$p = $this->service->preflight();
		$this->assertFalse($p['ready']);
		$this->assertSame(0, $p['effectiveAssignments']);
		$this->assertFalse($p['orgScopeCoverage']);
	}

	public function testTeamScopedCoverageSatisfiesTheGate(): void
	{
		// Org-scope is not mandatory — an org that assigns rules per team
		// must still be able to cut over.
		$team = new BusinessTripRuleAssignment();
		$team->setScopeType(BusinessTripRuleAssignment::SCOPE_TEAM);
		$team->setScopeId('7');
		$this->counts = [0, 0];
		$this->stubCounts();
		$this->ruleSets->method('findAll')->willReturn([$this->activeReviewedRuleSet()]);
		$this->assignments->method('findEffective')->willReturn([$team]);
		$p = $this->service->preflight();
		$this->assertTrue($p['ready']);
		$this->assertFalse($p['orgScopeCoverage']); // reported, not gated
	}
}
