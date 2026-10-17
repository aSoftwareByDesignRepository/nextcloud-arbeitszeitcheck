<?php

declare(strict_types=1);

/**
 * Readiness preflight for the Phase 6 rollout gates:
 *
 * - enabling `business_trips_enabled` is safe any time (feature is additive);
 * - disabling `legacy_trip_absence_create_enabled` (the spec §9.9 cutover)
 *   must be evidence-driven: it is refused while legacy `business_trip`
 *   absences still await a decision, or while no reviewed+active rule set
 *   reachable by an effective assignment exists to evaluate new trips.
 *
 * Historical/approved legacy absences never block — they remain valid
 * read/update data either way (AC-GLOBAL: legacy records stay creatable
 * until explicitly cut over, and stay readable forever).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCP\IDBConnection;

final class BusinessTripRolloutReadinessService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly BusinessTripRuleSetMapper $ruleSets,
		private readonly BusinessTripRuleAssignmentMapper $assignments,
	) {
	}

	/**
	 * @return array{
	 *   pendingLegacyTripAbsences:int,
	 *   submittedTripsUndecided:int,
	 *   activeRuleSets:int,
	 *   effectiveAssignments:int,
	 *   orgScopeCoverage:bool,
	 *   ready:bool
	 * }
	 */
	public function preflight(): array
	{
		$pendingLegacy = $this->countLegacyTripsByStatus([Absence::STATUS_PENDING]);
		$submitted = $this->countDomainTripsByStatus([BusinessTrip::STATUS_SUBMITTED]);
		$activeRuleSets = count(array_filter(
			$this->ruleSets->findAll(),
			static fn (BusinessTripRuleSet $s): bool => $s->getStatus() === BusinessTripRuleSet::STATUS_ACTIVE
				&& $s->getReviewedAt() !== null,
		));
		$orgCoverage = false;
		$effectiveAssignments = 0;
		foreach ($this->assignments->findEffective((new \DateTime('today'))->format('Y-m-d')) as $assignment) {
			$effectiveAssignments++;
			if ($assignment->getScopeType() === BusinessTripRuleAssignment::SCOPE_ORG) {
				$orgCoverage = true;
			}
		}
		return [
			'pendingLegacyTripAbsences' => $pendingLegacy,
			// Evidence, not gates: undecided new-domain trips mean the
			// replacement flow works, and org-scope is only one valid way to
			// cover all employees — gating on either would make a healthy
			// cutover unreachable (mirrors the work-location precedent:
			// only stranded legacy work blocks).
			'submittedTripsUndecided' => $submitted,
			'activeRuleSets' => $activeRuleSets,
			'effectiveAssignments' => $effectiveAssignments,
			'orgScopeCoverage' => $orgCoverage,
			// Gates: nothing stranded on the legacy path, and the replacement
			// can actually evaluate — a reviewed+active rule set that reaches
			// at least one scope. orgScopeCoverage stays reported evidence
			// for the admin, not a hard gate (team/model scoping is valid).
			'ready' => $pendingLegacy === 0 && $activeRuleSets > 0 && $effectiveAssignments > 0,
		];
	}

	/** @param list<string> $statuses */
	private function countLegacyTripsByStatus(array $statuses): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_absences')
			->where($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_BUSINESS_TRIP)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @param list<string> $statuses */
	private function countDomainTripsByStatus(array $statuses): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_btrip')
			->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
