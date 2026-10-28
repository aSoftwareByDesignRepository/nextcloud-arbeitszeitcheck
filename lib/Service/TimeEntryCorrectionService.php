<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use OCA\ArbeitszeitCheck\Support\BreakCountable;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Shared correction proposal apply/validate/approve/reject logic.
 */
class TimeEntryCorrectionService
{
	public function __construct(
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly MonthClosureGuard $monthClosureGuard,
		private readonly ComplianceService $complianceService,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly NotificationService $notificationService,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
		private readonly ProjectCheckIntegrationService $projectCheckIntegration,
		private readonly ProjectCheckLaborTimeSyncService $projectCheckLaborSync,
		private readonly ?LaborLawProfileFactory $lawProfileFactory = null,
		private readonly ?UserWorkLocationService $userWorkLocationService = null,
	) {
	}

	private function userWorkLocationService(): UserWorkLocationService
	{
		return $this->userWorkLocationService ?? \OCP\Server::get(UserWorkLocationService::class);
	}

	private function countableMinBreakMinutes(TimeEntry $entry): int
	{
		try {
			$factory = $this->lawProfileFactory ?? \OCP\Server::get(LaborLawProfileFactory::class);
			return BreakCountable::minMinutes($factory->getProfile($entry->getUserId())->minBreakMinutes);
		} catch (\Throwable) {
			return BreakCountable::DEFAULT_MIN_MINUTES;
		}
	}

	public function applyProposal(TimeEntry $entry, array $proposal): void
	{
		// MH-11: client payloads must never write the allocator-owned paid-break
		// snapshot. applyProposal() already uses an explicit key allow-list, so
		// this is defense-in-depth for every future field that might loop keys.
		unset(
			$proposal['paid_break_seconds'],
			$proposal['paidBreakSeconds'],
			$proposal['paid_break_minutes'],
			$proposal['paidBreakMinutes'],
			$proposal['paid_duration_hours'],
			$proposal['paidDurationHours'],
		);

		if (isset($proposal['breaks']) && is_array($proposal['breaks'])) {
			$this->applyBreaksJson($entry, $proposal['breaks']);
		}

		if (isset($proposal['startTime'])) {
			$entry->setStartTime(new \DateTime((string)$proposal['startTime']));
		}
		if (isset($proposal['endTime'])) {
			$entry->setEndTime(new \DateTime((string)$proposal['endTime']));
		}
		if (array_key_exists('breakStartTime', $proposal)) {
			$entry->setBreakStartTime($proposal['breakStartTime'] ? new \DateTime((string)$proposal['breakStartTime']) : null);
			if ($proposal['breakStartTime']) {
				$entry->setBreaks(null);
			}
		}
		if (array_key_exists('breakEndTime', $proposal)) {
			$entry->setBreakEndTime($proposal['breakEndTime'] ? new \DateTime((string)$proposal['breakEndTime']) : null);
		}
		if (array_key_exists('description', $proposal)) {
			$entry->setDescription($proposal['description'] === null ? null : (string)$proposal['description']);
		}
		// GH #43: null/'' clears the mark; invalid values must already have been
		// rejected upstream (validateProposal); keep the throw as a last fence.
		if (\array_key_exists('workLocation', $proposal) || \array_key_exists('work_location', $proposal)) {
			$entry->setWorkLocation(WorkLocation::normalize($proposal['workLocation'] ?? $proposal['work_location']));
		}
		if (\array_key_exists('projectCheckProjectId', $proposal) || \array_key_exists('project_check_project_id', $proposal)) {
			$raw = $proposal['projectCheckProjectId'] ?? $proposal['project_check_project_id'] ?? null;
			if ($raw === null || $raw === '') {
				$entry->setProjectCheckProjectId(null);
				$entry->setProjectCheckTimeEntryId(null);
			} else {
				$entry->setProjectCheckProjectId((string)$raw);
			}
		}
		if (isset($proposal['date'])) {
			$newStart = new \DateTime((string)$proposal['date']);
			$entry->setStartTime($newStart);
			if (isset($proposal['hours'])) {
				$endTime = clone $newStart;
				$endTime->modify('+' . round(((float)$proposal['hours']) * 3600) . ' seconds');
				$entry->setEndTime($endTime);
			}
		} elseif (isset($proposal['hours']) && $entry->getStartTime()) {
			$endTime = clone $entry->getStartTime();
			$endTime->modify('+' . round(((float)$proposal['hours']) * 3600) . ' seconds');
			$entry->setEndTime($endTime);
		}
	}

