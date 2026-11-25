<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Exception\SplitOverrideStaleException;
use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use OCA\ArbeitszeitCheck\Support\BreakCountable;
use OCA\ArbeitszeitCheck\Support\SplitServiceAccounting;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Shared correction proposal apply/validate/approve/reject logic.
 */
	// unique-conflict:ok — audit writes via logAction pass operation_key=NULL; time-entry writes target a table without a colliding unique index.
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
		private readonly ?SplitServiceAccountingService $splitServiceAccounting = null,
	) {
	}

	/**
	 * Proposal keys that name server-owned accounting surfaces (spec §9.8).
	 * A client payload naming any of these is a mutation attempt on frozen
	 * accounting state — an explicit `SERVER_OWNED_FIELD` rejection (400),
	 * never a silent allow-list skip like the paid-break snapshot fields.
	 */
	private const SERVER_OWNED_PROPOSAL_FIELDS = [
		'accounting',
		'work_context_json',
		'workContextJson',
		'excluded_work_seconds',
		'excludedWorkSeconds',
		'excludedMinutes',
		'excluded_minutes',
	];

	/** ISO-8601 with mandatory offset — same grammar the engine applies to overrides.worked. */
	private const WORKED_ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

	/** sha256 hex — format check for splitOverride.baseEntryRevision CAS tokens. */
	private const REVISION_TOKEN_PATTERN = '/^[0-9a-fA-F]{64}$/';

	/**
	 * Exclusive month-closure lock keys (month_closure tier — last in
	 * {@see DbLockKeys::ORDER}) for a persisted entry write: the entry's
	 * CURRENT post-mutation range unioned with the range it had before
	 * ($otherStart/$otherEnd) — an update that shifts a row between months
	 * must lock both sides or a seal of either can interleave with the
	 * write (the disjoint-lock TOCTOU the month-closure protocol closes).
	 *
	 * @return list<string>
	 */
	private function entryWriteMonthClosureKeys(TimeEntry $entry, ?\DateTimeInterface $otherStart, ?\DateTimeInterface $otherEnd): array
	{
		$keys = $this->monthClosureGuard->timeEntryMutableLockKeys($entry);
		if ($otherStart !== null) {
			$keys = array_merge(
				$keys,
				$this->monthClosureGuard->mutableRangeLockKeys(
					$entry->getUserId(),
					$otherStart,
					$otherEnd ?? $otherStart
				)
			);
		}
		$keys = array_values(array_unique($keys));
		sort($keys);
		return $keys;
	}

	/**
	 * Run $fn holding the exclusive month-closure locks — the durable
	 * assert inside re-checks BOTH the post-mutation entry range and the
	 * pre-mutation one, so a month sealed on either side of a move blocks
	 * the write, and an in-flight seal serializes on the held keys.
	 *
	 * @param list<string> $keys
	 * @param callable():TimeEntry $fn
	 */
	private function writeEntryUnderMonthClosureLocks(TimeEntry $entry, ?\DateTimeInterface $otherStart, ?\DateTimeInterface $otherEnd, array $keys, callable $fn): TimeEntry
	{
		$this->monthClosureGuard->acquireMutableRangeLockKeys($keys);
		try {
			$this->monthClosureGuard->assertTimeEntryMutable($entry);
			if ($otherStart !== null) {
				$this->monthClosureGuard->assertRangeMutable($entry->getUserId(), $otherStart, $otherEnd ?? $otherStart);
			}
			return $fn();
		} finally {
			$this->monthClosureGuard->releaseMutableRangeLocks($keys);
		}
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
		// §9.8: server-owned accounting fields are a hard reject (400
		// SERVER_OWNED_FIELD) — distinct from the silent paid-break unset below.
		foreach (self::SERVER_OWNED_PROPOSAL_FIELDS as $field) {
			if (\array_key_exists($field, $proposal)) {
				throw new \InvalidArgumentException('SERVER_OWNED_FIELD');
			}
		}

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
			$entry->setStartTime($this->storageDateTime((string)$proposal['startTime']));
		}
		if (isset($proposal['endTime'])) {
			$entry->setEndTime($this->storageDateTime((string)$proposal['endTime']));
		}
		if (array_key_exists('breakStartTime', $proposal)) {
			$entry->setBreakStartTime($proposal['breakStartTime'] ? $this->storageDateTime((string)$proposal['breakStartTime']) : null);
			if ($proposal['breakStartTime']) {
				$entry->setBreaks(null);
			}
		}
		if (array_key_exists('breakEndTime', $proposal)) {
			$entry->setBreakEndTime($proposal['breakEndTime'] ? $this->storageDateTime((string)$proposal['breakEndTime']) : null);
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
			$newStart = $this->storageDateTime((string)$proposal['date']);
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

		if (isset($proposal['splitOverride']) && is_array($proposal['splitOverride'])) {
			$this->applySplitOverride($entry, $proposal['splitOverride']);
		}
	}

	/**
	 * Write a §9.7 split override into the entry's in-memory frozen context
	 * (`overrides.decision` / `overrides.worked`). The accounting result is NOT
	 * derived here — {@see recomputeSplitAccounting()} recomputes it from the
	 * final corrected times before the approval transaction persists, and a
	 * stale result left behind on an un-recomputed path is a defect the
	 * `verify-split-accounting` audit surfaces rather than a silent wrong
	 * number (so the stale `result`/`excluded_work_seconds` are deliberately
	 * left untouched until the engine re-runs).
	 *
	 * Normalisation: `worked` is stored as overlap-merged ISO pairs in the
	 * context timezone; decision `auto` clears `worked` (an explicit return to
	 * automatic decision carries no worked evidence); `force_continuous`
	 * stores none either (worked portions only exist on a split). Validation
	 * rejects the malformed/impossible inputs upstream — anything still
	 * malformed here throws `SPLIT_OVERRIDE_INVALID` (fail closed, never write
	 * garbage into the audit-grade context).
	 *
	 * @param array<string, mixed> $splitOverride
	 */
	private function applySplitOverride(TimeEntry $entry, array $splitOverride): void
	{
		$context = $entry->getWorkContext();
		if ($context === null) {
			return; // validateProposal rejects NULL/invalid contexts — defensive no-op
		}
		$decision = $splitOverride['decision'] ?? null;
		if (!\in_array($decision, [
			SplitServiceAccounting::DECISION_AUTO,
			SplitServiceAccounting::DECISION_FORCE_SPLIT,
			SplitServiceAccounting::DECISION_FORCE_CONTINUOUS,
		], true)) {
			throw new \InvalidArgumentException('SPLIT_OVERRIDE_INVALID');
		}

		$workedIso = [];
		if ($decision === SplitServiceAccounting::DECISION_FORCE_SPLIT) {
			$tz = $this->contextTimeZone($context);
			foreach (SplitServiceAccounting::union($this->workedEpochPairs($splitOverride['worked'] ?? [])) as [$ws, $we]) {
				$workedIso[] = [
					(new \DateTimeImmutable('@' . $ws))->setTimezone($tz)->format('c'),
					(new \DateTimeImmutable('@' . $we))->setTimezone($tz)->format('c'),
				];
			}
		}

		$overrides = is_array($context['overrides'] ?? null) ? $context['overrides'] : [];
		$overrides['decision'] = $decision;
		$overrides['worked'] = $workedIso;
		$context['overrides'] = $overrides;
		$entry->setWorkContextJson(json_encode($context, JSON_THROW_ON_ERROR));
	}

	/**
	 * Timezone of the frozen context (validated by the engine at resolve time;
	 * UTC fallback only keeps a corrupt context from fatalling before the
	 * engine gets to reject it).
	 */
	private function contextTimeZone(array $context): \DateTimeZone
	{
		try {
			return new \DateTimeZone((string)($context['timezone'] ?? 'UTC'));
		} catch (\Throwable) {
			return new \DateTimeZone('UTC');
		}
	}

	/**
	 * Normalise the raw `worked` proposal value into epoch pairs.
	 *
	 * @return list<array{0:int,1:int}>
	 * @throws \InvalidArgumentException 'SPLIT_OVERRIDE_INVALID' on any malformed item
	 */
	private function workedEpochPairs(mixed $raw): array
	{
		if (!\is_array($raw) || !array_is_list($raw)) {
			throw new \InvalidArgumentException('SPLIT_OVERRIDE_INVALID');
		}
		$pairs = [];
		foreach ($raw as $pair) {
			if (!\is_array($pair) || !array_is_list($pair) || \count($pair) !== 2) {
				throw new \InvalidArgumentException('SPLIT_OVERRIDE_INVALID');
			}
			$s = $this->isoToEpochOrNull($pair[0]);
			$e = $this->isoToEpochOrNull($pair[1]);
			if ($s === null || $e === null || $e <= $s) {
				throw new \InvalidArgumentException('SPLIT_OVERRIDE_INVALID');
			}
			$pairs[] = [$s, $e];
		}
		return $pairs;
	}

	private function isoToEpochOrNull(mixed $value): ?int
	{
		if (!\is_string($value) || preg_match(self::WORKED_ISO_PATTERN, $value) !== 1) {
			return null;
		}
		try {
			return (new \DateTimeImmutable($value))->getTimestamp();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Parse a proposal/original-snapshot datetime into the storage wall clock.
	 * Explicit-offset inputs ('...Z', '+02:00') keep their instant and shift to
	 * the configured storage zone — persisting their raw foreign wall clock
	 * would rehydrate shifted by the offset (audit: approved end_time landed
	 * one hour early). Naive inputs keep their wall clock in storage TZ.
	 */
	private function storageDateTime(string $value): \DateTime
	{
		$tz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
		return AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($value, $tz);
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
				$breakStart = $this->storageDateTime((string)$break[$startKey]);
				$breakEnd = $this->storageDateTime((string)$break[$endKey]);
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
		foreach (self::SERVER_OWNED_PROPOSAL_FIELDS as $field) {
			if (\array_key_exists($field, $proposal)) {
				return 'SERVER_OWNED_FIELD';
			}
		}

		$candidate = clone $entry;
		try {
			$this->applyProposal($candidate, $proposal);
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->ensureCandidateStatusForValidation($candidate);

		if (\array_key_exists('splitOverride', $proposal)) {
			if (!\is_array($proposal['splitOverride'])) {
				return 'SPLIT_OVERRIDE_INVALID';
			}
			$splitError = $this->validateSplitOverride($entry, $candidate, $proposal['splitOverride']);
			if ($splitError !== null) {
				return $splitError;
			}
		}

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
	 * Translate a {@see validateProposal()} failure into the exception that
	 * carries the right HTTP semantics: a stale split-decision basis is a
	 * conflict (409 SPLIT_OVERRIDE_STALE via the ConcurrentDecisionException
	 * seam); every other validation failure stays a bad request (400).
	 *
	 * @throws SplitOverrideStaleException|\InvalidArgumentException always
	 */
	private function failProposal(string $error): never
	{
		if ($error === SplitOverrideStaleException::CODE) {
			throw new SplitOverrideStaleException(
				$this->l10n->t('This time entry was changed in the meantime. Reload it and submit the correction again.')
			);
		}
		throw new \InvalidArgumentException($error);
	}

	/**
	 * §9.7 split-override proposal validation. Returns the normative error
	 * code or null. Runs against the post-proposal candidate so the corrected
	 * times (R, candidate gaps) are what the override is checked against.
	 *
	 * `SPLIT_OVERRIDE_NOT_APPLICABLE` — the entry has no usable frozen context
	 * (NULL column, undecodable JSON, or context the engine cannot resolve),
	 * or the day's geometry is DST-unresolvable: the override simply cannot
	 * apply to this entry.
	 *
	 * `SPLIT_OVERRIDE_INVALID` — malformed payload: unknown decision, worked
	 * intervals that are unparseable / reversed / outside R / outside every
	 * candidate gap / more than 8 (before or after overlap-merge), worked on a
	 * `force_continuous` decision, or a malformed baseEntryRevision token.
	 *
	 * `SPLIT_OVERRIDE_STALE` — well-formed baseEntryRevision that no longer
	 * matches the entry's current {@see TimeEntry::getContentRevision()}:
	 * the correction basis changed since the client read it (or since the
	 * proposal was submitted). A conflict, not a validation error — callers
	 * must map it to HTTP 409, same as the approval-time staleness below.
	 *
	 * Deliberately NOT checked here: whether a `force_split` still applies
	 * after the new times (corrected clock-out reaching the last candidate gap
	 * end). That is the approval-time `SPLIT_OVERRIDE_STALE` contract — the
	 * engine decides it on the final persisted times, and a premature reject
	 * would turn the spec's 409 into a 400.
	 *
	 * @param array<string, mixed> $splitOverride
	 */
	private function validateSplitOverride(TimeEntry $entry, TimeEntry $candidate, array $splitOverride): ?string
	{
		$context = $entry->getWorkContext();
		if ($context === null) {
			return 'SPLIT_OVERRIDE_NOT_APPLICABLE';
		}

		$decision = $splitOverride['decision'] ?? null;
		if (!\in_array($decision, [
			SplitServiceAccounting::DECISION_AUTO,
			SplitServiceAccounting::DECISION_FORCE_SPLIT,
			SplitServiceAccounting::DECISION_FORCE_CONTINUOUS,
		], true)) {
			return 'SPLIT_OVERRIDE_INVALID';
		}

		if (\array_key_exists('baseEntryRevision', $splitOverride)) {
			$rev = $splitOverride['baseEntryRevision'];
			if (!\is_string($rev) || preg_match(self::REVISION_TOKEN_PATTERN, $rev) !== 1) {
				return 'SPLIT_OVERRIDE_INVALID';
			}
			// Entry CAS (spec §9.7 / AC-5): the token must equal the CURRENT
			// content revision — the version of the correction basis the
			// client built its decision on. A mismatch means the entry was
			// written between read and submit (or between submit and
			// approval); the decision basis is stale → 409
			// SPLIT_OVERRIDE_STALE, never a silent apply. hash_equals keeps
			// the comparison constant-time even though the token is not a
			// secret.
			if (!hash_equals($entry->getContentRevision(), $rev)) {
				return 'SPLIT_OVERRIDE_STALE';
			}
		}

		$workedRaw = $splitOverride['worked'] ?? [];
		if ($workedRaw === null) {
			$workedRaw = [];
		}
		if ($decision === SplitServiceAccounting::DECISION_FORCE_CONTINUOUS && $workedRaw !== []) {
			return 'SPLIT_OVERRIDE_INVALID';
		}
		if ($workedRaw === []) {
			return null;
		}
		if (!\is_array($workedRaw) || \count($workedRaw) > SplitServiceAccounting::MAX_WORKED_OVERRIDES) {
			return 'SPLIT_OVERRIDE_INVALID';
		}
		try {
			$pairs = $this->workedEpochPairs($workedRaw);
		} catch (\InvalidArgumentException) {
			return 'SPLIT_OVERRIDE_INVALID';
		}
		if (\count(SplitServiceAccounting::union($pairs)) > SplitServiceAccounting::MAX_WORKED_OVERRIDES) {
			return 'SPLIT_OVERRIDE_INVALID';
		}

		$startTs = $candidate->getStartTime()?->getTimestamp();
		if ($startTs === null) {
			return 'SPLIT_OVERRIDE_INVALID';
		}
		$endTs = $candidate->getEndTime()?->getTimestamp();
		try {
			$gaps = SplitServiceAccounting::localGapEpochs($context, $startTs);
		} catch (\Throwable) {
			// Context decodes but the engine cannot normalise it — the
			// override cannot apply to this entry, period.
			return 'SPLIT_OVERRIDE_NOT_APPLICABLE';
		}
		if ($gaps === null) {
			return 'SPLIT_OVERRIDE_NOT_APPLICABLE';
		}
		// Candidate gaps = scheduled interruptions the entry did NOT start
		// inside (engine step 4: entry start at/before the gap start).
		$candidateGaps = [];
		foreach ($gaps as $gap) {
			if ($startTs <= $gap[0]) {
				$candidateGaps[] = $gap;
			}
		}
		foreach ($pairs as [$ws, $we]) {
			if ($ws < $startTs || ($endTs !== null && $we > $endTs)) {
				return 'SPLIT_OVERRIDE_INVALID';
			}
			if (SplitServiceAccounting::subtract([[$ws, $we]], $candidateGaps) !== []) {
				return 'SPLIT_OVERRIDE_INVALID';
			}
		}
		return null;
	}

	/**
	 * Recompute the frozen-context accounting result for entries that carry a
	 * work context. The sibling-owned SplitServiceAccountingService mutates
	 * the entity in-memory (context.result + excluded_work_seconds); the
	 * caller's persist writes them in the same update. NULL-context (legacy)
	 * entries keep the byte-identical legacy path.
	 *
	 * @throws SplitOverrideStaleException when the engine reports the applied
	 *         override inapplicable on the corrected times (§9.7 → 409
	 *         SPLIT_OVERRIDE_STALE through the ConcurrentDecisionException seam).
	 */
	protected function recomputeSplitAccounting(TimeEntry $entry): void
	{
		if ($entry->getWorkContext() === null) {
			return;
		}
		$this->runSplitRecompute($entry);
		$result = $entry->getAccountingResult();
		if (\is_array($result) && ($result['error'] ?? null) === SplitServiceAccounting::ERR_OVERRIDE_NOT_APPLICABLE) {
			throw new SplitOverrideStaleException(
				$this->l10n->t('The approved split decision can no longer be applied to the corrected times. Reload the entry and submit a new correction.')
			);
		}
	}

	/**
	 * The engine invocation itself — the sibling-owned
	 * SplitServiceAccountingService mutates context.result +
	 * excluded_work_seconds in-memory. Kept as a protected seam so tests can
	 * substitute the engine while still exercising the stale-check above.
	 */
	protected function runSplitRecompute(TimeEntry $entry): void
	{
		$service = $this->splitServiceAccounting ?? \OCP\Server::get(SplitServiceAccountingService::class);
		$service->recomputeAccounting($entry);
	}

	/**
	 * §9.7 audit hook: when a proposal carries a split override, the
	 * justification record must keep the ORIGINAL frozen context so the
	 * approved decision can be reconstructed ("proposal stores original
	 * context"). Captured before applyProposal mutates the in-memory context.
	 *
	 * @param array<string, mixed> $justificationData
	 * @param array<string, mixed> $proposal
	 */
	private function stashOriginalWorkContext(array &$justificationData, TimeEntry $entry, array $proposal): void
	{
		if (!\array_key_exists('splitOverride', $proposal)) {
			return;
		}
		$raw = $entry->getWorkContextJson();
		if ($raw === null || $raw === '') {
			return;
		}
		$decoded = json_decode($raw, true);
		$justificationData['original_work_context'] = \is_array($decoded) ? $decoded : $raw;
	}

	/**
	 * Override summary for the service-owned audit payload — null when the
	 * entry carries no explicit (non-auto) decision.
	 *
	 * @return array{decision: string, worked: list<array{0:string,1:string}>}|null
	 */
	private function splitOverrideAuditInfo(TimeEntry $entry): ?array
	{
		$overrides = $entry->getWorkContext()['overrides'] ?? null;
		if (!\is_array($overrides)) {
			return null;
		}
		$decision = $overrides['decision'] ?? null;
		if (!\in_array($decision, [
			SplitServiceAccounting::DECISION_FORCE_SPLIT,
			SplitServiceAccounting::DECISION_FORCE_CONTINUOUS,
		], true)) {
			return null;
		}
		return [
			'decision' => $decision,
			'worked' => \is_array($overrides['worked'] ?? null) ? $overrides['worked'] : [],
		];
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

		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

		$error = $this->validateProposal($entry, $adjustedProposal, $managerId);
		if ($error !== null) {
			$this->failProposal($error);
		}
		$this->stashOriginalWorkContext($justificationData, $entry, $adjustedProposal);
		$this->applyProposal($entry, $adjustedProposal);
		$this->applyComplianceAdjustments($entry);
		$this->assertCompletableDecision($entry, $justificationData);
		$this->recomputeSplitAccounting($entry);

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

		// The proposal may shift the row across a month boundary — lock both sides.
		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry, $managerId): TimeEntry {
			$updated = $this->persistPendingDecision($entry);
			$updated = $this->recomputePaidBreaks($updated);
			$this->runComplianceIfEnabled($updated);
			$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

			return $updated;
		});
	}

	public function approve(TimeEntry $entry, string $managerId, ?string $comment = null): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		if (!is_array($justificationData)) {
			$justificationData = [];
		}

		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

		$proposal = $justificationData['proposed'] ?? null;
		if (is_array($proposal) && $proposal !== []) {
			$error = $this->validateProposal($entry, $proposal);
			if ($error !== null) {
				$this->failProposal($error);
			}
			$this->stashOriginalWorkContext($justificationData, $entry, $proposal);
			$this->applyProposal($entry, $proposal);
			$this->applyComplianceAdjustments($entry);
		}
		$this->assertCompletableDecision($entry, $justificationData);
		$this->recomputeSplitAccounting($entry);

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

		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry, $managerId): TimeEntry {
			$updated = $this->persistPendingDecision($entry);
			$updated = $this->recomputePaidBreaks($updated);
			$this->runComplianceIfEnabled($updated);
			$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

			return $updated;
		});
	}

	public function autoApprove(TimeEntry $entry): TimeEntry
	{
		$justificationData = json_decode($entry->getJustification() ?? '{}', true);
		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

		if (is_array($justificationData)) {
			$proposal = $justificationData['proposed'] ?? null;
			if (is_array($proposal) && $proposal !== []) {
				// Defensive: same fail-closed labour-law gate as approve()/manager writes,
				// even if a future caller forgets to validate first.
				$error = $this->validateProposal($entry, $proposal);
				if ($error !== null) {
					$this->failProposal($error);
				}
				$this->stashOriginalWorkContext($justificationData, $entry, $proposal);
				$this->applyProposal($entry, $proposal);
				$this->applyComplianceAdjustments($entry);
			}
		}
		if (is_array($justificationData)) {
			$this->assertCompletableDecision($entry, $justificationData);
		}
		$this->recomputeSplitAccounting($entry);

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

		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		$updated = $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry): TimeEntry {
			$updated = $this->persistPendingDecision($entry);
			$updated = $this->recomputePaidBreaks($updated);
			$this->runComplianceIfEnabled($updated);
			return $updated;
		});

		// B1-MH-05: audit row mirrors absence auto-approval — reason + which
		// approvers the predicate counted (method_exists: upgrade repair paths
		// may hold a stale TeamResolverService).
		try {
			$auditPayload = array_merge($updated->getSummary(), [
				'auto_approve' => [
					'reason' => 'no_assignable_approver',
					'predicate' => $this->approverPredicateSnapshotSafe($updated->getUserId()),
				],
			]);
			$splitOverride = $this->splitOverrideAuditInfo($updated);
			if ($splitOverride !== null) {
				$auditPayload['split_override'] = $splitOverride;
			}
			$this->auditLogMapper->logAction(
				$updated->getUserId(),
				'time_entry_auto_approved',
				'time_entry',
				$updated->getId(),
				null,
				$auditPayload,
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

		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

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

			$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
			return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry): TimeEntry {
				$rejected = $this->persistPendingDecision($entry);
				// Status flipped to REJECTED — its breaks leave the day pool.
				return $this->recomputePaidBreaks($rejected);
			});
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

		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry): TimeEntry {
			$rejected = $this->persistPendingDecision($entry);
			// Restored snapshot changed the recorded breaks — rebalance the pool.
			return $this->recomputePaidBreaks($rejected);
		});
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

		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

		$originalData = is_array($justificationData['original'] ?? null) ? $justificationData['original'] : [];
		$this->restoreOriginalSnapshot($entry, $originalData);
		$entry->setApprovedByUserId(null);
		$entry->setApprovedAt(null);
		$entry->setJustification(null);
		$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));

		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry): TimeEntry {
			$restored = $this->persistPendingDecision($entry);
			// Restored snapshot changed the recorded breaks — rebalance the pool.
			return $this->recomputePaidBreaks($restored);
		});
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
		$beforeStart = $entry->getStartTime();
		$beforeEnd = $entry->getEndTime();

		$error = $this->validateProposal($entry, $proposal, $managerId);
		if ($error !== null) {
			$this->failProposal($error);
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

		// §9.7: capture the original frozen context BEFORE the override writes
		// into it — the manager-correction justification keeps it for audit.
		$originalWorkContext = null;
		if (\array_key_exists('splitOverride', $proposal)) {
			$raw = $entry->getWorkContextJson();
			if ($raw !== null && $raw !== '') {
				$decoded = json_decode($raw, true);
				$originalWorkContext = \is_array($decoded) ? $decoded : $raw;
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
		$this->recomputeSplitAccounting($entry);
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
		if (\is_array($proposal['splitOverride'] ?? null)) {
			$justification['split_override'] = $proposal['splitOverride'];
			if ($originalWorkContext !== null) {
				$justification['original_work_context'] = $originalWorkContext;
			}
		}
		if ($previousJustification !== null) {
			$justification['previous_justification'] = $previousJustification;
		}
		$entry->setJustification(json_encode($justification, JSON_THROW_ON_ERROR));

		$mcKeys = $this->entryWriteMonthClosureKeys($entry, $beforeStart, $beforeEnd);
		return $this->writeEntryUnderMonthClosureLocks($entry, $beforeStart, $beforeEnd, $mcKeys, function () use ($entry, $managerId): TimeEntry {
			$updated = $this->timeEntryMapper->update($entry);
			$updated = $this->recomputePaidBreaks($updated);
			$this->runComplianceIfEnabled($updated);
			$updated->setBillingSyncWarning($this->syncProjectCheckBilling($updated, $managerId));

			return $updated;
		});
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
			$this->failProposal($error);
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
		$this->recomputeSplitAccounting($entry);
		$entry->setApprovedByUserId($managerId);
		$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$entry->setApprovedAt(clone $nowAt);
		$entry->setUpdatedAt(clone $nowAt);

		// New row — its own [start, end] months are the full write set.
		$mcKeys = $this->entryWriteMonthClosureKeys($entry, null, null);
		return $this->writeEntryUnderMonthClosureLocks($entry, null, null, $mcKeys, function () use ($entry, $managerId): TimeEntry {
			$inserted = $this->timeEntryMapper->insert($entry);
			$inserted = $this->recomputePaidBreaks($inserted);
			$this->runComplianceIfEnabled($inserted);
			$inserted->setBillingSyncWarning($this->syncProjectCheckBilling($inserted, $managerId));

			return $inserted;
		});
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
			$entry->setStartTime($this->storageDateTime((string)$originalData['startTime']));
		} elseif (isset($originalData['date'])) {
			$entry->setStartTime($this->storageDateTime((string)$originalData['date']));
		}
		if (array_key_exists('endTime', $originalData)) {
			$rawEnd = $originalData['endTime'];
			$entry->setEndTime(is_string($rawEnd) && $rawEnd !== '' ? $this->storageDateTime($rawEnd) : null);
		} elseif (isset($originalData['hours']) && $entry->getStartTime()) {
			$endTime = clone $entry->getStartTime();
			$endTime->modify('+' . (int)round((float)$originalData['hours'] * 3600) . ' seconds');
			$entry->setEndTime($endTime);
		}
		if (array_key_exists('breakStartTime', $originalData)) {
			$raw = $originalData['breakStartTime'];
			$entry->setBreakStartTime(is_string($raw) && $raw !== '' ? $this->storageDateTime($raw) : null);
		} else {
			$entry->setBreakStartTime(null);
		}
		if (array_key_exists('breakEndTime', $originalData)) {
			$raw = $originalData['breakEndTime'];
			$entry->setBreakEndTime(is_string($raw) && $raw !== '' ? $this->storageDateTime($raw) : null);
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
