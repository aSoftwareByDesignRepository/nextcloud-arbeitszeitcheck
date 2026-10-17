<?php

declare(strict_types=1);

/**
 * Readiness preflight for closing the legacy `home_office` absence create
 * path (Workforce Policy Suite, Phase 3 — spec §9.9 Phase B→C).
 *
 * The admin migration card renders these numbers; the flag flip refuses
 * `legacy_home_absence_create_enabled=false` while relevant legacy requests
 * still exist, so the cutover is evidence-driven, not hopeful.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCP\IDBConnection;

final class WorkLocationMigrationReadinessService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly WorkLocationDefaultPolicyMapper $policyMapper,
	) {
	}

	/**
	 * @return array{
	 *   openHomeOfficeAbsences:int,
	 *   pendingHomeOfficeAbsences:int,
	 *   futureHomeOfficeAbsences:int,
	 *   legacyDefaultUsersWithoutPolicy:int,
	 *   ready:bool
	 * }
	 */
	public function preflight(): array
	{
		$today = (new \DateTime('today'))->format('Y-m-d');
		$pending = $this->countHomeOffice('status', Absence::STATUS_PENDING, null);
		$future = $this->countHomeOffice(null, null, $today);
		$open = $this->countHomeOffice(null, null, null) - $this->countHomeOffice('status', Absence::STATUS_CANCELLED, null) - $this->countHomeOffice('status', Absence::STATUS_REJECTED, null);

		$unmigrated = 0;
		foreach ($this->userSettingsMapper->findUserIdsWithNonEmptySetting(Constants::SETTING_WORK_LOCATION_DEFAULT) as $userId) {
			if ($this->policyMapper->findByScope(WorkLocationDefaultPolicy::SCOPE_USER, $userId) === []) {
				$unmigrated++;
			}
		}

		return [
			'openHomeOfficeAbsences' => $open,
			'pendingHomeOfficeAbsences' => $pending,
			'futureHomeOfficeAbsences' => $future,
			'legacyDefaultUsersWithoutPolicy' => $unmigrated,
			// Ready when nothing still depends on the legacy create path:
			// no pending requests awaiting decision and no unmigrated defaults.
			// Historical/future approved absences stay valid data — they do
			// not block the cutover (they only keep rendering).
			'ready' => $pending === 0 && $unmigrated === 0,
		];
	}

	private function countHomeOffice(?string $statusField, ?string $status, ?string $fromDate): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_absences')
			->where($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_HOME_OFFICE)));
		if ($statusField !== null && $status !== null) {
			$qb->andWhere($qb->expr()->eq($statusField, $qb->createNamedParameter($status)));
		}
		if ($fromDate !== null) {
			$qb->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($fromDate)));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