	/**
	 * Replace the entry's break collection with a normalized list.
	 *
	 * Semantics (explicit so the auditor can read it once and trust it):
	 *  - An empty input list clears all break fields.
	 *  - Each break must have `start`/`end` (or legacy `start_time`/`end_time`).
	 *  - Items shorter than the profile floor (DE/CH 15, AT 10) are dropped silently.
	 *  - If after filtering the list is empty, all break fields are cleared too —
	 *    this matches the user's intent ("I sent breaks; none survived validation"),
	 *    avoids leaving stale legacy fields, and is symmetric with the empty-input case.
	 *
	 * @param list<array<string, mixed>> $breaks
	 */
	private function applyBreaksJson(TimeEntry $entry, array $breaks): void
	{
		$minSeconds = BreakCountable::minSeconds($this->countableMinBreakMinutes($entry));
		$validBreaks = [];
		foreach ($breaks as $break) {
			if (!is_array($break)) {
				continue;
			}
			$startKey = isset($break['start']) ? 'start' : (isset($break['start_time']) ? 'start_time' : null);
			$endKey = isset($break['end']) ? 'end' : (isset($break['end_time']) ? 'end_time' : null);
			if ($startKey === null || $endKey === null) {
				continue;
			}
			try {
				$breakStart = new \DateTime((string)$break[$startKey]);
				$breakEnd = new \DateTime((string)$break[$endKey]);
				if ($breakEnd < $breakStart) {
					$breakEnd->modify('+1 day');
				}
				$durationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
				if ($durationSeconds >= $minSeconds) {
					$validBreaks[] = [
						'start' => $breakStart->format('c'),
						'end' => $breakEnd->format('c'),
					];
				}
			} catch (\Throwable $e) {
				continue;
			}
		}

		if ($validBreaks === []) {
			$entry->setBreaks(null);
			$entry->setBreakStartTime(null);
			$entry->setBreakEndTime(null);
			return;
		}

		$entry->setBreaks(json_encode($validBreaks, JSON_THROW_ON_ERROR));
		$entry->setBreakStartTime(null);
		$entry->setBreakEndTime(null);
	}

