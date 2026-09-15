<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCP\IUserManager;

/**
 * Admin-only hour-ledger adjustments for overtime Saldo (Nullung / manual payout).
 * Never mutates time entries or opening-balance rows.
 */
class OvertimeAdjustmentService
{
	public const MIN_ABS_HOURS = 0.01;
	public const MAX_ABS_HOURS = 9999.0;

	public function __construct(
		private readonly OvertimeAdjustmentMapper $adjustmentMapper,
		private readonly OvertimeBankService $bankService,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Current display balance (bank-aware + adjustments), same as traffic-light YTD.
	 */
	public function getCurrentEffectiveBalance(string $userId, ?\DateTimeInterface $asOf = null): float
	{
		$status = $this->bankService->getBankStatus($userId, $asOf);

		return (float)($status['effective_balance'] ?? 0.0);
	}

	/**
	 * Record a signed hours_delta. Positive credits Saldo; negative debits.
	 *
	 * @return array{adjustment: array<string, mixed>, balance_before: float, balance_after: float}
	 */
	public function createAdjustment(
		string $userId,
		float $hoursDelta,
		string $reasonCode,
		?string $note,
		string $actorUserId,
		?\DateTimeInterface $effectiveOn = null,
	): array {
		if ($this->userManager->get($userId) === null) {
			throw new \InvalidArgumentException('User not found');
		}

		$hoursDelta = round($hoursDelta, 2);
		$abs = abs($hoursDelta);
		if ($abs < self::MIN_ABS_HOURS || $abs > self::MAX_ABS_HOURS || !is_finite($hoursDelta)) {
			throw new \InvalidArgumentException('Hours delta out of range');
		}

		if (!in_array($reasonCode, OvertimeAdjustment::REASON_CODES, true)) {
			throw new \InvalidArgumentException('Invalid reason code');
		}

		$note = $note !== null ? trim($note) : '';
		if (mb_strlen($note) > 500) {
			throw new \InvalidArgumentException('Note too long');
		}
		if ($note === '') {
			$note = null;
		}

		$effective = $effectiveOn !== null
			? \DateTime::createFromInterface($effectiveOn)
			: new \DateTime('today');
		$effective->setTime(0, 0, 0);
		$year = (int)$effective->format('Y');

		$balanceBefore = $this->getCurrentEffectiveBalance($userId, $effective);
		$balanceAfter = round($balanceBefore + $hoursDelta, 2);

		$entity = new OvertimeAdjustment();
		$entity->setUserId($userId);
		$entity->setCalendarYear($year);
		$entity->setEffectiveOn($effective);
		$entity->setHoursDelta($hoursDelta);
		$entity->setReasonCode($reasonCode);
		$entity->setNote($note);
		$entity->setBalanceBefore($balanceBefore);
		$entity->setBalanceAfter($balanceAfter);
		$entity->setProcessedBy($actorUserId);
		$entity->setCreatedAt(new \DateTime());

		$saved = $this->adjustmentMapper->insertAdjustment($entity);
		$summary = $this->toArray($saved);

		$this->auditLogMapper->logAction(
			$userId,
			'overtime_balance_adjustment',
			'overtime_adjustment',
			$saved->getId(),
			['balance' => $balanceBefore],
			$summary,
			$actorUserId
		);

		return [
			'adjustment' => $summary,
			'balance_before' => $balanceBefore,
			'balance_after' => $balanceAfter,
		];
	}

	/**
	 * Zero the current effective Saldo with one compensating ledger row.
	 * Plus → debit; Minus → credit. No-op when already ≈ 0.
	 *
	 * @return array{action: string, adjustment?: array<string, mixed>, balance_before: float, balance_after: float}
	 */
	public function resetBalanceToZero(
		string $userId,
		string $actorUserId,
		?string $note = null,
		?\DateTimeInterface $effectiveOn = null,
	): array {
		$before = $this->getCurrentEffectiveBalance($userId, $effectiveOn);
		if (abs($before) < self::MIN_ABS_HOURS) {
			return [
				'action' => 'skipped_zero',
				'balance_before' => $before,
				'balance_after' => $before,
			];
		}

		$reason = $before > 0
			? OvertimeAdjustment::REASON_PERIOD_NULLUNG
			: OvertimeAdjustment::REASON_UNDERTIME_WAIVER;
		$delta = round(-$before, 2);
		$result = $this->createAdjustment(
			$userId,
			$delta,
			$reason,
			$note ?? 'Balance reset to zero (Nullung)',
			$actorUserId,
			$effectiveOn
		);

		return [
			'action' => 'reset',
			'adjustment' => $result['adjustment'],
			'balance_before' => $result['balance_before'],
			'balance_after' => $result['balance_after'],
		];
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int, year: int, effective_balance: float}
	 */
	public function listForUser(string $userId, ?int $year = null, int $limit = 50, int $offset = 0): array
	{
		$year = $year ?? (int)(new \DateTime())->format('Y');
		$items = [];
		foreach ($this->adjustmentMapper->findByUserAndYear($userId, $year, $limit, $offset) as $row) {
			$items[] = $this->toArray($row);
		}

		return [
			'items' => $items,
			'total' => $this->adjustmentMapper->countByUserAndYear($userId, $year),
			'year' => $year,
			'effective_balance' => $this->getCurrentEffectiveBalance($userId),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(OvertimeAdjustment $entity): array
	{
		$effectiveOn = $entity->getEffectiveOn();
		$createdAt = $entity->getCreatedAt();

		return [
			'id' => $entity->getId(),
			'user_id' => $entity->getUserId(),
			'calendar_year' => $entity->getCalendarYear(),
			'effective_on' => $effectiveOn instanceof \DateTimeInterface ? $effectiveOn->format('Y-m-d') : null,
			'hours_delta' => round((float)$entity->getHoursDelta(), 2),
			'reason_code' => $entity->getReasonCode(),
			'note' => $entity->getNote(),
			'balance_before' => round((float)$entity->getBalanceBefore(), 2),
			'balance_after' => round((float)$entity->getBalanceAfter(), 2),
			'processed_by' => $entity->getProcessedBy(),
			'created_at' => $createdAt instanceof \DateTimeInterface ? $createdAt->format('c') : null,
		];
	}
}
