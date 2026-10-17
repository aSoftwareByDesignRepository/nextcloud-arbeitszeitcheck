<?php

declare(strict_types=1);

/**
 * Special-entitlement reservation and ledger service.
 *
 * A reservation can only be created inside the same DB transaction as its
 * absence. Approval is the only path that consumes it into a debit movement;
 * rejection/cancellation release it exactly once. Corrections are additional
 * signed ledger entries — historical rows are never edited or deleted.
 */

namespace OCA\ArbeitszeitCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntry;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriod;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;
use OCA\ArbeitszeitCheck\Policy\IdempotencyKey;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

final class SpecialEntitlementService
{
	private const EPSILON = 0.0000001;

	public function __construct(
		private readonly WorkforceSuiteConfig $suiteConfig,
		private readonly SpecialEntitlementTypeMapper $types,
		private readonly SpecialEntitlementPeriodMapper $periods,
		private readonly SpecialEntitlementReservationMapper $reservations,
		private readonly SpecialEntitlementLedgerEntryMapper $ledger,
		private readonly SpecialEntitlementResolver $resolver,
		private readonly VacationHoursDebitService $plannedHours,
		private readonly EntitlementSnapshotService $snapshots,
		private readonly AuditLogMapper $audit,
		private readonly AbsenceMapper $absences,
		private readonly ILockingProvider $lockingProvider,
		private readonly IDBConnection $db,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->suiteConfig->isEnabled('special_entitlements_enabled')
			&& $this->suiteConfig->isEnabled('policy_cohorts_enabled');
	}

	/**
	 * Acquire definition/policy/period locks before the generic absence-user
	 * lock. The returned context must be passed to completeReservation() in the
	 * absence transaction and to releaseReservationContext() in finally.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>|null
	 */
	public function beginReservation(string $userId, array $data): ?array
	{
		if (!$this->isEnabled()) {
			return null;
		}
		$absenceTypeCode = $this->scalar($data['type'] ?? null);
		if ($absenceTypeCode === null || $absenceTypeCode === '') {
			return null;
		}
		$type = $this->types->findByAbsenceTypeCode($absenceTypeCode);
		if ($type === null) {
			return null;
		}
		$requestKey = $this->requestKey($data, $userId);
		$existingReservation = $this->reservations->findByRequestKey($requestKey);
		if ($existingReservation !== null) {
			try {
				$existingAbsence = $this->absences->find((int)$existingReservation->getAbsenceId());
			} catch (DoesNotExistException) {
				$existingAbsence = null;
			}
			if ($existingAbsence !== null
				&& $existingAbsence->getUserId() === $userId
				&& $existingAbsence->getType() === $absenceTypeCode) {
				return ['replayAbsence' => $existingAbsence, 'locks' => []];
			}
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'This request was already submitted.', 409, ['request_key' => 'duplicate']);
		}