	public function validateProposal(TimeEntry $entry, array $proposal, ?string $actingManagerUserId = null): ?string
	{
		$candidate = clone $entry;
		try {
			$this->applyProposal($candidate, $proposal);
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->ensureCandidateStatusForValidation($candidate);

		try {
			$this->monthClosureGuard->assertTimeEntryMutable($candidate);
		} catch (MonthFinalizedException $e) {
			return $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.');
		}

		if ($candidate->getStartTime()) {
			$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime(
				$entry->getUserId(),
				$candidate->getStartTime(),
				$entry->getId()
			);
			if (!($restPeriodCheck['valid'] ?? false)) {
				return (string)($restPeriodCheck['message'] ?? $this->l10n->t('Rest period validation failed.'));
			}
		}

		if ($candidate->getEndTime() && $candidate->getStartTime()) {
			$this->timeTrackingService->calculateAndSetAutomaticBreak($candidate);
			$this->timeTrackingService->adjustEndTimeForDailyMaximum($candidate);
			$overlapping = $this->timeEntryMapper->findOverlapping(
				$entry->getUserId(),
				$candidate->getStartTime(),
				$candidate->getEndTime(),
				$entry->getId()
			);
			if ($overlapping !== []) {
				return $this->l10n->t('This correction overlaps with existing time entries.');
			}
		}

		$errors = $candidate->validate($this->countableMinBreakMinutes($candidate));
		if ($errors !== []) {
			$firstError = reset($errors);
			return $this->l10n->t((string)$firstError);
		}

		// Fail-closed labour-law break gate (ArbZG §4 / AZG §11 splits / ArG Art. 15).
		// Same gate as employee portal saves — manager create/correct and correction
		// approve must not persist illegal AT splits (e.g. 20+10).
		$blocking = $this->complianceService->blockingIssuesForCompletedEntry($candidate);
		if ($blocking !== []) {
			return (string)$blocking[0];
		}

		if ($this->projectCheckIntegration->isProjectCheckAvailable()) {
			$candidatePid = $candidate->getProjectCheckProjectId();
			$currentPid = $entry->getProjectCheckProjectId();
			$candidatePid = ($candidatePid === null || $candidatePid === '') ? null : (string)$candidatePid;
			$currentPid = ($currentPid === null || $currentPid === '') ? null : (string)$currentPid;
			// Only authorise when the link actually changes (add, change, or clear).
			// Unchanged links stay valid even if the admin connection was turned off
			// or team access was revoked — mirrors TimeEntryController::update().
			if ($candidatePid !== $currentPid && $candidatePid !== null) {
				$allowed = $actingManagerUserId !== null && $actingManagerUserId !== ''
					? $this->projectCheckIntegration->managerMayAttachProjectCheckProjectForEmployee(
						$actingManagerUserId,
						$entry->getUserId(),
						$candidatePid
					)
					: $this->projectCheckIntegration->userMayAttachProjectCheckProjectToOwnTime($entry->getUserId(), $candidatePid);
				if (!$allowed) {
					return $this->l10n->t('You cannot link this ProjectCheck project to your time entry.');
				}
			}
		}

		return null;
	}

	/**
	 * @param string $actorUserId User performing the write (employee or manager)
	 */
	public function syncProjectCheckBilling(TimeEntry $entry, string $actorUserId): ?string
	{
		if (!$this->projectCheckIntegration->isProjectCheckAvailable()) {
			return null;
		}
		$result = $this->projectCheckLaborSync->syncFromTimeEntry($entry, $actorUserId);
		if (!($result['success'] ?? false)) {
			return $this->l10n->t('Working time was saved, but billing hours in ProjectCheck could not be updated. Please check the project link or contact an administrator.');
		}
		return null;
	}

	/**
	 * Apply ArbZG §4 break / §3 daily-max guards after a proposal has been written.
	 *
	 * `validateProposal()` runs these calculators on a clone for validation. The
	 * persisted entry must receive the same treatment so the stored row is itself
	 * ArbZG-compliant — otherwise compliance/audit checks downstream would see a
	 * 10h+ entry without an auto-injected break and flag a false violation.
	 */
	private function applyComplianceAdjustments(TimeEntry $entry): void
	{
		if ($entry->getStartTime() === null || $entry->getEndTime() === null) {
			return;
		}
		try {
			$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
			$this->timeTrackingService->adjustEndTimeForDailyMaximum($entry);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Failed to apply compliance adjustments to corrected entry ' . $entry->getId() . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}

	/**
	 * NN-2: a decision must never produce a completed row with end_time NULL.
	 * When the request closed an open session, mark the end provenance for audit.
	 *
	 * @param array<string, mixed> $justificationData
	 */
	private function assertCompletableDecision(TimeEntry $entry, array $justificationData): void
	{
		if ($entry->getEndTime() === null) {
			throw new \InvalidArgumentException(
				$this->l10n->t('A clock-out time is required to complete this session.')
			);
		}
		$original = is_array($justificationData['original'] ?? null) ? $justificationData['original'] : [];
		if (\array_key_exists('endTime', $original) && $original['endTime'] === null && $entry->getEndedReason() === null) {
			$entry->setEndedReason(TimeEntry::ENDED_REASON_APPROVED_CLOCK_OUT);
		}
	}

	/**
	 * Approve with a manager-adjusted proposal (BQ-3): the manager's version is
	 * validated/applied; the employee's requested values stay on the record.
	 *
	 * @param array<string, mixed> $adjustedProposal
	 */
	public function approveWithAdjustment(TimeEntry $entry, string $managerId, ?string $comment, array $adjustedProposal): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (!is_array($justificationData)) {
			$justificationData = [];
		}

		$error = $this->validateProposal($entry, $adjustedProposal, $managerId);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}
		$this->applyProposal($entry, $adjustedProposal);
		$this->applyComplianceAdjustments($entry);
		$this->assertCompletableDecision($entry, $justificationData);

		$requestedProposal = is_array($justificationData['proposed'] ?? null) ? $justificationData['proposed'] : null;
		if ($requestedProposal !== null) {
			$justificationData['requested_proposal'] = $requestedProposal;
		}
		$justificationData['proposed'] = $adjustedProposal;
		$justificationData['adjusted_by_manager'] = true;

		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		$justificationData['approved_at'] = date('c');
		$justificationData['approved_by'] = $managerId;
		if ($comment !== null && $comment !== '') {
			$justificationData['approval_comment'] = $comment;
		}
		$entry->setJustification(json_encode($justificationData, JSON_THROW_ON_ERROR));

		$updated = $this->persistPendingDecision($entry);
		$updated = $this->recomputePaidBreaks($updated);
		$this->runComplianceIfEnabled($updated);
		$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

		return $updated;
	}

	public function approve(TimeEntry $entry, string $managerId, ?string $comment = null): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (!is_array($justificationData)) {
			$justificationData = [];
		}

		$proposal = $justificationData['proposed'] ?? null;
		if (is_array($proposal) && $proposal !== []) {
			$error = $this->validateProposal($entry, $proposal);
			if ($error !== null) {
				throw new \InvalidArgumentException($error);
			}
			$this->applyProposal($entry, $proposal);
			$this->applyComplianceAdjustments($entry);
		}
		$this->assertCompletableDecision($entry, $justificationData);

		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		$justificationData['approved_at'] = date('c');
		$justificationData['approved_by'] = $managerId;
		if ($comment !== null && $comment !== '') {
			$justificationData['approval_comment'] = $comment;
		}
		$encoded = json_encode($justificationData, JSON_THROW_ON_ERROR);
		$entry->setJustification($encoded);

		$updated = $this->persistPendingDecision($entry);
		$updated = $this->recomputePaidBreaks($updated);
		$this->runComplianceIfEnabled($updated);
		$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

		return $updated;
	}

