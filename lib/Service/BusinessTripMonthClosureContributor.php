<?php

declare(strict_types=1);

/**
 * Freezes the business-trip domain into month closures.
 *
 * Blockers: submitted (undecided) trips overlapping the month — a closure
 * must never finalize while an approval is pending. Contributions are the
 * immutable ledger events only; the payload never mutates worked minutes
 * and never exposes purpose text or free-text reasons.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Policy\MonthClosureContributor;

final class BusinessTripMonthClosureContributor implements MonthClosureContributor
{
	public function __construct(
		private readonly BusinessTripMapper $trips,
		private readonly BusinessTripDayCalculationMapper $dayCalcs,
		private readonly BusinessTripCreditLedgerMapper $ledger,
	) {
	}

	public function id(): string
	{
		return 'business_trips';
	}

	public function featureFlag(): string
	{
		return 'business_trips_enabled';
	}

	/** @return list<string> */
	public function blockers(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		return $this->trips->findClosureBlockers($userId, new \DateTime($start), new \DateTime($end . ' 23:59:59')) !== []
			? ['BUSINESS_TRIP_SUBMITTED_PENDING']
			: [];
	}

	/** @return array<string,mixed> */
	public function snapshot(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		$windowStart = new \DateTime($start);
		$windowEnd = new \DateTime($end . ' 23:59:59');
		$rows = [];
		foreach ($this->trips->findForUserBetween($userId, $windowStart, $windowEnd) as $trip) {
			$days = [];
			foreach ($this->dayCalcs->findForTrip((int)$trip->getId()) as $calc) {
				$localDate = $calc->getLocalDate()?->format('Y-m-d');
				if ($localDate === null || $localDate < $start || $localDate > $end) {
					continue;
				}
				$days[] = [
					'local_date' => $localDate,
					'rule_set_id' => (int)$calc->getRuleSetId(),
					'compliance_minutes' => (int)$calc->getComplianceMinutes(),
					'account_top_up_minutes' => (int)$calc->getAccountTopUpMinutes(),
					'export_minutes' => (int)$calc->getExportMinutes(),
					'snapshot_hash' => (string)$calc->getSnapshotHash(),
				];
			}
			$rows[] = [
				'id' => (int)$trip->getId(),
				'status' => (string)$trip->getStatus(),
				'starts_at' => $trip->getStartsAtUtc()?->format('c'),
				'ends_at' => $trip->getEndsAtUtc()?->format('c'),
				'evaluation_timezone' => (string)$trip->getEvaluationTimezone(),
				'revision' => (int)$trip->getRevision(),
				'current_snapshot_id' => $trip->getCurrentSnapshotId(),
				'days' => $days,
			];
		}
		return ['trips' => $rows];
	}

	/** @return list<array<string,mixed>> */
	public function contributions(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		$rows = [];
		foreach ($this->ledger->findForUserBetween($userId, $start, $end) as $entry) {
			$rows[] = [
				'id' => (int)$entry->getId(),
				'event_key' => (string)$entry->getEventKey(),
				'trip_id' => (int)$entry->getTripId(),
				'local_date' => $entry->getLocalDate()?->format('Y-m-d'),
				'kind' => (string)$entry->getKind(),
				'minutes' => (int)$entry->getMinutes(),
				'created_at' => $entry->getCreatedAt()?->format('c'),
			];
		}
		return $rows;
	}

	public function canonicalHash(string $userId, string $month): string
	{
		return hash('sha256', MonthClosureCanonical::encode([
			'snapshot' => $this->snapshot($userId, $month),
			'contributions' => $this->contributions($userId, $month),
			'blockers' => $this->blockers($userId, $month),
		]));
	}

	/** @return array{0:string,1:string} */
	private function monthRange(string $month): array
	{
		if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1) {
			throw new \InvalidArgumentException('Invalid month period.');
		}
		$start = new \DateTimeImmutable($month . '-01');
		return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
	}
}
