<?php

declare(strict_types=1);

/**
 * Atomic manager bulk-approve/reject across time entries, absences and
 * business trips (IMPLEMENTATION-OVERVIEW appendix "Bulk-Decision").
 *
 * Validation runs before any mutation: every item must exist, be in the
 * caller's manager scope, and still be in a decidable state. All decisions
 * are applied inside one outer transaction so a mid-flight failure rolls
 * back the whole batch. Per-item service calls keep their own audit and
 * notification side-effects.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BulkDecisionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;

final class ManagerBulkDecisionService
{
	public const ACTION_APPROVE = 'approve';
	public const ACTION_REJECT = 'reject';

	private const MAX_ITEMS = 200;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly AbsenceMapper $absenceMapper,
		private readonly BusinessTripMapper $businessTripMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly TimeEntryCorrectionService $correctionService,
		private readonly AbsenceService $absenceService,
		private readonly BusinessTripService $businessTripService,
		private readonly PermissionService $permissionService,
		private readonly WorkforceSuiteConfig $config,
		private readonly IL10N $l10n,
		private ?PostCommitEffectQueue $postCommitEffectQueue = null,
	) {
	}

	/**
	 * Apply one manager decision to a mixed list of pending items.
	 *
	 * @param list<array<string,mixed>> $items Raw request items
	 * @return list<array{type:string,id:int,status:string}>
	 * @throws \InvalidArgumentException On payload shape errors (400)
	 * @throws BulkDecisionException On item validation/conflict errors
	 */
	public function decide(string $managerId, string $action, array $items, ?string $comment = null): array
	{
		if ($action !== self::ACTION_APPROVE && $action !== self::ACTION_REJECT) {
			throw new \InvalidArgumentException($this->l10n->t('Invalid bulk action.'));
		}
		$normalized = $this->normalizeItems($items);
		$resolved = $this->resolveItems($managerId, $normalized);

		$this->db->beginTransaction();
		try {
			$results = [];
			foreach ($resolved as $item) {
				$results[] = $this->applyDecision($item, $action, $managerId, $comment);
			}
			$this->auditLogMapper->logAction(
				$managerId,
				'manager_bulk_decision',
				'bulk_decision',
				null,
				null,
				[
					'action' => $action,
					'item_count' => count($normalized),
					'items' => $normalized,
					'comment' => $comment,
				],
				$managerId
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			// Deferred external effects (mail) must never fire for rolled-back data.
			$this->effectQueue()->discard();
			$this->db->rollBack();
			throw $e;
		}

		// Only now are per-item decisions durable — release deferred external
		// effects (approval/rejection mail) collected by per-item services.
		$this->effectQueue()->flush();

		return $results;
	}

	private function effectQueue(): PostCommitEffectQueue
	{
		return $this->postCommitEffectQueue ??= \OCP\Server::get(PostCommitEffectQueue::class);
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return list<array{type:string,id:int,index:int}>
	 */
	private function normalizeItems(array $items): array
	{
		if ($items === [] || count($items) > self::MAX_ITEMS) {
			throw new \InvalidArgumentException($this->l10n->t('Bulk decision requires between 1 and 200 items.'));
		}

		$seen = [];
		$normalized = [];
		foreach ($items as $index => $item) {
			if (!is_array($item)) {
				throw new \InvalidArgumentException($this->l10n->t('Each bulk item must be an object with type and id.'));
			}
			$type = $item['type'] ?? null;
			$id = $item['id'] ?? null;
			if (!is_string($type) || !in_array($type, ['time_entry', 'absence', 'business_trip'], true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unknown bulk item type.'));
			}
			if (!is_int($id) || $id <= 0) {
				throw new \InvalidArgumentException($this->l10n->t('Bulk item id must be a positive integer.'));
			}
			$key = $type . ':' . $id;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$normalized[] = ['type' => $type, 'id' => $id, 'index' => $index];
		}

		return $normalized;
	}

	/**
	 * Load every item and enforce existence, scope and decidability before
	 * the transaction starts. Out-of-scope items collapse to a uniform 404
	 * so the caller cannot probe foreign ids (existence oracle).
	 *
	 * @param list<array{type:string,id:int,index:int}> $items
	 * @return list<array{type:string,id:int,index:int,entity:TimeEntry|Absence|BusinessTrip}>
	 */
	private function resolveItems(string $managerId, array $items): array
	{
		$resolved = [];
		foreach ($items as $item) {
			$type = $item['type'];
			$id = $item['id'];
			try {
				$entity = match ($type) {
					'time_entry' => $this->timeEntryMapper->find($id),
					'absence' => $this->absenceMapper->find($id),
					default => $this->businessTripMapper->find($id),
				};
			} catch (DoesNotExistException) {
				throw $this->notFound($item);
			}

			if ($type === 'business_trip' && !$this->config->isEnabled('business_trips_enabled')) {
				throw $this->notFound($item);
			}

			$userId = (string)$entity->getUserId();
			if (!$this->permissionService->canDecideEntryApproval($managerId, $userId)) {
				$this->permissionService->logPermissionDenied($managerId, 'bulk_decision', $type, (string)$id);
				throw $this->notFound($item);
			}

			$decidable = match ($type) {
				'time_entry' => $entity->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL,
				'absence' => $entity->getStatus() === Absence::STATUS_PENDING,
				default => $entity->getStatus() === BusinessTrip::STATUS_SUBMITTED,
			};
			if (!$decidable) {
				throw new BulkDecisionException(
					'already_decided',
					$this->l10n->t('This item was already decided by another manager.'),
					409,
					['status' => 'already_decided'],
					$item['index'],
					$id,
				);
			}

			$resolved[] = ['type' => $type, 'id' => $id, 'index' => $item['index'], 'entity' => $entity];
		}

		return $resolved;
	}

	/**
	 * @param array{type:string,id:int,index:int,entity:TimeEntry|Absence|BusinessTrip} $item
	 * @return array{type:string,id:int,status:string}
	 */
	private function applyDecision(array $item, string $action, string $managerId, ?string $comment): array
	{
		$entity = $item['entity'];
		$billingWarning = null;
		try {
			if ($item['type'] === 'time_entry') {
				if ($action === self::ACTION_APPROVE) {
					$billingWarning = $this->correctionService->approve($entity, $managerId, $comment)->getBillingSyncWarning();
				} else {
					$this->correctionService->reject($entity, $managerId, $comment);
				}
			} elseif ($item['type'] === 'absence') {
				if ($action === self::ACTION_APPROVE) {
					$this->absenceService->approveAbsence((int)$entity->getId(), $managerId, $comment);
				} else {
					$this->absenceService->rejectAbsence((int)$entity->getId(), $managerId, $comment);
				}
			} else {
				if ($action === self::ACTION_APPROVE) {
					$preview = $this->businessTripService->preview($entity, $managerId);
					$this->businessTripService->approve($entity, (int)$entity->getRevision(), (string)$preview['snapshotToken'], $managerId);
				} else {
					$this->businessTripService->reject($entity, (int)$entity->getRevision(), $managerId, $comment);
				}
			}
		} catch (\OCP\Lock\LockedException | \OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException) {
			throw new BulkDecisionException(
				'already_decided',
				$this->l10n->t('This item was already decided by another manager.'),
				409,
				['status' => 'already_decided'],
				$item['index'],
				$item['id'],
			);
		}

		$result = [
			'type' => $item['type'],
			'id' => $item['id'],
			'status' => $action === self::ACTION_APPROVE ? 'approved' : 'rejected',
		];
		if ($billingWarning !== null) {
			$result['billing_warning'] = $billingWarning;
		}
		return $result;
	}

	/** Uniform not-found for missing, disabled or out-of-scope items. */
	private function notFound(array $item): BulkDecisionException
	{
		return new BulkDecisionException(
			'NOT_FOUND',
			'Not found.',
			404,
			[],
			$item['index'] ?? null,
			isset($item['id']) ? (int)$item['id'] : null,
		);
	}
}
