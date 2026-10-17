<?php

declare(strict_types=1);

/**
 * Freezes the special-entitlement domain state into month closures.
 *
 * The payload deliberately contains public labels and immutable IDs only. It
 * never turns entitlement movements into worked minutes and never exposes
 * confidential labels, policy reasons, actors, or free-text audit data.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriod;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper;
use OCA\ArbeitszeitCheck\Policy\MonthClosureContributor;

final class SpecialEntitlementMonthClosureContributor implements MonthClosureContributor
{
	public function __construct(
		private readonly SpecialEntitlementTypeMapper $types,
		private readonly SpecialEntitlementPeriodMapper $periods,
		private readonly SpecialEntitlementReservationMapper $reservations,
		private readonly SpecialEntitlementLedgerEntryMapper $ledger,
	) {
	}

	public function id(): string
	{
		return 'special_entitlements';
	}

	public function featureFlag(): string
	{
		return 'special_entitlements_enabled';
	}

	/** @return list<string> */
	public function blockers(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		$periodIds = array_map(
			static fn (SpecialEntitlementPeriod $period): int => (int)$period->getId(),
			$this->periods->findOverlappingRangeForUser($userId, $start, $end),
		);
		return $this->reservations->countActiveForPeriods($periodIds) > 0
			? ['SPECIAL_ENTITLEMENT_RESERVATION_ACTIVE']
			: [];
	}

	/** @return array<string,mixed> */
	public function snapshot(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		$typeIndex = [];
		foreach ($this->types->findAll(false) as $type) {
			$typeIndex[(int)$type->getId()] = $type;
		}
		$rows = [];
		foreach ($this->periods->findOverlappingRangeForUser($userId, $start, $end) as $period) {
			$type = $typeIndex[(int)$period->getEntitlementTypeId()] ?? null;
			$periodId = (int)$period->getId();
			$rows[] = [
				'id' => $periodId,
				'type_id' => (int)$period->getEntitlementTypeId(),
				'type_code' => $type instanceof SpecialEntitlementType ? (string)$type->getCode() : null,
				'public_label' => $type instanceof SpecialEntitlementType ? (string)$type->getPublicLabel() : null,
				'unit' => (string)$period->getUnit(),
				'period_start' => $period->getPeriodStart()?->format('Y-m-d'),
				'period_end' => $period->getPeriodEnd()?->format('Y-m-d'),
				'computed_amount' => number_format((float)$period->getComputedAmount(), 2, '.', ''),
				'posted_balance' => number_format($this->ledger->balanceForPeriod($periodId), 2, '.', ''),
				'active_reserved' => number_format($this->reservations->activeAmountForPeriod($periodId), 2, '.', ''),
				'status' => (string)$period->getStatus(),
				'revision' => (int)$period->getRevision(),
				'computation_snapshot_id' => $period->getComputationSnapshotId(),
			];
		}
		return ['periods' => $rows];
	}

	/** @return list<array<string,mixed>> */
	public function contributions(string $userId, string $month): array
	{
		[$start, $end] = $this->monthRange($month);
		$periods = $this->periods->findOverlappingRangeForUser($userId, $start, $end);
		$periodIds = array_map(static fn (SpecialEntitlementPeriod $period): int => (int)$period->getId(), $periods);
		$rows = [];
		foreach ($this->ledger->findCreatedBetween($periodIds, $start, $end) as $entry) {
			$rows[] = [
				'id' => (int)$entry->getId(),
				'event_key' => (string)$entry->getEventKey(),
				'period_id' => (int)$entry->getPeriodId(),
				'kind' => (string)$entry->getKind(),
				'signed_amount' => number_format(
					$entry->getKind() === 'debit' ? -(float)$entry->getAmount() : (float)$entry->getAmount(),
					2,
					'.',
					'',
				),
				'absence_id' => $entry->getAbsenceId(),
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