		$locks = [];
		$this->acquire($locks, DbLockKeys::specialEntitlementType((int)$type->getId()), 'Special entitlement type');
		$this->acquire($locks, DbLockKeys::specialEntitlementPolicy((int)$type->getId()), 'Special entitlement policies');
		try {
			$type = $this->types->findByCode((string)$type->getCode());
			if ($type === null || !$type->getActive()) {
				throw new SpecialEntitlementException('ENTITLEMENT_DISABLED', 'This entitlement is not active.', 409, ['type' => 'disabled']);
			}
			$start = $this->parseDate($data['start_date'] ?? null, 'start_date');
			$end = $this->parseDate($data['end_date'] ?? null, 'end_date');
			if ($start > $end) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'End date must not be before the start date.', 400, ['end_date' => 'after_start']);
			}
			$resolution = $this->resolver->resolve($type, $userId, $start);
			if ($end > $resolution['periodEnd']) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'The request crosses an entitlement period boundary.', 400, ['end_date' => 'period_boundary']);
			}
			$this->acquire(
				$locks,
				DbLockKeys::specialEntitlementPeriod($userId, (int)$type->getId(), $resolution['periodStart']->format('Y-m-d')),
				'Special entitlement period'
			);
			return [
				'type' => $type,
				'userId' => $userId,
				'resolution' => $resolution,
				'locks' => $locks,
				'requestKey' => $requestKey,
				'data' => $data,
			];
		} catch (\Throwable $e) {
			$this->releaseLockList($locks);
			throw $e;
		}
	}

	/** @param array<string,mixed> $context */
	public function requestAmount(array $context, Absence $absence): float
	{
		$type = $context['type'];
		$data = $context['data'];
		if ($type->getUnit() === SpecialEntitlementType::UNIT_DAYS) {
			$fraction = $this->dayFraction($data['day_fraction'] ?? null);
			if ($fraction < 1.0 && $absence->getStartDate()->format('Y-m-d') !== $absence->getEndDate()->format('Y-m-d')) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'A partial day is only supported for a single-day request.', 400, ['day_fraction' => 'single_day']);
			}
			$workingDays = (float)($absence->getDays() ?? 0.0);
			if ($workingDays < 0.25) {
				throw new SpecialEntitlementException('NO_PLANNED_HOURS', 'The selected period contains no planned working time.', 422, ['end_date' => 'no_planned_time']);
			}
			return round($workingDays * $fraction, 2, PHP_ROUND_HALF_UP);
		}

		$minutes = $this->explicitMinutes($data['entitlement_minutes'] ?? null)
			?? $this->hoursToMinutes($data['duration_hours'] ?? null);
		if ($minutes === null) {
			$planned = $this->plannedHours->estimateForUserRange(
				$absence->getUserId(),
				$absence->getStartDate(),
				$absence->getEndDate()
			);
			$minutes = (int)round(((float)($planned['hours'] ?? 0.0)) * 60);
		}
		if ($minutes <= 0) {
			throw new SpecialEntitlementException('NO_PLANNED_HOURS', 'The selected period contains no planned working time.', 422, ['duration_hours' => 'no_planned_time']);
		}
		if ($minutes > 240000) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The requested amount exceeds the supported limit.', 400, ['duration_hours' => 'range']);
		}
		return round($minutes / 60.0, 2, PHP_ROUND_HALF_UP);
	}

	/**
	 * Runs inside the absence DB transaction. The caller already owns the
	 * absence-user lock; all entitlement locks were acquired by beginReservation.
	 *
	 * @param array<string,mixed> $context
	 */
	public function completeReservation(array $context, Absence $absence, float $amount, string $actorUid): SpecialEntitlementReservation
	{
		$type = $context['type'];
		$resolution = $context['resolution'];
		if (!$resolution['enabled']) {
			throw new SpecialEntitlementException('ENTITLEMENT_DISABLED', 'The effective policy disables this entitlement.', 409, ['type' => 'disabled']);
		}
		$period = $this->ensurePeriod($type, (string)$context['userId'], $resolution, $actorUid);
		if ($period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
			throw new SpecialEntitlementException('PERIOD_CLOSED', 'This entitlement period is closed.', 409, ['period' => 'closed']);
		}

		$requestKey = (string)$context['requestKey'];
		$existing = $this->reservations->findByRequestKey($requestKey);
		if ($existing !== null) {
			if ((int)$existing->getAbsenceId() === (int)$absence->getId()) {
				return $existing;
			}
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'This request was already submitted.', 409, ['request_key' => 'duplicate']);
		}

		$reserved = $this->reservations->activeAmountForPeriod((int)$period->getId());
		$ledgerBalance = $this->ledger->balanceForPeriod((int)$period->getId());
		$available = $resolution['computedAmount'] + $ledgerBalance - $reserved;
		if (!$type->getAllowNegative() && $amount > $available + self::EPSILON) {
			throw new SpecialEntitlementException('ENTITLEMENT_EXCEEDED', 'The request exceeds the available entitlement.', 409, ['amount' => 'entitlement_exceeded']);
		}

		$reservation = new SpecialEntitlementReservation();
		$reservation->setPeriodId((int)$period->getId());
		$reservation->setAbsenceId((int)$absence->getId());
		$reservation->setAmount($amount);
		$reservation->setStatus(SpecialEntitlementReservation::STATUS_ACTIVE);
		$reservation->setRequestKey($requestKey);
		$reservation->setRevision(1);
		$now = new \DateTime();
		$reservation->setCreatedAt($now);
		$reservation->setUpdatedAt($now);
		try {
			$reservation = $this->reservations->insert($reservation);
		} catch (UniqueConstraintViolationException $e) {
			$existing = $this->reservations->findByRequestKey($requestKey)
				?? $this->reservations->findByAbsenceId((int)$absence->getId());
			if ($existing !== null) {
				throw new SpecialEntitlementException('REVISION_CONFLICT', 'This request was already submitted.', 409, ['request_key' => 'duplicate']);
			}
			throw $e;
		}

		$this->audit->logAction(
			(string)$context['userId'],
			'special_entitlement_reserved',
			'special_entitlement_reservation',
			(int)$reservation->getId(),
			null,
			[
				'entitlement_type_id' => (int)$type->getId(),
				'period_id' => (int)$period->getId(),
				'absence_id' => (int)$absence->getId(),
				'amount' => $amount,
				'available_after' => round($available - $amount, 2, PHP_ROUND_HALF_UP),
			],
			$actorUid,
		);
		return $reservation;
	}

	/** @param array<string,mixed>|null $context */
	public function releaseReservationContext(?array $context): void
	{
		if ($context === null) {
			return;
		}
		$this->releaseLockList(array_reverse($context['locks'] ?? []));
	}

	/**
	 * Re-price an active reservation after a pending absence is edited.
	 * Called inside the absence transaction after the absence row update.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $oldSummary
	 */
	public function syncForUpdatedAbsence(Absence $absence, array $data, array $oldSummary, string $actorUid): void
	{
		$reservation = $this->reservations->findByAbsenceId((int)$absence->getId());
		if ($reservation === null || $reservation->getStatus() !== SpecialEntitlementReservation::STATUS_ACTIVE) {
			return;
		}
		$type = $this->types->findByAbsenceTypeCode((string)$absence->getType());
		if ($type === null) {
			return;
		}

		$locks = [];
		try {
			$this->acquire($locks, DbLockKeys::specialEntitlementType((int)$type->getId()), 'Special entitlement type');
			$this->acquire($locks, DbLockKeys::specialEntitlementPolicy((int)$type->getId()), 'Special entitlement policies');
			$resolution = $this->resolver->resolve($type, $absence->getUserId(), $absence->getStartDate());
			if ($absence->getEndDate() > $resolution['periodEnd']) {
				throw new SpecialEntitlementException('VALIDATION_ERROR', 'The request crosses an entitlement period boundary.', 400, ['end_date' => 'period_boundary']);
			}
			$this->acquire(
				$locks,
				DbLockKeys::specialEntitlementPeriod($absence->getUserId(), (int)$type->getId(), $resolution['periodStart']->format('Y-m-d')),
				'Special entitlement period'
			);
			$this->acquire($locks, DbLockKeys::specialEntitlementAbsence((int)$absence->getId()), 'Special entitlement absence');
			$reservation = $this->reservations->findByIdForUpdate((int)$reservation->getId()) ?? $reservation;
			if ($reservation->getStatus() !== SpecialEntitlementReservation::STATUS_ACTIVE) {
				return;
			}

			$requestData = $data;
			if ($type->getUnit() === SpecialEntitlementType::UNIT_DAYS && !array_key_exists('day_fraction', $requestData)) {
				$oldDays = isset($oldSummary['days']) ? (float)$oldSummary['days'] : 0.0;
				$oldAmount = (float)$reservation->getAmount();
				if ($oldDays > 0.0) {
					$ratio = $oldAmount / $oldDays;
					foreach ([0.25, 0.5, 0.75] as $fraction) {
						if (abs($ratio - $fraction) < 0.011) {
							$requestData['day_fraction'] = (string)$fraction;
							break;
						}
					}
				}
			}
			if ($type->getUnit() === SpecialEntitlementType::UNIT_HOURS
				&& !array_key_exists('duration_hours', $requestData)
				&& !array_key_exists('entitlement_minutes', $requestData)
				&& $absence->getDurationHours() !== null) {
				$requestData['duration_hours'] = (string)$absence->getDurationHours();
			}
			$context = ['type' => $type, 'data' => $requestData, 'resolution' => $resolution, 'userId' => $absence->getUserId()];
			$newAmount = $this->requestAmount($context, $absence);
			if ($type->getUnit() === SpecialEntitlementType::UNIT_HOURS) {
				$absence->setDurationHours($newAmount);
			}
			$period = $this->ensurePeriod($type, $absence->getUserId(), $resolution, $actorUid);
			if ($period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
				throw new SpecialEntitlementException('PERIOD_CLOSED', 'This entitlement period is closed.', 409, ['period' => 'closed']);
			}
			$reserved = $this->reservations->activeAmountForPeriod((int)$period->getId());
			if ((int)$reservation->getPeriodId() === (int)$period->getId()) {
				$reserved -= (float)$reservation->getAmount();
			}
			$available = $resolution['computedAmount'] + $this->ledger->balance((int)$period->getId()) - $reserved;
			if (!$type->getAllowNegative() && $newAmount > $available + self::EPSILON) {
				throw new SpecialEntitlementException('ENTITLEMENT_EXCEEDED', 'The request exceeds the available entitlement.', 409, ['amount' => 'entitlement_exceeded']);
			}
			if (!$this->reservations->updateAmountAndPeriodIfRevision($reservation, (int)$period->getId(), $newAmount)) {
				throw new SpecialEntitlementException('REVISION_CONFLICT', 'The reservation changed concurrently.', 409);
			}
			$this->audit->logAction(
				$absence->getUserId(),
				'special_entitlement_reservation_repriced',
				'special_entitlement_reservation',
				(int)$reservation->getId(),
				['amount' => (float)$reservation->getAmount(), 'period_id' => (int)$reservation->getPeriodId()],
				['amount' => $newAmount, 'period_id' => (int)$period->getId()],
				$actorUid,
			);
		} finally {
			$this->releaseLockList(array_reverse($locks));
		}
	}

	/** Must be called inside the absence mutation transaction. */
	public function consumeForAbsence(Absence $absence, string $actorUid): void
	{
		$lock = DbLockKeys::specialEntitlementAbsence((int)$absence->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement absence');
		try {
			$reservation = $this->reservations->findByAbsenceId((int)$absence->getId());
			if ($reservation === null) {
				return;
			}
			if ($reservation->getStatus() === SpecialEntitlementReservation::STATUS_RELEASED) {
				throw new SpecialEntitlementException('REVISION_CONFLICT', 'The reservation was already released.', 409);
			}
			if ($reservation->getStatus() !== SpecialEntitlementReservation::STATUS_CONSUMED) {
				$this->insertLedgerOnce(
					(int)$reservation->getPeriodId(),
					'absence:' . (int)$absence->getId() . ':approve',
					SpecialEntitlementLedgerEntry::KIND_DEBIT,
					(float)$reservation->getAmount(),
					(int)$absence->getId(),
					null,
					$actorUid,
					null,
				);
				if (!$this->reservations->transitionIfRevision((int)$reservation->getId(), (int)$reservation->getRevision(), SpecialEntitlementReservation::STATUS_CONSUMED)) {
					throw new SpecialEntitlementException('REVISION_CONFLICT', 'The reservation changed concurrently.', 409);
				}
				$this->audit->logAction(
					$absence->getUserId(),
					'special_entitlement_consumed',
					'special_entitlement_reservation',
					(int)$reservation->getId(),
					null,
					['absence_id' => (int)$absence->getId(), 'amount' => (float)$reservation->getAmount()],
					$actorUid,
				);
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Called when an already-approved absence is shortened before a boundary. */
	public function adjustConsumedAbsence(Absence $absence, array $data, array $oldSummary, string $actorUid): void
	{
		$reservation = $this->reservations->findByAbsenceId((int)$absence->getId());
		if ($reservation === null || $reservation->getStatus() !== SpecialEntitlementReservation::STATUS_CONSUMED) {
			return;
		}
		$lock = DbLockKeys::specialEntitlementAbsence((int)$absence->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement absence');
		try {
			$this->adjustConsumedAbsenceLocked($absence, $data, $oldSummary, $actorUid, $reservation);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function adjustConsumedAbsenceLocked(Absence $absence, array $data, array $oldSummary, string $actorUid, SpecialEntitlementReservation $reservation): void
	{
		$type = $this->types->findByAbsenceTypeCode((string)$absence->getType());
		$debit = $this->ledger->findByEventKey('absence:' . (int)$absence->getId() . ':approve');
		if ($type === null || $debit === null) {
			return;
		}
		$resolution = $this->resolver->resolve($type, $absence->getUserId(), $absence->getStartDate());
		if ($absence->getEndDate() > $resolution['periodEnd']) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The request crosses an entitlement period boundary.', 400, ['end_date' => 'period_boundary']);
		}
		if ($type->getUnit() === SpecialEntitlementType::UNIT_DAYS && !array_key_exists('day_fraction', $data)) {
			$oldDays = isset($oldSummary['days']) ? (float)$oldSummary['days'] : 0.0;
			$oldReservationAmount = (float)$reservation->getAmount();
			if ($oldDays > 0.0) {
				foreach ([0.25, 0.5, 0.75] as $fraction) {
					if (abs(($oldReservationAmount / $oldDays) - $fraction) < 0.011) {
						$data['day_fraction'] = (string)$fraction;
						break;
					}
				}
			}
		}
		if ($type->getUnit() === SpecialEntitlementType::UNIT_HOURS
			&& !array_key_exists('duration_hours', $data)
			&& !array_key_exists('entitlement_minutes', $data)
			&& $absence->getDurationHours() !== null) {
			$data['duration_hours'] = (string)$absence->getDurationHours();
		}
		$context = ['type' => $type, 'data' => $data, 'resolution' => $resolution, 'userId' => $absence->getUserId()];
		$newAmount = $this->requestAmount($context, $absence);
		$oldAmount = (float)$debit->getAmount();
		if ($newAmount > $oldAmount + self::EPSILON) {
			throw new SpecialEntitlementException('ENTITLEMENT_EXCEEDED', 'The changed request exceeds the original approved amount.', 409, ['amount' => 'entitlement_exceeded']);
		}
		$delta = round($oldAmount - $newAmount, 2, PHP_ROUND_HALF_UP);
		if ($delta <= self::EPSILON) {
			return;
		}
		$this->insertLedgerOnce(
			(int)$debit->getPeriodId(),
			'absence:' . (int)$absence->getId() . ':adjust:' . str_replace('.', '_', number_format($newAmount, 2, '.', '')),
			SpecialEntitlementLedgerEntry::KIND_CORRECTION,
			$delta,
			(int)$absence->getId(),
			(int)$debit->getId(),
			$actorUid,
			'Compensating adjustment after the approved absence was shortened.',
		);
		$this->audit->logAction(
			$absence->getUserId(),
			'special_entitlement_adjusted',
			'special_entitlement_reservation',
			(int)$reservation->getId(),
			['amount' => $oldAmount],
			['amount' => $newAmount],
			$actorUid,
		);
	}

	/** Must be called inside the absence mutation transaction. */
	public function releaseForAbsence(Absence $absence, string $actorUid): void
	{
		$lock = DbLockKeys::specialEntitlementAbsence((int)$absence->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement absence');
		try {
			$reservation = $this->reservations->findByAbsenceId((int)$absence->getId());
			if ($reservation === null || $reservation->getStatus() === SpecialEntitlementReservation::STATUS_RELEASED) {
				return;
			}
			if ($reservation->getStatus() === SpecialEntitlementReservation::STATUS_CONSUMED) {
				$debit = $this->ledger->findByEventKey('absence:' . (int)$absence->getId() . ':approve');
				if ($debit !== null) {
					$this->insertLedgerOnce(
						(int)$reservation->getPeriodId(),
						'absence:' . (int)$absence->getId() . ':release',
						SpecialEntitlementLedgerEntry::KIND_CORRECTION,
						(float)$debit->getAmount(),
						(int)$absence->getId(),
						(int)$debit->getId(),
						$actorUid,
						'Compensating release of an approved reservation.',
					);
				}
			}
			if (!$this->reservations->transitionIfRevision((int)$reservation->getId(), (int)$reservation->getRevision(), SpecialEntitlementReservation::STATUS_RELEASED)) {
				throw new SpecialEntitlementException('REVISION_CONFLICT', 'The reservation changed concurrently.', 409);
			}
			$this->audit->logAction(
				$absence->getUserId(),
				'special_entitlement_released',
				'special_entitlement_reservation',
				(int)$reservation->getId(),
				null,
				['absence_id' => (int)$absence->getId()],
				$actorUid,
			);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return list<array<string,mixed>> */
	public function listAccounts(string $userId, ?\DateTimeInterface $asOf = null): array
	{
		$asOfDate = $asOf ?? new \DateTimeImmutable('today');
		$items = [];
		foreach ($this->types->findAll() as $type) {
			try {
				$periodBounds = $this->resolver->periodFor($type, $userId, $asOfDate);
				$period = $this->periods->findForPeriod(
					(int)$type->getId(),
					$userId,
					$periodBounds['periodStart']->format('Y-m-d'),
				);
				if (!$type->getActive() && $period === null) {
					continue;
				}
				$items[] = $this->accountFor($type, $userId, $period, $asOfDate);
			} catch (SpecialEntitlementException) {
				// A type with incomplete employment data must not make the whole
				// account endpoint fail; its requests remain fail-closed.
				continue;
			}
		}
		return $items;
	}

	/**
	 * Employee-facing ledger history for one entitlement type. Public fields
	 * only — confidential labels and resolution traces never leave this
	 * serializer.
	 *
	 * @return array<string,mixed>|null null when the type is unknown
	 */
	public function historyForUser(string $userId, string $typeCode, int $limit = 50, int $offset = 0): ?array
	{
		$type = $this->types->findByCode($typeCode);
		if ($type === null) {
			return null;
		}
		$items = [];
		foreach ($this->periods->findForUserAndType($userId, (int)$type->getId()) as $period) {
			$entries = array_map(
				static fn (SpecialEntitlementLedgerEntry $e): array => [
					'id' => (int)$e->getId(),
					'kind' => (string)$e->getKind(),
					'amount' => number_format((float)$e->getAmount(), 2, '.', ''),
					'createdAt' => $e->getCreatedAt()?->format('c'),
				],
				$this->ledger->findForPeriodPage((int)$period->getId(), $limit, $offset),
			);
			$items[] = [
				'periodStart' => $period->getPeriodStart()?->format('Y-m-d'),
				'periodEnd' => $period->getPeriodEnd()?->format('Y-m-d'),
				'status' => (string)$period->getStatus(),
				'computedAmount' => number_format((float)$period->getComputedAmount(), 2, '.', ''),
				'revision' => (int)$period->getRevision(),
				'entries' => $entries,
			];
		}
		return [
			'typeCode' => (string)$type->getCode(),
			'label' => (string)$type->getPublicLabel(),
			'unit' => (string)$type->getUnit(),
			'periods' => $items,
		];
	}

	/** @return array<string,mixed> */
	private function accountFor(SpecialEntitlementType $type, string $userId, ?SpecialEntitlementPeriod $period, \DateTimeInterface $asOf): array
	{
		if ($period !== null && $period->getStatus() === SpecialEntitlementPeriod::STATUS_CLOSED) {
			$computed = (float)$period->getComputedAmount();
			$enabled = $computed > 0;
			$start = $period->getPeriodStart();
			$end = $period->getPeriodEnd();
			$revision = (int)$period->getRevision();
		} else {
			$resolution = $this->resolver->resolve($type, $userId, $asOf);
			$computed = $resolution['computedAmount'];
			$enabled = $resolution['enabled'];
			$start = $resolution['periodStart'];
			$end = $resolution['periodEnd'];
			$revision = $period !== null ? (int)$period->getRevision() : 0;
		}
		$reserved = $period !== null ? $this->reservations->activeAmountForPeriod((int)$period->getId()) : 0.0;
		$balance = $period !== null ? $this->ledger->balanceForPeriod((int)$period->getId()) : 0.0;
		$used = $period !== null ? $this->ledger->usedAmountForPeriod((int)$period->getId()) : 0.0;
		$available = $computed + $balance - $reserved;
		return [
			'typeId' => (int)$type->getId(),
			'typeCode' => (string)$type->getCode(),
			'absenceTypeCode' => (string)$type->getAbsenceTypeCode(),
			'label' => (string)$type->getPublicLabel(),
			'unit' => (string)$type->getUnit(),
			'periodStart' => $start?->format('Y-m-d'),
			'periodEnd' => $end?->format('Y-m-d'),
			'total' => number_format($computed, 2, '.', ''),
			'reserved' => number_format($reserved, 2, '.', ''),
			'used' => number_format($used, 2, '.', ''),
			'available' => number_format($available, 2, '.', ''),
			'enabled' => $enabled,
			'active' => (bool)$type->getActive(),
			'revision' => $revision,
		];
	}

	private function ensurePeriod(SpecialEntitlementType $type, string $userId, array $resolution, string $actorUid): SpecialEntitlementPeriod
	{
		$period = $this->periods->findForPeriod(
			(int)$type->getId(),
			$userId,
			$resolution['periodStart']->format('Y-m-d'),
		);
		if ($period !== null) {
			return $period;
		}
		$period = new SpecialEntitlementPeriod();
		$period->setEntitlementTypeId((int)$type->getId());
		$period->setUserId($userId);
		$period->setPeriodStart(new \DateTime($resolution['periodStart']->format('Y-m-d')));
		$period->setPeriodEnd(new \DateTime($resolution['periodEnd']->format('Y-m-d')));
		$period->setComputedAmount($resolution['computedAmount']);
		$period->setUnit((string)$type->getUnit());
		$period->setStatus(SpecialEntitlementPeriod::STATUS_OPEN);
		$period->setRevision(1);
		try {
			$period = $this->periods->insert($period);
		} catch (UniqueConstraintViolationException) {
			$period = $this->periods->findForPeriod(
				(int)$type->getId(),
				$userId,
				$resolution['periodStart']->format('Y-m-d'),
			);
			if ($period === null) {
				throw new SpecialEntitlementException('REVISION_CONFLICT', 'The entitlement period could not be resolved.', 409);
			}
			return $period;
		}
		$snapshot = $this->snapshots->storeSpecial(
			$userId,
			(int)$type->getId(),
			$resolution['periodStart'],
			$resolution['periodEnd'],
			(string)$type->getUnit(),
			$resolution['asOfDate'],
			$resolution['computedAmount'],
			SpecialEntitlementResolver::ALGORITHM_VERSION,
			$resolution['trace'],
			$actorUid,
		);
		$period->setComputationSnapshotId((int)$snapshot->getId());
		if (!$this->periods->updateIfRevision($period)) {
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'The entitlement period changed concurrently.', 409);
		}
		return $period;
	}

	private function insertLedgerOnce(
		int $periodId,
		string $eventKey,
		string $kind,
		float $amount,
		?int $absenceId,
		?int $reversesLedgerId,
		string $actorUid,
		?string $reason,
	): ?SpecialEntitlementLedgerEntry {
		if ($kind === SpecialEntitlementLedgerEntry::KIND_DEBIT && $amount <= 0.0) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A debit amount must be positive.', 400);
		}
		if (in_array($kind, [SpecialEntitlementLedgerEntry::KIND_CREDIT, SpecialEntitlementLedgerEntry::KIND_CORRECTION], true) && $amount <= 0.0) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'A compensating amount must be positive.', 400);
		}
		$entry = new SpecialEntitlementLedgerEntry();
		$entry->setPeriodId($periodId);
		$entry->setEventKey($eventKey);
		$entry->setKind($kind);
		$entry->setAmount(round($amount, 2, PHP_ROUND_HALF_UP));
		$entry->setAbsenceId($absenceId);
		$entry->setReversesLedgerId($reversesLedgerId);
		$entry->setActorUid(substr($actorUid, 0, 64));
		$entry->setReason($reason !== null ? substr($reason, 0, 500) : null);
		$entry->setCreatedAt(new \DateTime());
		try {
			return $this->ledger->insert($entry);
		} catch (UniqueConstraintViolationException) {
			return $this->ledger->findByEventKey($eventKey);
		}
	}

	private function requestKey(array $data, string $userId): string
	{
		foreach (['request_key', 'requestKey', 'entitlement_request_key'] as $key) {
			$raw = $this->scalar($data[$key] ?? null);
			if ($raw !== null && $raw !== '') {
				try {
					$parsed = IdempotencyKey::parse($raw)->value;
				} catch (\InvalidArgumentException) {
					throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid request key.', 400, [$key => 'invalid']);
				}
				// Scope client keys to the user and bound them to the 128-char
				// column: a shared weak key between users must not collide.
				return 'req:' . hash('sha256', $userId . "\n" . $parsed);
			}
		}
		return 'request:' . hash('sha256', MonthClosureCanonical::encode([
			'user' => $userId,
			'type' => (string)($data['type'] ?? ''),
			'start' => (string)($data['start_date'] ?? ''),
			'end' => (string)($data['end_date'] ?? ''),
			'fraction' => (string)($data['day_fraction'] ?? '1'),
			'minutes' => (string)($data['entitlement_minutes'] ?? ''),
			'hours' => (string)($data['duration_hours'] ?? ''),
		]));
	}

	private function dayFraction(mixed $raw): float
	{
		$value = $this->scalar($raw);
		if ($value === null || $value === '') {
			return 1.0;
		}
		$value = str_replace(',', '.', $value);
		if (!in_array($value, ['0.25', '0.5', '0.75', '1', '1.0'], true)) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid day fraction.', 400, ['day_fraction' => 'invalid']);
		}
		return (float)$value;
	}

	private function explicitMinutes(mixed $raw): ?int
	{
		$value = $this->scalar($raw);
		if ($value === null || $value === '') {
			return null;
		}
		if (preg_match('/^\d{1,6}$/D', $value) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid entitlement minutes.', 400, ['entitlement_minutes' => 'invalid']);
		}
		return (int)$value;
	}

	private function hoursToMinutes(mixed $raw): ?int
	{
		$value = $this->scalar($raw);
		if ($value === null || $value === '') {
			return null;
		}
		$value = str_replace(',', '.', $value);
		if (preg_match('/^\d{1,4}(\.\d{1,4})?$/D', $value) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid duration hours.', 400, ['duration_hours' => 'invalid']);
		}
		$minutes = (float)$value * 60.0;
		if (abs($minutes - round($minutes)) > self::EPSILON) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Hours must resolve to whole minutes.', 400, ['duration_hours' => 'whole_minutes']);
		}
		return (int)round($minutes);
	}

	private function parseDate(mixed $raw, string $field): \DateTimeImmutable
	{
		$value = $this->scalar($raw);
		if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid date.', 400, [$field => 'invalid']);
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid date.', 400, [$field => 'invalid']);
		}
		return $date;
	}

	private function scalar(mixed $raw): ?string
	{
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if ($raw === null || is_bool($raw) || is_object($raw)) {
			return null;
		}
		return trim((string)$raw);
	}

	/** @param list<string> $locks */
	private function acquire(array &$locks, string $key, string $description): void
	{
		try {
			$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, $description);
			$locks[] = $key;
		} catch (\Throwable $e) {
			$this->releaseLockList(array_reverse($locks));
			throw new SpecialEntitlementException('REVISION_CONFLICT', 'The entitlement is being changed concurrently.', 409);
		}
	}

	/** @param list<string> $locks */
	private function releaseLockList(array $locks): void
	{
		foreach ($locks as $key) {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
