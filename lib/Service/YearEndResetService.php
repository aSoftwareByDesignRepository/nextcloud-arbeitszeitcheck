<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2026 Software by Design GmbH
 *
 * Year-end overtime reset orchestrator (manual + annual).
 *
 * Spec: optional-absence-corrections-hourly-leave-year-end-2026-11 §9.5/§15.
 *
 * Invariants enforced here:
 * - A preview is a single-use, actor-bound, 15-minute CSPRNG handle. Only its
 *   SHA-256 hash is stored; the raw handle leaves the server exactly once.
 * - Items are unique per (user, closing_year). A target/year identity is reused
 *   across manual and annual runs; `last_execution_run_id` points at the run
 *   that most recently attempted it.
 * - Every item mutation goes through a lease CAS (`lease_token`/`lease_until`)
 *   so a crashed worker can never double-execute: reclaim requires the lease to
 *   be expired, finish requires holding the current token.
 * - The financial unit of work per item is one transaction: adjustment insert +
 *   audit (inside OvertimeAdjustmentService::atomic, savepoint-nested) + balance
 *   revision bump + item snapshot. Any failure rolls the whole item back.
 * - The item's stored source_revision is the token the reset itself produces,
 *   so the reset never flags its own write as needs_review.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustment;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\YearResetItem;
use OCA\ArbeitszeitCheck\Db\YearResetItemMapper;
use OCA\ArbeitszeitCheck\Db\YearResetRef;
use OCA\ArbeitszeitCheck\Db\YearResetRefMapper;
use OCA\ArbeitszeitCheck\Db\YearResetRun;
use OCA\ArbeitszeitCheck\Db\YearResetRunMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

class YearEndResetService
{
	use TTransactional;

	public const ERROR_FEATURE_DISABLED = 'FEATURE_DISABLED';
	public const ERROR_POLICY_INVALID = 'POLICY_INVALID';
	public const ERROR_REVISION_CONFLICT = 'REVISION_CONFLICT';
	public const ERROR_POLICY_CHANGED = 'POLICY_CHANGED';
	public const ERROR_REASON_REQUIRED = 'REASON_REQUIRED';
	public const ERROR_PREVIEW_EXPIRED = 'PREVIEW_EXPIRED';
	public const ERROR_OPERATION_CONFLICT = 'OPERATION_CONFLICT';
	public const ERROR_SOURCE_CHANGED = 'SOURCE_CHANGED';
	public const ERROR_NOT_FOUND = 'NOT_FOUND';
	public const ERROR_INTERNAL = 'INTERNAL';
	public const ERROR_PERIOD_FINALIZED = 'PERIOD_FINALIZED';
	public const ERROR_CUTOFF_UNRESOLVED = 'CUTOFF_UNRESOLVED';
	public const ERROR_NEXT_OPENING_NONZERO = 'NEXT_OPENING_NONZERO';

	public const OUTCOME_SUCCESS = 'success';
	public const OUTCOME_NOOP = 'no_op';
	public const OUTCOME_SKIPPED = 'skipped';
	public const OUTCOME_BLOCKED = 'blocked';
	public const OUTCOME_FAILED = 'failed';
	public const OUTCOME_PENDING = 'pending';

