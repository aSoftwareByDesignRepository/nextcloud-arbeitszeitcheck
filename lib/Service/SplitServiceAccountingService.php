<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\SplitServiceAccounting;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use Psr\Log\LoggerInterface;

/**
 * Split-service days — the impure orchestration layer around the pure
 * {@see SplitServiceAccounting} engine (SPEC §9.5/§9.6/§9.10).
 *
 * Two responsibilities:
 *
 *  1. {@see pinContextForEntry()} freezes the audit context at clock-in /
 *     manual-entry creation / offline replay, evaluated against the user's
 *     effective working-time model on the entry's local start date. Only
 *     v2 models with active `split_service` options produce a context;
 *     everything else stays NULL (byte-identical legacy path).
 *
 *  2. {@see recomputeAccounting()} derives the `result` sub-document and
 *     `excluded_work_seconds` from raw stamps + frozen context + engine —
 *     never from the current (mutable) model. It additionally evaluates the
 *     interruption break-cover rule (§9.6 step 10b) using day-level data
 *     from {@see BreakPolicyResolver} and records `break_handling`.
 *
 * The service writes entities only in memory (context JSON + excluded
 * seconds + trimmed breaks JSON); callers persist inside their existing
 * transactions. No direct SQL — the only mapper reads are the effective
 * model assignment (pin) and the same-day entry list (cover check), both
 * skipped entirely for NULL-context legacy entries.
 *
 * Failure posture (§12.2): an engine/defect failure never blocks the
 * completed stamp and never silently falls back to legacy accounting for a
 * non-NULL context — the entry is written as `state=review`, unsplit,
 * `break_handling=not_applicable`, `excluded=0`, which is a valid
 * conservative result that stays closable.
 */
class SplitServiceAccountingService {

	/**
	 * Hard cap on the stored context document — a pathological schedule or
	 * override list must never produce an unbounded `work_context_json`.
	 */
	private const MAX_CONTEXT_BYTES = 16384;

	/** Marker recorded on review results when no break placement fits a segment. */
	public const ERR_BREAK_PLACEMENT_IMPOSSIBLE = 'SPLIT_BREAK_PLACEMENT_IMPOSSIBLE';

	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private TimeEntryMapper $timeEntryMapper;
	private BreakPolicyResolver $breakPolicyResolver;
	private LaborLawProfileFactory $lawProfileFactory;
	private TimeZoneService $timeZoneService;
	private LoggerInterface $logger;

	public function __construct(
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		TimeEntryMapper $timeEntryMapper,
		BreakPolicyResolver $breakPolicyResolver,
		LaborLawProfileFactory $lawProfileFactory,
		TimeZoneService $timeZoneService,
		LoggerInterface $logger,
	) {
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->breakPolicyResolver = $breakPolicyResolver;
		$this->lawProfileFactory = $lawProfileFactory;
		$this->timeZoneService = $timeZoneService;
		$this->logger = $logger;
	}

	// ------------------------------------------------------------------ //
	// Context pinning (§9.5)                                              //
	// ------------------------------------------------------------------ //

