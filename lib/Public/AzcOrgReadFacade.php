<?php
declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Public;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IDBConnection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Suite flange F20 — org-structure read facade.
 *
 * ExpenseCheck (and other sibling approver flows) learn *who* approves an
 * employee under AZC's own resolution mode — app teams when configured,
 * group mode otherwise. The facade hides the mode: consumers never branch
 * on `use_app_teams` themselves and never read `at_*` tables (S3).
 *
 * Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence — the EXP fallback is
 * its own `exp_manager_map` + admin-review queue.
 *
 * Contract (spec §12.2 F20):
 *   managersFor(uid)         — resolved manager/approver uids, escalation order
 *   canManage(m, e)          — may m decide for e under current mode
 *   hasManager(e)            — does e have *any* factual approver
 *   predicateSnapshot(e)     — {mode, approvers} audit snapshot
 *   substitutesFor(uid)      — uids acting for uid while uid is absent (MH-20)
 *   doorConfig()             — access-door mirror for the MH-22 setup assist
 *   teamIdsFor(uid)          — at_teams membership ids (MH-23 cost-center link)
 */
class AzcOrgReadFacade
{
	public const FACADE_VERSION = 1;
	private const APP_ID = 'arbeitszeitcheck';

	public function __construct(
		private readonly TeamResolverService $teams,
		private readonly IConfig $config,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Resolved approver uids for the employee — escalation order.
	 * App-teams: the employee's team managers. Group mode: enabled admins
	 * (the factual approvers AZC itself would route to).
	 *
	 * @return list<string>
	 */
	public function managersFor(string $employeeUid): array
	{
		if ($employeeUid === '') {
			return [];
		}
		try {
			$snapshot = $this->teams->approverPredicateSnapshot($employeeUid);
			$out = [];
			foreach ((array)($snapshot['approvers'] ?? []) as $uid) {
				$uid = (string)$uid;
				if ($uid !== '' && $uid !== $employeeUid) {
					$out[] = $uid;
				}
			}
			return array_values(array_unique($out));
		} catch (\Throwable $e) {
			$this->logger->info('AzcOrgReadFacade managersFor failed', ['exception' => $e->getMessage()]);
			return [];
		}
	}

	/** Whether $managerUid may decide for $employeeUid under the current mode. */
	public function canManage(string $managerUid, string $employeeUid): bool
	{
		if ($managerUid === '' || $employeeUid === '') {
			return false;
		}
		try {
			return $this->teams->canUserManageEmployee($managerUid, $employeeUid);
		} catch (\Throwable) {
			return false;
		}
	}

	/** Whether the employee has at least one factual approver. */
	public function hasManager(string $employeeUid): bool
	{
		if ($employeeUid === '') {
			return false;
		}
		try {
			return $this->teams->hasAssignableManagerForEmployee($employeeUid);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Audit snapshot of the resolution predicate — frozen into EXP
	 * `chain_snapshot` rows so a later mode flip (edge 22) stays explainable.
	 *
	 * @return array{mode:string,approvers:list<string>}
	 */
	public function predicateSnapshot(string $employeeUid): array
	{
		if ($employeeUid === '') {
			return ['mode' => 'unknown', 'approvers' => []];
		}
		try {
			$snapshot = (array)$this->teams->approverPredicateSnapshot($employeeUid);
			return [
				'mode' => (string)($snapshot['mode'] ?? 'unknown'),
				'approvers' => array_values(array_map('strval', (array)($snapshot['approvers'] ?? []))),
			];
		} catch (\Throwable) {
			return ['mode' => 'error', 'approvers' => []];
		}
	}

	/**
	 * MH-20 — who acts for $uid right now because $uid is absent and named
	 * them as substitute. Reads only AZC's own absence rows; a delegate the
	 * absent person chose, never an org-wide guess. Currently-approved or
	 * pending absences spanning today qualify.
	 *
	 * @return list<string>
	 */
	public function substitutesFor(string $uid): array
	{
		if ($uid === '') {
			return [];
		}
		try {
			$today = gmdate('Y-m-d');
			$qb = $this->db->getQueryBuilder();
			$qb->select('substitute_user_id')
				->from('at_absences')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->isNotNull('substitute_user_id'))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($today)))
				->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($today)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					[Absence::STATUS_APPROVED, Absence::STATUS_SUBSTITUTE_PENDING],
					\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
				->setMaxResults(50);
			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$s = (string)$r['substitute_user_id'];
				if ($s !== '' && $s !== $uid) {
					$out[$s] = true;
				}
			}
			return array_keys($out);
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * MH-22 — the AZC access-door config so sibling apps can offer a
	 * one-click prefill of their own door lists. Read-only mirror; the door
	 * itself stays per-app.
	 *
	 * @return array{mode:string,allowed_users:list<string>,allowed_groups:list<string>}
	 */
	public function doorConfig(): array
	{
		try {
			$restricted = $this->config->getAppValue(self::APP_ID, Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, '') === '1';
			$users = json_decode((string)$this->config->getAppValue(self::APP_ID, Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, '[]'), true);
			$groups = json_decode((string)$this->config->getAppValue(self::APP_ID, Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS, '[]'), true);
			return [
				'mode' => $restricted ? 'restricted' : 'open',
				'allowed_users' => is_array($users) ? array_values(array_map('strval', $users)) : [],
				'allowed_groups' => is_array($groups) ? array_values(array_map('strval', $groups)) : [],
			];
		} catch (\Throwable) {
			return ['mode' => 'open', 'allowed_users' => [], 'allowed_groups' => []];
		}
	}

	/**
	 * MH-23 — `at_teams` ids the user belongs to (app-teams mode only;
	 * group mode has no team ids to expose).
	 *
	 * @return list<int>
	 */
	public function teamIdsFor(string $uid): array
	{
		if ($uid === '') {
			return [];
		}
		try {
			if (!$this->teams->useAppTeams()) {
				return [];
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('team_id')
				->from('at_team_members')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->setMaxResults(100);
			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$id = (int)$r['team_id'];
				if ($id > 0) {
					$out[$id] = true;
				}
			}
			return array_map('intval', array_keys($out));
		} catch (\Throwable) {
			return [];
		}
	}
}