	public function autoApprove(TimeEntry $entry): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (is_array($justificationData)) {
			$proposal = $justificationData['proposed'] ?? null;
			if (is_array($proposal) && $proposal !== []) {
				// Defensive: same fail-closed labour-law gate as approve()/manager writes,
				// even if a future caller forgets to validate first.
				$error = $this->validateProposal($entry, $proposal);
				if ($error !== null) {
					throw new \InvalidArgumentException($error);
				}
				$this->applyProposal($entry, $proposal);
				$this->applyComplianceAdjustments($entry);
			}
		}
		if (is_array($justificationData)) {
			$this->assertCompletableDecision($entry, $justificationData);
		}

		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setApprovedByUserId(null);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		if (is_array($justificationData)) {
			$justificationData['approval_comment'] = $this->l10n->t('Auto-approved: no approver is assigned to your team in the app.');
			$justificationData['approved_at'] = date('c');
			$justificationData['approved_by'] = 'system';
			$encoded = json_encode($justificationData, JSON_THROW_ON_ERROR);
			$entry->setJustification($encoded);
		}

		$updated = $this->persistPendingDecision($entry);
		$updated = $this->recomputePaidBreaks($updated);
		$this->runComplianceIfEnabled($updated);

		// B1-MH-05: audit row mirrors absence auto-approval — reason + which
		// approvers the predicate counted (method_exists: upgrade repair paths
		// may hold a stale TeamResolverService).
		try {
			$this->auditLogMapper->logAction(
				$updated->getUserId(),
				'time_entry_auto_approved',
				'time_entry',
				$updated->getId(),
				null,
				array_merge($updated->getSummary(), [
					'auto_approve' => [
						'reason' => 'no_assignable_approver',
						'predicate' => $this->approverPredicateSnapshotSafe($updated->getUserId()),
					],
				]),
				'system'
			);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Auto-approve audit row failed for entry ' . $updated->getId() . ': ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
		}