	public const PREVIEW_TTL_SECONDS = 900;
	public const LEASE_SECONDS = 60;
	public const TICK_BUDGET_SECONDS = 20;
	public const MAX_SELECTION = 200;
	public const REASON_MIN = 10;
	public const REASON_MAX = 500;
	public const CLOSING_YEAR_MIN = 2000;
	public const CLOSING_YEAR_MAX = 2100;
	private const CENTI_EPSILON = 0.5;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly YearResetRunMapper $runMapper,
		private readonly YearResetItemMapper $itemMapper,
		private readonly YearResetRefMapper $refMapper,
		private readonly OvertimeAdjustmentService $adjustmentService,
		private readonly OvertimeAdjustmentMapper $adjustmentMapper,
		private readonly WorkforcePolicyService $policyService,
		private readonly FinancialRevisionService $revisionService,
		private readonly AbsenceMapper $absenceMapper,
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly MonthClosureService $monthClosureService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly AuditLogMapper $auditLogMapper,
	) {
	}

	// ---------------------------------------------------------------- preview

	/**
	 * Create an actor-bound, single-use preview handle for a manual reset run.
	 *
	 * @param list<string> $userIds
	 * @return array{preview_id: string, run_id: int, closing_year: int,
	 *               expires_at: string, targets: list<array<string, mixed>>,
	 *               policy_revision: int}
	 */
	public function createPreview(
		string $actor,
		int $closingYear,
		array $userIds,
		bool $resetPositive,
		bool $waiveNegative,
		string $reason,
		int $expectedPolicyRevision,
	): array {
		$this->assertManualEnabled();
		$this->assertPolicyRevision($expectedPolicyRevision, self::ERROR_REVISION_CONFLICT);
		$this->assertClosingYear($closingYear);
		$reason = $this->assertReason($reason);
		$userIds = $this->assertSelection($userIds);

		$policy = $this->policyService->get();
		if (($resetPositive && !$policy->yearEndResetPositive)
			|| ($waiveNegative && !$policy->yearEndWaiveNegative)) {
			throw $this->error(self::ERROR_POLICY_INVALID, 'Requested scope exceeds the enabled year-end policy');
		}
		$now = new \DateTime('now');
		$expires = (clone $now)->modify('+' . self::PREVIEW_TTL_SECONDS . ' seconds');

		$handle = bin2hex(random_bytes(32));
		$targets = [];
		foreach ($userIds as $uid) {
			$targets[] = $this->previewTarget(
				$uid, $closingYear, $resetPositive, $waiveNegative,
			);
		}

		// The snapshot must bind the exact scope the preview showed the
		// operator — processItem re-derives actions from these flags, so
		// storing the raw policy (not the request scope) would let execute
		// silently diverge from the predicted outcome.
		$policySnapshot = $policy->toArray();
		$policySnapshot['yearEnd']['resetPositive'] = $resetPositive;
		$policySnapshot['yearEnd']['waiveNegative'] = $waiveNegative;

		$run = new YearResetRun();
		$run->setOperationKey($this->newOperationKey());
		$run->setClosingYear($closingYear);
		$run->setOrigin(YearResetRun::ORIGIN_MANUAL);
		$run->setPolicyRevision($this->policyService->revision());
		$run->setPolicySnapshot(json_encode($policySnapshot, JSON_THROW_ON_ERROR));
		$run->setSelectionSnapshot(json_encode($userIds, JSON_THROW_ON_ERROR));
		$run->setActorUid($actor);
		$run->setReason($reason);
		$run->setState(YearResetRun::STATE_PREVIEWED);
		$run->setRevision(1);
		$run->setPreviewKeyHash(hash('sha256', $handle));
		$run->setPreviewExpiresAt($expires);
		$run->setCreatedAt($now);
		$run->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$run = $this->runMapper->insert($run);

			foreach ($targets as $t) {
				$ref = new YearResetRef();
				$ref->setRunId($run->getId());
				$ref->setUserId($t['user_id']);
				$ref->setPreviewSourceRevision($t['source_revision']);
				$ref->setOutcome(self::OUTCOME_PENDING);
				$ref->setPreviewResult(json_encode($t, JSON_THROW_ON_ERROR));
				$ref->setItemId(null);
				$ref->setCreatedAt($now);
				$ref->setUpdatedAt($now);
				$this->refMapper->insert($ref);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $this->error(self::ERROR_OPERATION_CONFLICT, 'A year-end run already holds this operation key');
			}
			throw $e;
		}

		return [
			'preview_id' => $handle,
			'run_id' => $run->getId(),
			'closing_year' => $closingYear,
			'expires_at' => $expires->format('c'),
			'targets' => $targets,
			'policy_revision' => $this->policyService->revision(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function previewTarget(
		string $userId,
		int $closingYear,
		bool $resetPositive,
		bool $waiveNegative,
	): array {
		$sourceRevision = $this->revisionService->sourceRevision($userId, $closingYear);
		// Anchor at the closing boundary: the reset zeroes the balance carried
		// into closingYear+1, not whatever the Saldo happens to be today.
		// processItem anchors its compensating adjustment at the same instant,
		// so preview delta and executed delta can only match this way.
		$boundary = new \DateTime(sprintf('%04d-01-01', $closingYear + 1));
		$before = $this->adjustmentService->getCurrentEffectiveBalance($userId, $boundary);
		$beforeCents = (int)round($before * 100);

		$blocked = $this->blockedReason($userId, $closingYear);
		$deltaCents = 0;
		$action = 'none';
		if ($blocked === null) {
			if ($beforeCents > 0 && $resetPositive) {
				$deltaCents = -$beforeCents;
				$action = 'reset_positive';
			} elseif ($beforeCents < 0 && $waiveNegative) {
				$deltaCents = -$beforeCents;
				$action = 'waive_negative';
			}
		}

		return [
			'user_id' => $userId,
			'before_centi_hours' => $beforeCents,
			'delta_centi_hours' => $deltaCents,
			'action' => $action,
			'blocked' => $blocked,
			'source_revision' => $sourceRevision,
		];
	}

	/**
	 * Hard blockers for a reset at the closing-year boundary (§AC-19).
	 */
	private function blockedReason(string $userId, int $closingYear): ?string
	{
		// An annual opening already booked into closingYear+1 would be falsified.
		foreach ($this->adjustmentMapper->findByUserAndYear($userId, $closingYear + 1, 1) as $adj) {
			if (abs((float)$adj->getBalanceAfter()) > self::CENTI_EPSILON / 100) {
				return self::ERROR_NEXT_OPENING_NONZERO;
			}
		}
		if ($this->monthClosureService->isMonthFinalized($userId, $closingYear, 12)) {
			return self::ERROR_PERIOD_FINALIZED;
		}
		$open = $this->timeEntryMapper->findOpenEntryByUser($userId);
		if ($open !== null) {
			$cutoff = new \DateTime(sprintf('%04d-01-01 00:00:00', $closingYear + 1));
			$start = $open->getStartTime();
			if ($start !== null && $start < $cutoff) {
				return self::ERROR_CUTOFF_UNRESOLVED;
			}
		}
		foreach ($this->absenceMapper->findCancelRequestedForUsers([$userId]) as $absence) {
			if ($this->absenceIntersectsBoundary($absence, $closingYear)) {
				return self::ERROR_CUTOFF_UNRESOLVED;
			}
		}
		return null;
	}

	private function absenceIntersectsBoundary(Absence $absence, int $closingYear): bool
	{
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return false;
		}
		$lo = new \DateTime(sprintf('%04d-12-25', $closingYear));
		$hi = new \DateTime(sprintf('%04d-01-07', $closingYear + 1));
		return $start <= $hi && $end >= $lo;
	}

	// ---------------------------------------------------------------- execute

	/**
	 * Convert a live preview into an executing run, then process items within
	 * the request's tick budget. Replays (same preview handle or operation key)
	 * return the existing run instead of duplicating work.
	 *
	 * @return array<string, mixed> run status payload
	 */
	public function execute(
		string $actor,
		string $previewHandle,
		string $operationId,
		int $expectedPolicyRevision,
	): array {
		$this->assertManualEnabled();
		if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', $operationId)) {
			throw $this->error(self::ERROR_POLICY_INVALID, 'operationId must be a UUID');
		}
		// Durable replay receipt: the operation key outlives the single-use
		// preview handle (consumed below), so a retried execute MUST consult
		// it first — otherwise every client retry hits a dead-handle 410.
		// A foreign actor's key gets the same uniform denial as a dead handle.
		try {
			$receipt = $this->runMapper->findByOperationKey(strtolower($operationId));
			if ($receipt->getActorUid() !== $actor) {
				throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
			}
			return $this->runStatus($receipt->getId());
		} catch (DoesNotExistException) {
			// fresh operation — continue with the preview handle
		}
		$run = $this->lookupPreviewRun($previewHandle);
		if ($run->getActorUid() !== $actor) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
		}
		$now = new \DateTime('now');
		if ($run->getPreviewExpiresAt() === null || $run->getPreviewExpiresAt() <= $now) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview expired');
		}
		$this->assertPolicyRevision($expectedPolicyRevision, self::ERROR_POLICY_CHANGED);

		// Idempotent replay: the preview handle is single-use; a second execute
		// on the same run returns current state rather than re-running.
		if ($run->getState() !== YearResetRun::STATE_PREVIEWED) {
			return $this->runStatus($run->getId());
		}

		$refs = $this->refMapper->findByRun($run->getId());
		$closingYear = $run->getClosingYear();

		$this->db->beginTransaction();
		try {
			$run->setOperationKey(strtolower($operationId));
			$run->setState(YearResetRun::STATE_EXECUTING);
			$run->setPreviewKeyHash(null); // handle consumed — single use
			$run->setPreviewExpiresAt(null);
			$run->setUpdatedAt($now);
			$this->runMapper->update($run);

			foreach ($refs as $ref) {
				$this->bindItemToRun($run, $ref);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $this->error(self::ERROR_OPERATION_CONFLICT, 'Another year-end run already holds this operation key');
			}
			throw $e;
		}

		$this->auditRun($run, 'year_end_run_started', [
			'closing_year' => $closingYear,
			'targets' => count($refs),
		], $actor);

		$this->runTick($run->getId(), self::TICK_BUDGET_SECONDS);
		return $this->runStatus($run->getId());
	}

	private function bindItemToRun(YearResetRun $run, YearResetRef $ref): void
	{
		$item = $this->itemMapper->findByUserAndYear($ref->getUserId(), $run->getClosingYear());
		if ($item === null) {
			$item = new YearResetItem();
			$item->setUserId($ref->getUserId());
			$item->setClosingYear($run->getClosingYear());
			$item->setRevision(1);
			$item->setCreatedAt(new \DateTime('now'));
			$item->setReviewState(YearResetItem::REVIEW_CLEAR);
			$item->setSourceRevision($ref->getPreviewSourceRevision());
		}
		// An item already terminal-done for this year is never re-queued by a
		// manual run; the ref keeps its own outcome instead.
		if ($item->getId() !== null && $item->getState() === YearResetItem::STATE_DONE) {
			$this->refMapper->markOutcome($run->getId(), $ref->getUserId(), self::OUTCOME_SKIPPED);
			$this->refMapper->linkItem($run->getId(), $ref->getUserId(), $item->getId());
			return;
		}
		// Re-validate the preview-captured source token at bind time. Stale
		// sources are not executed — the operator must re-preview.
		$current = $this->revisionService->sourceRevision($ref->getUserId(), $run->getClosingYear());
		if ($current !== $ref->getPreviewSourceRevision()) {
			$item->setState(YearResetItem::STATE_FAILED);
			$item->setErrorCode(self::ERROR_SOURCE_CHANGED);
			$item->setLastExecutionRunId($run->getId());
			$item->setUpdatedAt(new \DateTime('now'));
			$this->saveItem($item);
			$this->refMapper->markOutcome($run->getId(), $ref->getUserId(), self::OUTCOME_BLOCKED);
			$this->refMapper->linkItem($run->getId(), $ref->getUserId(), $item->getId());
			return;
		}
		$item->setState(YearResetItem::STATE_PENDING);
		$item->setErrorCode(null);
		$item->setLastExecutionRunId($run->getId());
		$item->setLeaseToken(null);
		$item->setLeaseUntil(null);
		$item->setUpdatedAt(new \DateTime('now'));
		$this->saveItem($item);
		$this->refMapper->linkItem($run->getId(), $ref->getUserId(), $item->getId());
	}

	// ----------------------------------------------------------------- resume

	/**
	 * Resume a stalled/failed run from a fresh preview. Only non-terminal
	 * items whose users appear in the fresh preview are re-queued; their
	 * source_revision is rebound to the preview's token (explicit re-attribution).
	 *
	 * @return array<string, mixed>
	 */
	public function resume(
		string $actor,
		int $runId,
		string $previewHandle,
		string $operationId,
		int $expectedPolicyRevision,
	): array {
		$this->assertManualEnabled();
		// Same durable replay receipt as execute(): a retried resume hits the
		// consumed preview handle otherwise (the op key lives on the resumed
		// run). A key bound to a different run is a real conflict.
		if (preg_match('/^[0-9a-fA-F-]{32,36}$/', $operationId)) {
			try {
				$receipt = $this->runMapper->findByOperationKey(strtolower($operationId));
				if ($receipt->getActorUid() !== $actor) {
					throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
				}
				if ($receipt->getId() !== $runId) {
					throw $this->error(self::ERROR_OPERATION_CONFLICT, 'Another year-end run already holds this operation key');
				}
				return $this->runStatus($receipt->getId());
			} catch (DoesNotExistException) {
				// fresh operation — continue with the preview handle
			}
		}
		$run = $this->findRunOr404($runId);
		if (!in_array($run->getState(), [YearResetRun::STATE_FAILED, YearResetRun::STATE_COMPLETED, YearResetRun::STATE_EXECUTING], true)) {
			throw $this->error(self::ERROR_OPERATION_CONFLICT, 'Run is not resumable');
		}
		$previewRun = $this->lookupPreviewRun($previewHandle);
		if ($previewRun->getActorUid() !== $actor) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
		}
		if ($previewRun->getPreviewExpiresAt() === null || $previewRun->getPreviewExpiresAt() <= new \DateTime('now')) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview expired');
		}
		if ($previewRun->getState() !== YearResetRun::STATE_PREVIEWED) {
			throw $this->error(self::ERROR_OPERATION_CONFLICT, 'Preview already consumed');
		}
		if ($previewRun->getClosingYear() !== $run->getClosingYear()) {
			throw $this->error(self::ERROR_POLICY_INVALID, 'Preview closing year does not match run');
		}
		$this->assertPolicyRevision($expectedPolicyRevision, self::ERROR_POLICY_CHANGED);

		$refs = $this->refMapper->findByRun($previewRun->getId());
		$requeued = 0;
		$this->db->beginTransaction();
		try {
			$previewRun->setState(YearResetRun::STATE_FAILED); // consumed as resume authority
			$previewRun->setPreviewKeyHash(null);
			$previewRun->setPreviewExpiresAt(null);
			$previewRun->setUpdatedAt(new \DateTime('now'));
			$this->runMapper->update($previewRun);

			foreach ($refs as $ref) {
				$item = $this->itemMapper->findByUserAndYear($ref->getUserId(), $run->getClosingYear());
				if ($item === null || $item->getState() === YearResetItem::STATE_DONE) {
					continue;
				}
				$item->setState(YearResetItem::STATE_PENDING);
				$item->setErrorCode(null);
				$item->setSourceRevision($ref->getPreviewSourceRevision());
				$item->setLastExecutionRunId($run->getId());
				$item->setLeaseToken(null);
				$item->setLeaseUntil(null);
				$item->setUpdatedAt(new \DateTime('now'));
				$this->itemMapper->update($item);
				$this->refMapper->linkItem($run->getId(), $ref->getUserId(), $item->getId());
				$requeued++;
			}
			if ($requeued > 0) {
				$run->setState(YearResetRun::STATE_EXECUTING);
				$run->setOperationKey(strtolower($operationId));
				$run->setActorUid($actor);
				$run->setUpdatedAt(new \DateTime('now'));
				$this->runMapper->update($run);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $this->error(self::ERROR_OPERATION_CONFLICT, 'Another year-end run already holds this operation key');
			}
			throw $e;
		}

		if ($requeued > 0) {
			$this->auditRun($run, 'year_end_run_resumed', ['requeued' => $requeued], $actor);
			$this->runTick($run->getId(), self::TICK_BUDGET_SECONDS);
		}
		return $this->runStatus($run->getId());
	}

	// -------------------------------------------------------------------- tick

	/**
	 * Claim and process pending items until the budget is exhausted. Safe to
	 * call from request threads and the annual job; the lease CAS makes
	 * overlapping callers no-ops rather than double-executions.
	 */
	public function runTick(int $runId, int $budgetSeconds = self::TICK_BUDGET_SECONDS): void
	{
		$deadline = microtime(true) + max(1, $budgetSeconds);
		$run = $this->runMapper->findById($runId);
		if ($run->getState() !== YearResetRun::STATE_EXECUTING) {
			return;
		}

		while (microtime(true) < $deadline) {
			$token = bin2hex(random_bytes(16));
			$leaseUntil = new \DateTime('+' . self::LEASE_SECONDS . ' seconds');
			$item = $this->itemMapper->claimNext($runId, $token, $leaseUntil, new \DateTime('now'));
			if ($item === null) {
				break;
			}
			$this->processItem($run, $item, $token);
		}

		$this->finalizeRunIfTerminal($runId);
	}

	/**
	 * One item = one transaction. The adjustment, its audit row, the balance
	 * revision bump and the item snapshot commit together or not at all.
	 */
	private function processItem(YearResetRun $run, YearResetItem $item, string $leaseToken): void
	{
		$userId = $item->getUserId();
		$closingYear = $item->getClosingYear();

		// Re-validate the bound source at execution time — anything committed
		// between bind and claim makes the stale preview non-authoritative.
		$current = $this->revisionService->sourceRevision($userId, $closingYear);
		if ($current !== $item->getSourceRevision()) {
			$this->itemMapper->finishItem($item, $leaseToken, YearResetItem::STATE_FAILED, [
				'error_code' => self::ERROR_SOURCE_CHANGED,
			]);
			$this->refMapper->markOutcome($run->getId(), $userId, self::OUTCOME_BLOCKED);
			return;
		}

		$blocked = $this->blockedReason($userId, $closingYear);
		if ($blocked !== null) {
			$this->itemMapper->finishItem($item, $leaseToken, YearResetItem::STATE_SKIPPED, [
				'error_code' => $blocked,
			]);
			$this->refMapper->markOutcome($run->getId(), $userId, self::OUTCOME_BLOCKED);
			return;
		}

		$policy = json_decode($run->getPolicySnapshot() ?? '{}', true);
		$ye = is_array($policy['yearEnd'] ?? null) ? $policy['yearEnd'] : [];
		$resetPositive = (bool)($ye['resetPositive'] ?? false);
		$waiveNegative = (bool)($ye['waiveNegative'] ?? false);

		try {
			// The per-user ledger lock spans the WHOLE unit (read → adjust →
			// revision bump → commit): releasing it before commit would let a
			// rival adjustment interleave and make the before/after ledger
			// chain self-contradicting (§15.3 already-held lock). The lock is
			// re-entrant, so createAdjustment's inner hold is a no-op here.
			$outcome = $this->adjustmentService->withBalanceLock($userId, function () use ($userId, $closingYear, $run, $resetPositive, $waiveNegative): array {
				$this->db->beginTransaction();
				// Same anchor as the preview: the compensating write cancels the
				// balance carried into closingYear+1 — measured at the boundary,
				// never at request time. createAdjustment re-anchors internally at
				// $effective, so anything else breaks the zero reconciliation.
				$boundary = new \DateTime(sprintf('%04d-01-01', $closingYear + 1));
				$before = $this->adjustmentService->getCurrentEffectiveBalance($userId, $boundary);
				$beforeCents = (int)round($before * 100);

				$reasonCode = null;
				if ($beforeCents > 0 && $resetPositive) {
					$reasonCode = OvertimeAdjustment::REASON_PERIOD_NULLUNG;
				} elseif ($beforeCents < 0 && $waiveNegative) {
					$reasonCode = OvertimeAdjustment::REASON_UNDERTIME_WAIVER;
				}

				if ($reasonCode === null) {
					// Zero balance or sign not selected: truthful no-op, still
					// inside the transaction so the snapshot commit is atomic
					// with the read.
					$this->revisionService->bumpForWrite($userId, [$closingYear]);
					$token = $this->revisionService->sourceRevision($userId, $closingYear);
					$this->db->commit();
					return ['kind' => 'noop', 'before' => $beforeCents, 'token' => $token];
				}

				$effective = new \DateTime(sprintf('%04d-01-01', $closingYear + 1));
				$note = sprintf(
					'Year-end reset %d (run #%d): %s',
					$closingYear,
					$run->getId(),
					(string)($run->getReason() ?? '')
				);
				$result = $this->adjustmentService->createAdjustment(
					$userId,
					round(-$before, 2),
					$reasonCode,
					mb_substr($note, 0, 500),
					$run->getActorUid(),
					$effective,
				);

				// Canonical-result reconciliation at ledger precision: the
				// reported after-balance must land at zero within centi-hour
				// epsilon.
				$afterCents = (int)round($result['balance_after'] * 100);
				if (abs($afterCents) > self::CENTI_EPSILON) {
					throw new \RuntimeException('Post-reset balance did not reconcile to zero');
				}

				// The reset is itself a financial write: bump BEFORE the
				// snapshot so the stored token is the post-reset authority —
				// never self-flagging.
				$this->revisionService->bumpForWrite($userId, [$closingYear]);
				$newToken = $this->revisionService->sourceRevision($userId, $closingYear);
				$this->db->commit();
				return [
					'kind' => 'reset',
					'before' => $beforeCents,
					'delta' => -$beforeCents,
					'adjustment_id' => (int)($result['adjustment']['id'] ?? 0),
					'token' => $newToken,
				];
			});

			if ($outcome['kind'] === 'noop') {
				$this->itemMapper->finishItem($item, $leaseToken, YearResetItem::STATE_DONE, [
					'before_centi_hours' => $outcome['before'],
					'delta_centi_hours' => 0,
					'adjustment_id' => null,
					'source_revision' => $outcome['token'],
					'review_state' => YearResetItem::REVIEW_CLEAR,
				]);
				$this->refMapper->markOutcome($run->getId(), $userId, self::OUTCOME_NOOP);
				return;
			}

			$this->itemMapper->finishItem($item, $leaseToken, YearResetItem::STATE_DONE, [
				'before_centi_hours' => $outcome['before'],
				'delta_centi_hours' => $outcome['delta'],
				'adjustment_id' => $outcome['adjustment_id'] > 0 ? $outcome['adjustment_id'] : null,
				'source_revision' => $outcome['token'],
				'review_state' => YearResetItem::REVIEW_CLEAR,
			]);
			$this->refMapper->markOutcome($run->getId(), $userId, self::OUTCOME_SUCCESS);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('year-end processItem failed: ' . $e::class . ' ' . $e->getMessage(), ['exception' => $e]);
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$code = $e instanceof BusinessRuleException && $e->getReasonCode() !== null
				? $e->getReasonCode()
				: self::ERROR_INTERNAL;
			$this->itemMapper->finishItem($item, $leaseToken, YearResetItem::STATE_FAILED, [
				'error_code' => $code,
			]);
			$this->refMapper->markOutcome($run->getId(), $userId, self::OUTCOME_FAILED);
		}
	}

	private function finalizeRunIfTerminal(int $runId): void
	{
		$counts = $this->itemMapper->countByState($runId);
		if (($counts[YearResetItem::STATE_PENDING] ?? 0) > 0
			|| ($counts[YearResetItem::STATE_IN_PROGRESS] ?? 0) > 0) {
			return;
		}
		$run = $this->runMapper->findById($runId);
		if ($run->getState() !== YearResetRun::STATE_EXECUTING) {
			return;
		}
		$failed = $counts[YearResetItem::STATE_FAILED] ?? 0;
		$run->setState($failed > 0 ? YearResetRun::STATE_FAILED : YearResetRun::STATE_COMPLETED);
		$run->setSelectionCompleteAt(new \DateTime('now'));
		$run->setUpdatedAt(new \DateTime('now'));
		try {
			$this->runMapper->update($run);
		} catch (\Throwable $e) {
			if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// A concurrent tick finalized the run first — its state wins.
				return;
			}
			throw $e;
		}
		$this->auditRun($run, 'year_end_run_finished', $counts, $run->getActorUid());
	}

	// ------------------------------------------------------------------ status

	/**
	 * @return array<string, mixed>
	 */
	public function runStatus(int $runId): array
	{
		$run = $this->findRunOr404($runId);
		$items = $this->itemMapper->findByRun($runId);
		$refs = $this->refMapper->findByRun($runId);
		$refByUser = [];
		foreach ($refs as $ref) {
			$refByUser[$ref->getUserId()] = $ref;
		}
		$rows = [];
		foreach ($items as $item) {
			if ($item->getLastExecutionRunId() !== $runId) {
				continue;
			}
			$stale = $this->revisionService->isStale(
				$item->getSourceRevision() ?? '',
				$item->getUserId(),
				$item->getClosingYear(),
			);
			$ref = $refByUser[$item->getUserId()] ?? null;
			$rows[] = [
				'user_id' => $item->getUserId(),
				'state' => $item->getState(),
				'error_code' => $item->getErrorCode(),
				'before_centi_hours' => $item->getBeforeCentiHours(),
				'delta_centi_hours' => $item->getDeltaCentiHours(),
				'adjustment_id' => $item->getAdjustmentId(),
				'review_state' => ($stale && $item->getReviewState() !== YearResetItem::REVIEW_NEEDS_REVIEW)
					? YearResetItem::REVIEW_NEEDS_REVIEW
					: $item->getReviewState(),
				'completed_at' => $item->getCompletedAt()?->format('c'),
				'outcome' => $ref?->getOutcome(),
			];
		}
		return [
			'id' => $run->getId(),
			'closing_year' => $run->getClosingYear(),
			'origin' => $run->getOrigin(),
			'state' => $run->getState(),
			'actor_uid' => $run->getActorUid(),
			'reason' => $run->getReason(),
			'policy_revision' => $run->getPolicyRevision(),
			'created_at' => $run->getCreatedAt()?->format('c'),
			'updated_at' => $run->getUpdatedAt()?->format('c'),
			'counts' => $this->itemMapper->countByState($runId),
			'items' => $rows,
		];
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function listRuns(int $limit, int $offset): array
	{
		$limit = max(1, min(100, $limit));
		$rows = [];
		foreach ($this->runMapper->listRecent($limit, $offset) as $run) {
			$rows[] = [
				'id' => $run->getId(),
				'closing_year' => $run->getClosingYear(),
				'origin' => $run->getOrigin(),
				'state' => $run->getState(),
				'actor_uid' => $run->getActorUid(),
				'reason' => $run->getReason(),
				'created_at' => $run->getCreatedAt()?->format('c'),
				'counts' => $this->itemMapper->countByState($run->getId()),
			];
		}
		return ['items' => $rows, 'total' => $this->runMapper->countAll()];
	}

	// ------------------------------------------------------------------ purge

	/**
	 * §16.9 retention: drop expired, never-consumed preview runs together
	 * with their per-target snapshot refs. A previewed run whose handle
	 * expired can never execute (execute() re-checks preview_expires_at),
	 * so this deletes no reachable work — only the financial snapshot the
	 * preview captured.
	 */
	public function purgeExpiredPreviews(?\DateTimeInterface $now = null): int
	{
		$now = $now ?? new \DateTime('now');
		$purged = 0;
		foreach ($this->runMapper->findExpiredPreviews($now) as $run) {
			$this->db->beginTransaction();
			try {
				$this->refMapper->deleteByRun($run->getId());
				$this->runMapper->delete($run);
				$this->db->commit();
				$purged++;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
		return $purged;
	}

	// ------------------------------------------------------------------ annual

	/**
	 * Cron authority: when the policy window is due, ensure an annual run exists
	 * for the closing year and keep ticking it (catch-up included).
	 */
	public function annualTick(): void
	{
		$due = $this->policyService->annualDueState();
		if (!$due['due'] || $due['closing_year'] === null) {
			return;
		}
		$closingYear = $due['closing_year'];
		$run = $this->runMapper->findActiveRun(YearResetRun::ORIGIN_ANNUAL, $closingYear);
		if ($run === null) {
			$run = $this->createAnnualRun($closingYear);
		}
		if ($run !== null && $run->getState() === YearResetRun::STATE_EXECUTING) {
			$this->runTick($run->getId());
		}
	}

	private function createAnnualRun(int $closingYear): ?YearResetRun
	{
		$policy = $this->policyService->get();
		$userIds = $this->resolveTargetUsers($policy->yearEndTargetGroupIds);
		$now = new \DateTime('now');
		$run = new YearResetRun();
		$run->setOperationKey($this->newOperationKey());
		$run->setClosingYear($closingYear);
		$run->setOrigin(YearResetRun::ORIGIN_ANNUAL);
		$run->setPolicyRevision($this->policyService->revision());
		$run->setPolicySnapshot(json_encode($policy->toArray(), JSON_THROW_ON_ERROR));
		$run->setSelectionSnapshot(json_encode($userIds, JSON_THROW_ON_ERROR));
		$run->setActorUid('system');
		$run->setReason('Annual automatic year-end reset');
		$run->setState(YearResetRun::STATE_EXECUTING);
		$run->setRevision(1);
		$run->setCreatedAt($now);
		$run->setUpdatedAt($now);
		try {
			$run = $this->runMapper->insert($run);
		} catch (\Throwable $e) {
			if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// A concurrent tick created the annual run first — adopt it.
				return $this->runMapper->findActiveRun(YearResetRun::ORIGIN_ANNUAL, $closingYear);
			}
			throw $e;
		}

		foreach ($userIds as $uid) {
			$item = $this->itemMapper->findByUserAndYear($uid, $closingYear);
			if ($item === null) {
				$item = new YearResetItem();
				$item->setUserId($uid);
				$item->setClosingYear($closingYear);
				$item->setRevision(1);
				$item->setCreatedAt($now);
				$item->setReviewState(YearResetItem::REVIEW_CLEAR);
			}
			if ($item->getState() === YearResetItem::STATE_DONE) {
				continue;
			}
			$item->setState(YearResetItem::STATE_PENDING);
			$item->setErrorCode(null);
			$item->setSourceRevision($this->revisionService->sourceRevision($uid, $closingYear));
			$item->setLastExecutionRunId($run->getId());
			$item->setUpdatedAt($now);
			$this->saveItem($item);
		}
		$this->auditRun($run, 'year_end_annual_run_created', [
			'closing_year' => $closingYear,
			'targets' => count($userIds),
		], 'system');
		return $run;
	}

	/**
	 * @param list<string> $groupIds
	 * @return list<string>
	 */
	private function resolveTargetUsers(array $groupIds): array
	{
		$uids = [];
		foreach (array_slice($groupIds, 0, WorkforcePolicyService::MAX_TARGET_GROUPS) as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uids[$user->getUID()] = true;
			}
		}
		return array_keys($uids);
	}

	// ----------------------------------------------------------------- helpers

	private function lookupPreviewRun(string $handle): YearResetRun
	{
		if (!preg_match('/^[0-9a-f]{64}$/', $handle)) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
		}
		try {
			return $this->runMapper->findByPreviewKeyHash(hash('sha256', $handle));
		} catch (DoesNotExistException) {
			throw $this->error(self::ERROR_PREVIEW_EXPIRED, 'Preview is no longer valid');
		}
	}

	private function findRunOr404(int $runId): YearResetRun
	{
		try {
			return $this->runMapper->findById($runId);
		} catch (DoesNotExistException) {
			throw $this->error(self::ERROR_NOT_FOUND, 'Run not found');
		}
	}

	private function assertManualEnabled(): void
	{
		if (!$this->policyService->isYearEndManualEnabled()) {
			throw $this->error(self::ERROR_FEATURE_DISABLED, 'Year-end reset is disabled');
		}
	}

	private function assertPolicyRevision(int $expected, string $code): void
	{
		if ($expected !== $this->policyService->revision()) {
			throw $this->error($code, 'Policy changed; re-read settings before retrying');
		}
	}

	private function assertClosingYear(int $year): void
	{
		$policy = $this->policyService->get();
		$tz = new \DateTimeZone($policy->yearEndTimezone !== ''
			? $policy->yearEndTimezone
			: 'UTC');
		$currentYear = (int)(new \DateTimeImmutable('now', $tz))->format('Y');
		if ($year < self::CLOSING_YEAR_MIN || $year > self::CLOSING_YEAR_MAX || $year >= $currentYear) {
			throw $this->error(self::ERROR_POLICY_INVALID, 'Closing year must be 2000-2100 and before the current year');
		}
	}

	private function assertReason(string $reason): string
	{
		$reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
		$len = mb_strlen($reason);
		if ($len < self::REASON_MIN || $len > self::REASON_MAX) {
			throw $this->error(self::ERROR_REASON_REQUIRED, 'Reason must be 10-500 characters');
		}
		return $reason;
	}

	/**
	 * @param list<string> $userIds
	 * @return list<string>
	 */
	private function assertSelection(array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('strval', $userIds)));
		if ($userIds === [] || count($userIds) > self::MAX_SELECTION) {
			throw $this->error(self::ERROR_POLICY_INVALID, 'Select 1-200 users');
		}
		foreach ($userIds as $uid) {
			if ($uid === '' || !$this->userManager->userExists($uid)) {
				throw $this->error(self::ERROR_POLICY_INVALID, 'Unknown user: ' . $uid);
			}
		}
		return $userIds;
	}

	private function newOperationKey(): string
	{
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
			random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
			random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
		);
	}

	private function saveItem(YearResetItem $item): void
	{
		try {
			if ($item->getId() === null) {
				$this->itemMapper->insert($item);
			} else {
				$this->itemMapper->update($item);
			}
		} catch (\Throwable $e) {
			if (!($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) || $item->getId() !== null) {
				throw $e;
			}
			// at_yr_item_uq (user_id, closing_year): a concurrent run won the
			// first write between findByUserAndYear and this insert — adopt
			// its row and apply the caller's mutations onto it.
			$winner = $this->itemMapper->findByUserAndYear($item->getUserId(), $item->getClosingYear());
			if ($winner === null) {
				throw $e;
			}
			$item->setId($winner->getId());
			$item->setRevision($winner->getRevision());
			$item->setCreatedAt($winner->getCreatedAt());
			try {
				$this->itemMapper->update($item);
			} catch (\OCP\DB\Exception $updateError) {
				// The adopted row cannot collide on (user_id, closing_year) —
				// a violation means a third writer raced the adoption.
				if ($updateError->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				throw $updateError;
			}
		}
	}


	private function error(string $code, string $message): BusinessRuleException
	{
		return new BusinessRuleException($message, $code, ['error_code' => $code]);
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function auditRun(YearResetRun $run, string $action, array $details, string $actor): void
	{
		try {
			$this->auditLogMapper->logAction(
				$actor,
				$action,
				'year_reset_run',
				$run->getId() ?? 0,
				[],
				$details,
				$actor,
			);
		} catch (\Throwable) {
			// Audit must never break the orchestrator; the run row is the receipt.
		}
	}
}