	/**
	 * Freeze the split-service context on a not-yet-inserted entry. Runs
	 * inside the caller's stamp transaction, before `insert()`.
	 *
	 * Pins iff the user's effective assignment on the entry's local start
	 * date resolves to a v2 model with active `split_service` behaviour.
	 * Everything else leaves `work_context_json` NULL — including v1 models,
	 * missing assignments and non-split v2 models. Entries that already
	 * carry a context are never re-pinned (idempotent for replays).
	 *
	 * Throws are deliberately NOT swallowed: callers wrap this best-effort
	 * so a defect never rejects a legitimate stamp.
	 */
	public function pinContextForEntry(TimeEntry $entry, string $userId): void {
		if ($entry->getWorkContextJson() !== null) {
			return;
		}
		$start = $entry->getStartTime();
		if ($start === null) {
			return;
		}

		$tz = $this->timeZoneService->storageTimeZone();
		$startLocal = (new \DateTimeImmutable('@' . $start->getTimestamp()))->setTimezone($tz);
		$assignment = $this->userWorkingTimeModelMapper->findByUserAndDate(
			$userId,
			\DateTime::createFromImmutable($startLocal)
		);
		if ($assignment === null) {
			return;
		}
		$model = $this->workingTimeModelMapper->find((int)$assignment->getWorkingTimeModelId());
		$context = $this->buildContext($model, $startLocal, $tz->getName());
		if ($context === null) {
			return;
		}

		$json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json)) {
			$this->logger->error('Split context could not be JSON-encoded — entry stays on the legacy path', [
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
			]);
			return;
		}
		if (strlen($json) > self::MAX_CONTEXT_BYTES) {
			$this->logger->error('Split context exceeds the size cap — entry stays on the legacy path', [
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
				'bytes' => strlen($json),
			]);
			return;
		}
		$entry->setWorkContextJson($json);
		$entry->setExcludedWorkSeconds(0);
	}

	/**
	 * Build the pin-time context document for a model + local start instant,
	 * or null when the model does not pin (not v2 or no active split-service
	 * options). Public so preview/verify surfaces can render the same
	 * document the pin would have frozen.
	 *
	 * @return array<string,mixed>|null
	 */
	public function buildContext(WorkingTimeModel $model, \DateTimeInterface $start, string $timezone): ?array {
		$breakRules = $model->getBreakRulesArray();
		$schedule = WeekdaySchedule::tryFromBreakRules(is_array($breakRules) ? $breakRules : null);
		if ($schedule === null
			|| $schedule->getVersion() !== WeekdaySchedule::VERSION_V2
			|| !$schedule->hasActiveSplitService()
		) {
			return null;
		}
		try {
			new \DateTimeZone($timezone);
		} catch (\Throwable) {
			return null;
		}

		$week = [];
		foreach (WeekdaySchedule::DAYS as $day) {
			$week[$day] = $this->blocksToHm($schedule->blocksForWeekday($day));
		}
		$dayKey = WeekdaySchedule::DAYS[max(0, min(6, ((int)$start->format('N')) - 1))];
		$split = $schedule->getSplitService();
		$storedSchedule = is_array($breakRules[WeekdaySchedule::KEY] ?? null)
			? $breakRules[WeekdaySchedule::KEY]
			: null;

		return [
			'v' => 1,
			'model_id' => (int)$model->getId(),
			'schedule_revision' => WeekdaySchedule::revisionOf($storedSchedule),
			'timezone' => $timezone,
			'day' => ['blocks' => $week[$dayKey]],
			'week' => $week,
			'split' => [
				'automatic' => $split['automatic'],
				'cutoff' => $split['cutoff'],
				'break_cover' => $split['breakCover'],
			],
			'overrides' => ['decision' => SplitServiceAccounting::DECISION_AUTO, 'worked' => []],
			'result' => [
				'state' => SplitServiceAccounting::STATE_ACTIVE,
				'split' => false,
				'reason' => null,
				'excluded' => [],
				'segments' => [],
				'engine' => SplitServiceAccounting::ENGINE_VERSION,
			],
		];
	}

	// ------------------------------------------------------------------ //
	// Accounting recompute (§9.6)                                         //
	// ------------------------------------------------------------------ //

	/**
	 * Recompute the entry's accounting result from raw stamps + frozen
	 * context and write it back into the context (in memory — the caller
	 * persists inside its transaction). Also evaluates the interruption
	 * break-cover rule and records `result.break_handling`.
	 *
	 * Contract:
	 *  - NULL-context (legacy) and corrupt-but-decodable-defect contexts do
	 *    no mapper work at all; a non-NULL column that fails to decode is
	 *    logged as SPLIT_CONTEXT_INVALID and left untouched (FM-11).
	 *  - Open entries (no end) are preview-only per spec — no writes.
	 *  - Automatic break portions on this entry are derived data: they never
	 *    count as evidence in the coverage check, and they are dropped when
	 *    the verdict is `covered_by_interruption` (§9.6 step 12). Manual
	 *    portions are never touched. Same inputs → byte-identical result
	 *    (see contextResultHash in tests).
	 *  - The caller MUST then run the standard auto-break flow
	 *    (`calculateAndSetAutomaticBreak`) unless the returned
	 *    `break_handling` is `covered_by_interruption` — the skip is the
	 *    caller's single responsibility.
	 *  - Any engine/coverage failure writes the conservative review result
	 *    (unsplit, excluded=0) — never a half-written context, never a
	 *    silent legacy fallback, never an exception.
	 *
	 * @return array<string,mixed>|null the stored `result` sub-document, or
	 *         null for legacy/invalid/open entries
	 */
	public function recomputeAccounting(TimeEntry $entry): ?array {
		if ($entry->hasInvalidWorkContext()) {
			$this->logger->error('SPLIT_CONTEXT_INVALID: non-NULL work_context_json does not decode — conservative read path retained', [
				'user_id' => $entry->getUserId(),
				'entry_id' => $entry->getId(),
			]);
			return null;
		}
		$context = $entry->getWorkContext();
		if ($context === null) {
			return null;
		}
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start === null || $end === null) {
			return null; // spec step 1: open entries are preview-only — no writes
		}

		$userId = (string)$entry->getUserId();
		$startTs = $start->getTimestamp();
		$endTs = $end->getTimestamp();

		try {
			$engine = SplitServiceAccounting::resolveSplit($context, $startTs, $endTs);
		} catch (\Throwable $e) {
			$this->logger->error('Split accounting failed; conservative review result written', [
				'exception' => $e,
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
			]);
			return $this->writeReviewFallback($entry, $context, null);
		}

		if (($engine['error'] ?? null) !== null) {
			// A stored override became inapplicable (e.g. stamp times moved
			// under it) — never apply blindly: closable review, error kept
			// for audit, standard (unsplit) accounting retained.
			$this->logger->warning('Split override not applicable at recompute; entry flagged for review', [
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
				'error' => $engine['error'],
			]);
			return $this->writeReviewFallback($entry, $context, (string)$engine['error']);
		}

		// Persist the fresh terminal result FIRST (in memory): the day-level
		// helpers used by the cover check — own-work spans and effective
		// breaks — read this entry's stored segments/excluded, so they must
		// see the result currently being computed, not the stale `active` one.
		$result = [
			'state' => $engine['state'],
			'split' => (bool)$engine['split'],
			'reason' => $engine['reason'],
			'excluded' => $engine['excluded'],
			'segments' => $engine['segments'],
			'break_handling' => SplitServiceAccounting::BREAK_STANDARD,
			'engine' => $engine['engine'],
		];
		$this->storeResult($entry, $context, $result);
		$entry->setExcludedWorkSeconds((int)$engine['excludedSeconds']);

		$breakHandling = $this->evaluateBreakHandling($entry, $context, $engine, $userId, $startTs, $endTs);
		if ($breakHandling !== $result['break_handling']) {
			$result['break_handling'] = $breakHandling;
			$this->storeResult($entry, $context, $result);
		}
		return $result;
	}

	/**
	 * Flag a context-bearing entry for review when the masked auto-break
	 * placement found no segment that can host the required break (§9.6
	 * step 10a: "if placement impossible → state review"). The conservative
	 * review result accounts the entry unsplit — valid, closable, and
	 * visibly wrong so HR corrects it — rather than placing a break into an
	 * excluded interruption.
	 */
	public function markReviewForFailedPlacement(TimeEntry $entry): void {
		$context = $entry->getWorkContext();
		if ($context === null) {
			return;
		}
		$this->logger->warning('No break placement inside the split segments; entry flagged for review', [
			'user_id' => $entry->getUserId(),
			'entry_id' => $entry->getId(),
		]);
		$this->writeReviewFallback($entry, $context, self::ERR_BREAK_PLACEMENT_IMPOSSIBLE);
	}

	// ------------------------------------------------------------------ //
	// Break-cover evaluation (§9.6 step 10b)                              //
	// ------------------------------------------------------------------ //

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $engine   engine result incl. *_Epoch internals
	 */
	private function evaluateBreakHandling(
		TimeEntry $entry,
		array $context,
		array $engine,
		string $userId,
		int $startTs,
		int $endTs,
	): string {
		if (($engine['state'] ?? null) === SplitServiceAccounting::STATE_REVIEW) {
			// The entry accounts unsplit and HR reviews it anyway — the
			// standard flow still runs, but "not_applicable" is the honest
			// record: no coverage evaluation happened.
			return SplitServiceAccounting::BREAK_NOT_APPLICABLE;
		}
		if (($engine['split'] ?? false) !== true) {
			return SplitServiceAccounting::BREAK_STANDARD;
		}
		$breakCover = (($context['split'] ?? null)['break_cover'] ?? false) === true;
		if (!$breakCover) {
			return SplitServiceAccounting::BREAK_STANDARD;
		}
		try {
			$verdict = $this->coverageCheck($entry, $context, $userId, $startTs, $endTs);
		} catch (\Throwable $e) {
			// Coverage is an optimisation on top of an already-valid split:
			// keep the computed split and let the standard masked flow run —
			// never discard the split because a day-level lookup failed.
			$this->logger->error('Break-cover evaluation failed; standard masked placement applies', [
				'exception' => $e,
				'user_id' => $userId,
				'entry_id' => $entry->getId(),
			]);
			return SplitServiceAccounting::BREAK_STANDARD;
		}
		if ($verdict === SplitServiceAccounting::BREAK_COVERED_BY_INTERRUPTION) {
			// §9.6 step 10b/12: covered → no automatic insertion and stale
			// automatic portions on THIS entry are removed (derived data).
			// Manual portions are never touched. Only in the covered branch —
			// otherwise the recorded evidence stays for the standard flow,
			// keeping re-runs byte-identical.
			$this->dropAutomaticBreakPortions($entry);
		}
		return $verdict;
	}

	/**
	 * covered ⇔ interruption portions + effective recorded breaks satisfy
	 * the required break r AND every maximal own-work stretch between the
	 * counted portions stays within the tightest statutory limit L.
	 *
	 * @param array<string,mixed> $context
	 */
	private function coverageCheck(
		TimeEntry $entry,
		array $context,
		string $userId,
		int $startTs,
		int $endTs,
	): string {
		$resolver = $this->breakPolicyResolver;
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($entry->getStartTime());
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();

		$dayEntries = $this->dayEntriesWithLive($entry, $dayStart, $dayEnd);

		// [first start, last end) of the day, clipped to the day window.
		$firstStart = $startTs;
		$lastEnd = $endTs;
		foreach ($dayEntries as $other) {
			$s = $other->getStartTime()?->getTimestamp();
			$e = $other->getEndTime()?->getTimestamp();
			if ($s === null || $e === null) {
				continue;
			}
			$firstStart = min($firstStart, max($s, $dayStartTs));
			$lastEnd = max($lastEnd, min($e, $dayEndTs));
		}
		if ($lastEnd <= $firstStart) {
			return SplitServiceAccounting::BREAK_NOT_APPLICABLE;
		}

		// W_day: union over the day's entries of (R_i \ X_i) — the context-aware
		// resolver already clips v2 entries to their stored segments.
		$wDay = $resolver->dayWork($userId, $dayStart, $dayEnd, $entry, $entry->getId());
		$presenceSeconds = SplitServiceAccounting::totalSeconds($wDay);

		// I_day = (union of the day's scheduled gaps) ∩ [firstStart,lastEnd) \ W_day
		$gaps = SplitServiceAccounting::localGapEpochs($context, $startTs);
		if ($gaps === null) {
			return SplitServiceAccounting::BREAK_NOT_APPLICABLE; // DST — review path already decided
		}
		$iDay = SplitServiceAccounting::subtract(
			SplitServiceAccounting::intersect([[$firstStart, $lastEnd]], $gaps),
			$wDay
		);

		// Be_day: recorded break portions, effective part only — the resolver
		// already subtracts each v2 entry's excluded interruption. The LIVE
		// entry's own automatic portions are derived data and never evidence:
		// counting them would let a stale auto break self-justify the covered
		// state that removes it (oscillation instead of idempotency).
		$bePortionsMinutes = [];
		$beIntervals = [];
		foreach ($dayEntries as $other) {
			foreach ($resolver->entryBreakIntervals($other, $dayStartTs, $dayEndTs) as $piece) {
				if ($other === $entry && $piece['automatic']) {
					continue;
				}
				$bePortionsMinutes[] = intdiv($piece['end'] - $piece['start'], 60);
				$beIntervals[] = [$piece['start'], $piece['end']];
			}
		}
		$beSeconds = SplitServiceAccounting::totalSeconds($beIntervals);

		$required = $resolver->minimalConsistentBreakSeconds(
			$presenceSeconds,
			$beSeconds,
			$resolver->deductionModeForUser($userId),
			$userId
		);

		// Counted portions (>= floor) split the own-work day; every maximal
		// stretch of work between them must stay under L. Smaller
		// interruptions and sub-floor breaks do NOT reset the stretch clock —
		// they are not legal breaks.
		$floorMinutes = $resolver->effectiveFloor($userId);
		$floorSeconds = $floorMinutes * 60;
		$countedPortions = [];
		foreach ($iDay as [$a, $b]) {
			if ($b - $a >= $floorSeconds) {
				$countedPortions[] = [$a, $b];
			}
		}
		foreach ($beIntervals as [$a, $b]) {
			if ($b - $a >= $floorSeconds) {
				$countedPortions[] = [$a, $b];
			}
		}
		$stretches = [];
		foreach (SplitServiceAccounting::subtract([[$firstStart, $lastEnd]], $countedPortions) as [$a, $b]) {
			$stretches[] = $b - $a;
		}

		$maxStretch = null;
		foreach ($resolver->effectiveTiers($userId) as $tier) {
			$threshold = (int)round(((float)$tier['afterHours']) * 3600);
			$maxStretch = $maxStretch === null ? $threshold : min($maxStretch, $threshold);
		}
		$patterns = $this->lawProfileFactory->getProfile($userId)->allowedBreakSplitPatterns;

		$iDayPortionsMinutes = [];
		foreach ($iDay as [$a, $b]) {
			$iDayPortionsMinutes[] = intdiv($b - $a, 60);
		}

		return SplitServiceAccounting::coverageCheck(
			intdiv((int)$required['total_seconds'], 60),
			$iDayPortionsMinutes,
			$bePortionsMinutes,
			$stretches,
			is_array($patterns) ? $patterns : null,
			$floorMinutes,
			$maxStretch,
		);
	}

	// ------------------------------------------------------------------ //
	// Internals                                                          //
	// ------------------------------------------------------------------ //

	/**
	 * The day's overlapping entries with the (possibly unsaved) live entry
	 * substituted in place of its persisted row so in-memory changes count.
	 *
	 * @return list<TimeEntry>
	 */
	private function dayEntriesWithLive(TimeEntry $live, \DateTime $dayStart, \DateTime $dayEnd): array {
		$entries = $this->timeEntryMapper->findOverlapping(
			(string)$live->getUserId(),
			$dayStart,
			$dayEnd
		) ?? [];
		$out = [];
		$replaced = false;
		$liveId = $live->getId();
		foreach ($entries as $other) {
			if ($liveId !== null && $other->getId() === $liveId) {
				$out[] = $live;
				$replaced = true;
				continue;
			}
			$out[] = $other;
		}
		if (!$replaced) {
			$out[] = $live;
		}
		return $out;
	}

	/**
	 * Remove `automatic:true` break portions from the entry's breaks JSON —
	 * derived data, rebuilt every run. Manual portions are never touched
	 * and a document without automatic portions is preserved byte-for-byte.
	 */
	private function dropAutomaticBreakPortions(TimeEntry $entry): void {
		$json = $entry->getBreaks();
		if (!is_string($json) || $json === '') {
			return;
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return;
		}
		$kept = [];
		foreach ($decoded as $portion) {
			if (is_array($portion) && ($portion['automatic'] ?? false) === true) {
				continue;
			}
			$kept[] = $portion;
		}
		if (count($kept) === count($decoded)) {
			return;
		}
		$entry->setBreaks($kept === [] ? null : json_encode(array_values($kept)));
	}

	/**
	 * Conservative closable result: unsplit, nothing excluded, review state.
	 * `segments` mirrors R so downstream segment-aware reads still see a
	 * consistent document.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed> the stored result
	 */
	private function writeReviewFallback(TimeEntry $entry, array $context, ?string $error): array {
		$segments = [];
		$tzName = (string)($context['timezone'] ?? '');
		try {
			$tz = new \DateTimeZone($tzName);
		} catch (\Throwable) {
			$tz = $this->timeZoneService->storageTimeZone();
		}
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start !== null && $end !== null && $end->getTimestamp() > $start->getTimestamp()) {
			$segments = [[
				(new \DateTimeImmutable('@' . $start->getTimestamp()))->setTimezone($tz)->format('c'),
				(new \DateTimeImmutable('@' . $end->getTimestamp()))->setTimezone($tz)->format('c'),
			]];
		}
		$result = [
			'state' => SplitServiceAccounting::STATE_REVIEW,
			'split' => false,
			'reason' => null,
			'excluded' => [],
			'segments' => $segments,
			'break_handling' => SplitServiceAccounting::BREAK_NOT_APPLICABLE,
			'engine' => SplitServiceAccounting::ENGINE_VERSION,
		];
		if ($error !== null) {
			$result['error'] = $error;
		}
		$this->storeResult($entry, $context, $result);
		$entry->setExcludedWorkSeconds(0);
		return $result;
	}

	/**
	 * Persist the recomputed `result` sub-document into the context JSON.
	 * A JSON-encode failure leaves the previous context intact — a
	 * half-written context is never an option.
	 *
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $result
	 */
	private function storeResult(TimeEntry $entry, array $context, array $result): void {
		$context['result'] = $result;
		$json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json)) {
			$this->logger->error('Split context re-encode failed; previous context kept', [
				'user_id' => $entry->getUserId(),
				'entry_id' => $entry->getId(),
			]);
			return;
		}
		$entry->setWorkContextJson($json);
	}

	/**
	 * Minute-of-day block pairs to HH:MM strings (context storage shape).
	 *
	 * @param list<array{0:int,1:int}> $blocks
	 * @return list<array{0:string,1:string}>
	 */
	private function blocksToHm(array $blocks): array {
		$out = [];
		foreach ($blocks as [$s, $e]) {
			$out[] = [self::minutesToHm($s), self::minutesToHm($e)];
		}
		return $out;
	}

	private static function minutesToHm(int $minutes): string {
		return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
	}
}