		$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, 'system'));

		return $updated;
	}

	/**
	 * Approver-predicate snapshot for the auto-approve audit row; null when the
	 * resolver predates the method (upgrade path with stale classes).
	 *
	 * @return array{mode: string, approvers: list<string>}|null
	 */
	private function approverPredicateSnapshotSafe(string $employeeUserId): ?array
	{
		try {
			$resolver = \OCP\Server::get(TeamResolverService::class);
			return method_exists($resolver, 'approverPredicateSnapshot')
				? $resolver->approverPredicateSnapshot($employeeUserId)
				: null;
		} catch (\Throwable) {
			return null;
		}
	}

	public function reject(TimeEntry $entry, string $managerId, ?string $reason = null): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (!is_array($justificationData)) {
			$justificationData = [];
		}

		// Four-eyes reject of a *new* manual entry: there is no original snapshot to
		// restore. restoreOriginalSnapshot([]) would keep the proposed clocks and set
		// status=completed — i.e. "Reject" would silently approve hours. That is forbidden.
		if (($justificationData['type'] ?? '') === 'manual_create') {
			$entry->setStatus(TimeEntry::STATUS_REJECTED);
			$entry->setApprovedByUserId(null);
			$entry->setApprovedAt(null);
			$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));
			$justificationData['rejected_at'] = date('c');
			$justificationData['rejected_by'] = $managerId;
			if ($reason !== null && $reason !== '') {
				$justificationData['rejection_reason'] = $reason;
			}
			$entry->setJustification(json_encode($justificationData, JSON_THROW_ON_ERROR));

			$rejected = $this->persistPendingDecision($entry);
			// Status flipped to REJECTED — its breaks leave the day pool.
			$rejected = $this->recomputePaidBreaks($rejected);
			return $rejected;
		}

		$originalData = is_array($justificationData['original'] ?? null) ? $justificationData['original'] : [];
		$this->restoreOriginalSnapshot($entry, $originalData);
		$entry->setApprovedByUserId(null);
		$entry->setApprovedAt(null);
		$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));

		if ($reason !== null && $reason !== '') {
			$justificationData['rejection_reason'] = $reason;
			$justificationData['rejected_at'] = date('c');
			$justificationData['rejected_by'] = $managerId;
			$entry->setJustification(json_encode($justificationData, JSON_THROW_ON_ERROR));
		}

		$rejected = $this->persistPendingDecision($entry);
		// Restored snapshot changed the recorded breaks — rebalance the pool.
		$rejected = $this->recomputePaidBreaks($rejected);
		return $rejected;
	}

	/**
	 * Cancel a pending correction. Returns null when the row should be deleted (manual_create).
	 */
	public function cancelByEmployee(TimeEntry $entry): ?TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (!is_array($justificationData)) {
			$justificationData = [];
		}

		if (($justificationData['type'] ?? '') === 'manual_create') {
			return null;
		}

		$originalData = is_array($justificationData['original'] ?? null) ? $justificationData['original'] : [];
		$this->restoreOriginalSnapshot($entry, $originalData);
		$entry->setApprovedByUserId(null);
		$entry->setApprovedAt(null);
		$entry->setJustification(null);
		$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));

		$restored = $this->persistPendingDecision($entry);
		// Restored snapshot changed the recorded breaks — rebalance the pool.
		$restored = $this->recomputePaidBreaks($restored);
		return $restored;
	}

	/**
	 * Snapshot recompute for the day pool covering this entry's span.
	 * Delegates to the canonical lock-aware writer path (EC-05/MF-03).
	 */
	private function recomputePaidBreaks(TimeEntry $entry): TimeEntry
	{
		$start = $entry->getStartTime();
		if ($start === null) {
			return $entry;
		}
		$end = $entry->getEndTime()
			?? AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$this->timeTrackingService->recomputePaidBreaksForUserSpan(
			$entry->getUserId(),
			$start,
			$end
		);
		// Re-read so callers serialize the persisted paid_break_seconds
		// snapshot — the allocator wrote through its own entity set.
		$id = $entry->getId();
		if ($id === null) {
			return $entry;
		}
		try {
			$fresh = $this->timeEntryMapper->find($id);
			return $fresh instanceof TimeEntry ? $fresh : $entry;
		} catch (\Throwable) {
			return $entry;
		}
	}

	/**
	 * Write a pending-approval decision with an atomic status guard.
	 *
	 * @throws ConcurrentDecisionException When another manager already decided
	 */
	private function persistPendingDecision(TimeEntry $entry): TimeEntry
	{
		if (!$this->timeEntryMapper->updateIfPendingApproval($entry)) {
			throw new ConcurrentDecisionException(
				$this->l10n->t('This time entry was already decided by another manager.')
			);
		}
		return $entry;
	}

	public function applyManagerCorrection(TimeEntry $entry, array $proposal, string $managerId, string $reason): TimeEntry
	{
		$error = $this->validateProposal($entry, $proposal, $managerId);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}

		// Preserve previous justification so the manager correction remains traceable
		// (e.g. when a rejected/approved correction is later directly corrected by a manager).
		$previousJustification = null;
		if ($entry->getJustification() !== null && $entry->getJustification() !== '') {
			$decoded = json_decode($entry->getJustification(), true);
			if (is_array($decoded)) {
				$previousJustification = $decoded;
			}
		}

		$this->applyProposal($entry, $proposal);
		// A still-open break interval must be archived when the correction
		// finalises the session — otherwise the row keeps a dangling breakStart.
		if ($entry->getStatus() === TimeEntry::STATUS_BREAK && $entry->getBreakStartTime() !== null) {
			$breakStart = $entry->getBreakStartTime();
			$breakEnd = $entry->getEndTime() !== null && $entry->getEndTime() > $breakStart
				? clone $entry->getEndTime()
				: clone $breakStart;
			$existing = $entry->getBreaks();
			$breaksList = ($existing !== null && $existing !== '') ? (json_decode($existing, true) ?? []) : [];
			if (is_array($breaksList)) {
				$breaksList[] = [
					'start' => $breakStart->format('c'),
					'end' => $breakEnd->format('c'),
					'duration_minutes' => (int)round(($breakEnd->getTimestamp() - $breakStart->getTimestamp()) / 60),
				];
				$entry->setBreaks(json_encode($breaksList));
			}
			$entry->setBreakStartTime(null);
			$entry->setBreakEndTime(null);
		}
		$this->applyComplianceAdjustments($entry);
		if ($entry->getEndTime() === null) {
			throw new \InvalidArgumentException(
				$this->l10n->t('A clock-out time is required to complete this session.')
			);
		}
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		$justification = [
			'manager_correction' => true,
			'reason' => $reason,
			'corrected_at' => date('c'),
			'corrected_by' => $managerId,
		];
		if ($previousJustification !== null) {
			$justification['previous_justification'] = $previousJustification;
		}
		$entry->setJustification(json_encode($justification, JSON_THROW_ON_ERROR));

		$updated = $this->timeEntryMapper->update($entry);
		$updated = $this->recomputePaidBreaks($updated);
		$this->runComplianceIfEnabled($updated);
		$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

		return $updated;
	}

	/**
	 * Insert a completed manual entry recorded by a manager on behalf of an employee.
	 *
	 * @throws \InvalidArgumentException When validation fails
	 */
	public function createManagerRecordedEntry(TimeEntry $entry, array $proposal, string $managerId, string $reason): TimeEntry
	{
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setJustification(json_encode([
			'manager_created' => true,
			'reason' => $reason,
			'created_at' => date('c'),
			'created_by' => $managerId,
		], JSON_THROW_ON_ERROR));

		$error = $this->validateProposal($entry, $proposal, $managerId);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}

		$this->applyProposal($entry, $proposal);
		// GH #43: manager-recorded entries follow the same resolution as every
		// other create path — proposal value wins, else the employee default.
		if ($entry->getWorkLocation() === null) {
			$entry->setWorkLocation(
				$this->userWorkLocationService()->resolveForNewEntry($entry->getUserId(), null, $entry->getStartTime())
			);
		}
		$this->applyComplianceAdjustments($entry);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		$inserted = $this->timeEntryMapper->insert($entry);
		$inserted = $this->recomputePaidBreaks($inserted);
		$this->runComplianceIfEnabled($inserted);
		$inserted->setBillingSyncWarning($this->syncProjectCheckBilling($inserted, $managerId));

		return $inserted;
	}

	/**
	 * Mark a new manual entry as pending manager approval (four-eyes mode).
	 */
	public function prepareManualPending(TimeEntry $entry, string $justificationText): void
	{
		$proposed = [
			'startTime' => $entry->getStartTime()?->format('c'),
			'endTime' => $entry->getEndTime()?->format('c'),
			'description' => $entry->getDescription(),
			'workLocation' => $entry->getWorkLocation(),
		];
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => $justificationText,
			'proposed' => $proposed,
			'requested_at' => date('c'),
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * Proposal validation clones entries before persistence. New rows (e.g. manager-recorded
	 * entries) have no status yet; infer the completed shape when the proposal includes an end time.
	 */

	/**
	 * Restore times/status from the correction snapshot.
	 *
	 * `isset($original['endTime'])` is false when the original was paused
	 * (endTime: null). Using array_key_exists keeps a null end and restores
	 * STATUS_PAUSED so cancel/reject cannot create a completed row with no end.
	 *
	 * @param array<string, mixed> $originalData
	 */
	private function restoreOriginalSnapshot(TimeEntry $entry, array $originalData): void
	{
		if (isset($originalData['startTime'])) {
			$entry->setStartTime(new \DateTime((string)$originalData['startTime']));
		} elseif (isset($originalData['date'])) {
			$entry->setStartTime(new \DateTime((string)$originalData['date']));
		}
		if (array_key_exists('endTime', $originalData)) {
			$rawEnd = $originalData['endTime'];
			$entry->setEndTime(is_string($rawEnd) && $rawEnd !== '' ? new \DateTime($rawEnd) : null);
		} elseif (isset($originalData['hours']) && $entry->getStartTime()) {
			$endTime = clone $entry->getStartTime();
			$endTime->modify('+' . (int)round((float)$originalData['hours'] * 3600) . ' seconds');
			$entry->setEndTime($endTime);
		}
		if (array_key_exists('breakStartTime', $originalData)) {
			$raw = $originalData['breakStartTime'];
			$entry->setBreakStartTime(is_string($raw) && $raw !== '' ? new \DateTime($raw) : null);
		} else {
			$entry->setBreakStartTime(null);
		}
		if (array_key_exists('breakEndTime', $originalData)) {
			$raw = $originalData['breakEndTime'];
			$entry->setBreakEndTime(is_string($raw) && $raw !== '' ? new \DateTime($raw) : null);
		} else {
			$entry->setBreakEndTime(null);
		}
		if (isset($originalData['breaks']) && is_array($originalData['breaks'])) {
			$this->applyBreaksJson($entry, $originalData['breaks']);
		}
		if (array_key_exists('description', $originalData)) {
			$entry->setDescription($originalData['description'] ?? '');
		}
		if (array_key_exists('workLocation', $originalData)) {
			$loc = $originalData['workLocation'];
			$entry->setWorkLocation(is_string($loc) && WorkLocation::isValid($loc) ? $loc : null);
		}

		$originalStatus = $originalData['status'] ?? null;
		if ($entry->getEndTime() === null || $originalStatus === TimeEntry::STATUS_PAUSED) {
			$entry->setStatus(TimeEntry::STATUS_PAUSED);
		} else {
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		}
	}

	private function ensureCandidateStatusForValidation(TimeEntry $candidate): void
	{
		$status = $candidate->getStatus();
		if ($status !== null && $status !== '' && $candidate->getEndTime() === null) {
			return;
		}
		// A proposal that supplies an end time finalises the session — validate
		// the candidate in the completed shape so the labour-law blocking gate
		// (blockingIssuesForCompletedEntry requires status=completed) fires for
		// open-session corrections the same as for finished-entry corrections.
		if ($status === null || $status === ''
			|| in_array($status, [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK, TimeEntry::STATUS_PAUSED], true)) {
			if ($candidate->getEndTime() !== null) {
				$candidate->setStatus(TimeEntry::STATUS_COMPLETED);
			}
		}
	}

	private function runComplianceIfEnabled(TimeEntry $entry): void
	{
		if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
			return;
		}

		try {
			if ($this->config->getAppValue('arbeitszeitcheck', 'realtime_compliance_check', '1') !== '1') {
				return;
			}
			$strictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
			$this->complianceService->checkComplianceForCompletedEntry($entry, $strictMode);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Compliance check failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
