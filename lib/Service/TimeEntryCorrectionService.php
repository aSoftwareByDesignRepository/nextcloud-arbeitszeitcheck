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
	) {
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
		$this->applyProposal($candidate, $proposal);
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
		$this->runComplianceIfEnabled($updated);
		$this->syncProjectCheckBilling($updated, $managerId);

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
		$this->runComplianceIfEnabled($updated);
		$this->syncProjectCheckBilling($updated, 'system');

		return $updated;
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

			return $this->persistPendingDecision($entry);
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

		return $this->persistPendingDecision($entry);
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

		return $this->persistPendingDecision($entry);
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
		$this->applyComplianceAdjustments($entry);
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
		$this->runComplianceIfEnabled($updated);
		$this->syncProjectCheckBilling($updated, $managerId);

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
		$this->applyComplianceAdjustments($entry);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		$inserted = $this->timeEntryMapper->insert($entry);
		$this->runComplianceIfEnabled($inserted);
		$this->syncProjectCheckBilling($inserted, $managerId);

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
		if ($status !== null && $status !== '') {
			return;
		}
		if ($candidate->getEndTime() !== null) {
			$candidate->setStatus(TimeEntry::STATUS_COMPLETED);
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
