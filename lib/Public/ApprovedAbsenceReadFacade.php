<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Public;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Suite flange F19 — approved-absence read facade.
 *
 * Sibling apps (CareCheck staffing hints, DutyCheck mirrors) learn *that*
 * a member is absent in a date range — never *why*. The row shape is
 * deliberately reason-free: `type`, `reason`, `approver_comment`, and
 * every other at_absences column are unreachable through this surface.
 * Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence.
 */
class ApprovedAbsenceReadFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Approved absences overlapping [from, to] (ISO dates, inclusive).
	 * Optionally restricted to one member uid.
	 *
	 * @return list<array{userId:string,startDate:string,endDate:string,status:string}>
	 */
	public function listApprovedForRange(string $from, string $to, ?string $uid = null): array
	{
		if (!$this->isIsoDate($from) || !$this->isIsoDate($to) || $from > $to) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user_id', 'start_date', 'end_date')
				->from('at_absences')
				->where($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($to)))
				->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($from)));
			if ($uid !== null && $uid !== '') {
				$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
			}
			$qb->orderBy('start_date', 'ASC')->addOrderBy('user_id', 'ASC');

			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$out[] = [
					'userId' => (string)$r['user_id'],
					'startDate' => substr((string)$r['start_date'], 0, 10),
					'endDate' => substr((string)$r['end_date'], 0, 10),
					'status' => Absence::STATUS_APPROVED,
				];
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->info('arbeitszeitcheck approved-absence facade failed', ['exception' => $e->getMessage()]);
			return [];
		}
	}

	private function isIsoDate(string $d): bool
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
	}
}
