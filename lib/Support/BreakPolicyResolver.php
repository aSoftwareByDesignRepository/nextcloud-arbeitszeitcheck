<?php

declare(strict_types=1);

/**
 * Single source of truth for effective break policy (law ∪ organisation ∪
 * working-time model) — tiers, floors, break windows, presence windows and
 * exemption ranges.
 *
 * Merge contract (SPEC §9):
 *  - effectiveRequired(h) = max(law.required(h), org.required(h), model.required(h))
 *  - effectiveFloor       = max(law.minBreakMinutes, org.min_break_minutes ?? 0,
 *                               model.min_break_minutes ?? 0)
 *  - Statutory split patterns stay authoritative; org/model can only tighten.
 *  - Threshold comparisons run on whole seconds (exclusive ">"), never on
 *    float hours (E-32).
 *  - Corrupt `break_policy_json` fragments are dropped with a logged warning —
 *    write/stamp paths must fail open to law-only behaviour (E-26).
 *
 * The resolver must not depend on TimeTrackingService or ComplianceService
 * (those are the two enforcement funnels that consume it).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IL10N;

final class BreakPolicyResolver
{
	public const POLICY_VERSION = 1;

	public const MAX_TIERS = 8;
	public const MAX_WINDOWS = 4;
	public const MAX_PRESENCE_EXEMPTIONS = 8;
	/** Generous cap: up to four presence slots on each of seven weekdays. */
	public const MAX_PRESENCE_WINDOWS = 28;
	/** Write-boundary cap (spec): tolerant stored reads stay looser. */
	public const MAX_PRESENCE_WINDOWS_WRITE = 8;
	/** Overnight break windows may wrap at most 8 hours past midnight. */
	public const MAX_WINDOW_MINUTES = 480;

	public const MODE_PLACEMENT = 'placement';
	public const MODE_REQUIRED = 'required';

	public const SOURCE_LAW = 'law';
	public const SOURCE_ORG = 'org';
	public const SOURCE_MODEL = 'model';

	/** Admin-request field names → stable field-error keys. */
	public const FIELD_ENFORCED = 'breakPolicyEnforced';
	public const FIELD_MIN_BREAK = 'breakPolicyMinBreakMinutes';
	public const FIELD_TIERS = 'breakPolicyTiers';
	public const FIELD_WINDOWS = 'breakPolicyWindows';
	public const FIELD_EXEMPTIONS = 'presenceExemptions';

	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

	private IConfig $config;
	private LaborLawProfileFactory $lawProfileFactory;
	private UserSettingsMapper $userSettingsMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private TimeEntryMapper $timeEntryMapper;
	private TimeZoneService $timeZoneService;
	private IL10N $l10n;

	/** @var array<string, mixed>|null normalised org policy (null = not yet read) */
	private ?array $orgConfigCache = null;

	/** @var array<int, array<string, mixed>> modelId → normalised break_rules */
	private array $modelRulesCache = [];

	/** @var array<string, WorkingTimeModel|null> uid → current model */
	private array $userModelCache = [];

	/** @var array<string, list<array{0:int,1:int}>> "uid|Y-m-d" → merged work intervals */
	private array $dayWorkCache = [];

	/** @var list<string>|null active labour-law countries */
	private ?array $activeCountriesCache = null;

	/** True once orgConfig() had to drop malformed stored fragments (occ path). */
	private bool $orgConfigMalformed = false;

	public function __construct(
		IConfig $config,
		LaborLawProfileFactory $lawProfileFactory,
		UserSettingsMapper $userSettingsMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		TimeEntryMapper $timeEntryMapper,
		TimeZoneService $timeZoneService,
		IL10N $l10n,
	) {
		$this->config = $config;
		$this->lawProfileFactory = $lawProfileFactory;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->timeZoneService = $timeZoneService;
		$this->l10n = $l10n;
	}

	/**
	 * Drop request-level caches (after an admin save in the same request).
	 */
	public function clearCache(): void
	{
		$this->orgConfigCache = null;
		$this->modelRulesCache = [];
		$this->userModelCache = [];
		$this->dayWorkCache = [];
		$this->activeCountriesCache = null;
		$this->orgConfigMalformed = false;
	}

	// ------------------------------------------------------------------ //
	// Org policy (app config JSON)                                        //
	// ------------------------------------------------------------------ //

	/**
	 * @return array{
	 *   version: int,
	 *   enforced: bool,
	 *   min_break_minutes: int|null,
	 *   tiers: list<array{afterHours: float, breakMinutes: int}>,
	 *   windows: list<array{start: string, end: string, minutes: int|null, mode: string}>,
	 *   presence_exemptions: list<array{start: string, end: string}>
	 * }
	 */
	private static function emptyPolicy(): array
	{
		return [
			'version' => self::POLICY_VERSION,
			'enforced' => false,
			'min_break_minutes' => null,
			'tiers' => [],
			'windows' => [],
			'presence_exemptions' => [],
		];
	}

	/**
	 * Normalised org policy; tolerant — malformed fragments are dropped with a
	 * logged warning so write/stamp paths degrade to law-only behaviour (E-26).
	 */
	public function orgConfig(): array
	{
		if ($this->orgConfigCache !== null) {
			return $this->orgConfigCache;
		}

		$raw = '';
		try {
			$raw = (string)$this->config->getAppValue(
				'arbeitszeitcheck',
				Constants::CONFIG_BREAK_POLICY_JSON,
				''
			);
		} catch (\Throwable $e) {
			$this->logWarning('break_policy_json read failed', $e);
		}

		return $this->orgConfigCache = $this->policyFromStoredDocument($raw);
	}

	/**
	 * Tolerant decode of a raw break_policy_json payload — identical
	 * semantics to orgConfig() but for a value the caller already read
	 * straight from the database (e.g. under a row lock inside a
	 * transaction), bypassing the per-request appconfig cache. That cache
	 * is the wrong merge base for a write: another admin's save may have
	 * committed after it was populated.
	 */
	public function policyFromStoredDocument(?string $raw): array
	{
		if ($raw === null || trim($raw) === '') {
			return self::emptyPolicy();
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->orgConfigMalformed = true;
			$this->logWarning('break_policy_json is not valid JSON — falling back to law-only break policy');
			return self::emptyPolicy();
		}

		return $this->normalizePolicyDocument($decoded, true);
	}

	/**
	 * The org policy controls breaks centrally — employees cannot opt out of
	 * automatic break calculation while this is on.
	 */
	public function isEnforced(): bool
	{
		return $this->orgConfig()['enforced'] === true;
	}

	/**
	 * Whether any org/model rule is configured at all (even without enforced).
	 * Used to skip policy evaluation fast and to label auto-break reasons.
	 */
	public function isPolicyActive(?string $userId = null): bool
	{
		$org = $this->orgConfig();
		if ($org['enforced'] || $org['min_break_minutes'] !== null
			|| $org['tiers'] !== [] || $org['windows'] !== []) {
			return true;
		}
		$model = $this->modelRulesForUser($userId);
		return $model['tiers'] !== [] || $model['min_break_minutes'] !== null
			|| $model['presence_windows'] !== [];
	}

	// ------------------------------------------------------------------ //
	// Working-time-model rules (existing break_rules JSON)                //
	// ------------------------------------------------------------------ //

	/**
	 * Current working-time model for a user (request-cached; null when the
	 * user has no assignment — presence checks then stay inert, E-38).
	 */
	public function modelForUser(?string $userId): ?WorkingTimeModel
	{
		if ($userId === null || $userId === '') {
			return null;
		}
		if (array_key_exists($userId, $this->userModelCache)) {
			return $this->userModelCache[$userId];
		}
		try {
			$assignment = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			if ($assignment === null) {
				return $this->userModelCache[$userId] = null;
			}
			return $this->userModelCache[$userId] =
				$this->workingTimeModelMapper->find((int)$assignment->getWorkingTimeModelId());
		} catch (\Throwable) {
			return $this->userModelCache[$userId] = null;
		}
	}

	/**
	 * @return array{
	 *   tiers: list<array{afterHours: float, breakMinutes: int}>,
	 *   min_break_minutes: int|null,
	 *   presence_windows: list<array{weekday: int, start: string, end: string}>
	 * }
	 */
	public function modelRulesForUser(?string $userId): array
	{
		$model = $this->modelForUser($userId);
		if ($model === null) {
			return self::emptyModelRules();
		}
		$id = (int)$model->getId();
		if (isset($this->modelRulesCache[$id])) {
			return $this->modelRulesCache[$id];
		}
		$raw = $model->getBreakRulesArray() ?? [];
		return $this->modelRulesCache[$id] = $this->normalizeModelRules(is_array($raw) ? $raw : []);
	}

	private static function emptyModelRules(): array
	{
		return [
			'tiers' => [],
			'min_break_minutes' => null,
			'presence_windows' => [],
		];
	}

	/**
	 * Tolerant normaliser for stored `break_rules` fragments. Unknown keys
	 * (weekday_schedule etc.) are owned elsewhere and left untouched.
	 */
	public function normalizeModelRules(array $raw): array
	{
		$rules = self::emptyModelRules();

		$tiers = $raw['tiers'] ?? null;
		if (is_array($tiers)) {
			foreach ($tiers as $tier) {
				$t = $this->normalizeTier($tier);
				if ($t !== null) {
					$rules['tiers'][] = $t;
				}
			}
			$rules['tiers'] = $this->sortTiersAscending($rules['tiers']);
		}

		$floor = $raw['min_break_minutes'] ?? null;
		if (is_int($floor) || (is_string($floor) && preg_match('/^\d+$/', trim($floor)))) {
			$floor = (int)$floor;
			if ($floor >= 1 && $floor <= self::MAX_WINDOW_MINUTES) {
				$rules['min_break_minutes'] = $floor;
			}
		}

		$presence = $raw['presence_windows'] ?? null;
		if (is_array($presence)) {
			foreach ($presence as $window) {
				$w = $this->normalizePresenceWindow($window);
				if ($w !== null) {
					$rules['presence_windows'][] = $w;
				}
			}
		}

		return $rules;
	}

	// ------------------------------------------------------------------ //
	// Tiers / floor merge                                                  //
	// ------------------------------------------------------------------ //

	/**
	 * Merged tier view for previews/UI: per threshold the strictest requirement
	 * across law/org/model plus the winning source label.
	 *
	 * @return list<array{afterHours: float, breakMinutes: int, source: string}>
	 */
	public function effectiveTiers(?string $userId = null): array
	{
		$profile = $this->lawProfileFactory->getProfile($userId);
		$byThreshold = [];

		foreach ($profile->breakTiers as $tier) {
			$key = (string)$tier['afterHours'];
			$byThreshold[$key] = [
				'afterHours' => (float)$tier['afterHours'],
				'breakMinutes' => (int)$tier['breakMinutes'],
				'source' => self::SOURCE_LAW,
			];
		}
		foreach ($this->orgConfig()['tiers'] as $tier) {
			$key = (string)$tier['afterHours'];
			if (!isset($byThreshold[$key]) || $tier['breakMinutes'] > $byThreshold[$key]['breakMinutes']) {
				$byThreshold[$key] = [
					'afterHours' => $tier['afterHours'],
					'breakMinutes' => $tier['breakMinutes'],
					'source' => self::SOURCE_ORG,
				];
			}
		}
		foreach ($this->modelRulesForUser($userId)['tiers'] as $tier) {
			$key = (string)$tier['afterHours'];
			if (!isset($byThreshold[$key]) || $tier['breakMinutes'] > $byThreshold[$key]['breakMinutes']) {
				$byThreshold[$key] = [
					'afterHours' => $tier['afterHours'],
					'breakMinutes' => $tier['breakMinutes'],
					'source' => self::SOURCE_MODEL,
				];
			}
		}

		$tiers = array_values($byThreshold);
		usort($tiers, static fn (array $a, array $b): int => $a['afterHours'] <=> $b['afterHours']);
		return $tiers;
	}

	/**
	 * Countable-break floor: max(law, org, model). A policy floor only affects
	 * counting/compliance evaluation — never the statutory validate() gate.
	 */
	public function effectiveFloor(?string $userId = null): int
	{
		$floor = $this->lawProfileFactory->getProfile($userId)->minBreakMinutes;
		$orgFloor = $this->orgConfig()['min_break_minutes'];
		if ($orgFloor !== null) {
			$floor = max($floor, $orgFloor);
		}
		$modelFloor = $this->modelRulesForUser($userId)['min_break_minutes'];
		if ($modelFloor !== null) {
			$floor = max($floor, $modelFloor);
		}
		return $floor;
	}

	/**
	 * Required break minutes for a net work duration, in whole seconds
	 * (exclusive statutory thresholds — exactly 6h00m00s does not cross >6h).
	 */
	public function requiredBreakMinutes(int $workSeconds, ?string $userId = null): int
	{
		return $this->requiredBreakDetailed($workSeconds, $userId)['minutes'];
	}

	/**
	 * @return array{minutes: int, source: string|null}
	 */
	public function requiredBreakDetailed(int $workSeconds, ?string $userId = null): array
	{
		$profile = $this->lawProfileFactory->getProfile($userId);
		$law = $this->requiredFromTiers($profile->breakTiers, $workSeconds);
		$org = $this->requiredFromTiers($this->orgConfig()['tiers'], $workSeconds);
		$model = $this->requiredFromTiers($this->modelRulesForUser($userId)['tiers'], $workSeconds);

		$max = max($law, $org, $model);
		if ($max <= 0) {
			return ['minutes' => 0, 'source' => null];
		}
		// Precedence on ties: the organisational policy "binds" before the model,
		// the model before the bare statute — that is the rule an admin expects
		// to see reported when it tightened the requirement.
		$source = $org === $max ? self::SOURCE_ORG
			: ($model === $max ? self::SOURCE_MODEL : self::SOURCE_LAW);

		return ['minutes' => $max, 'source' => $source];
	}

	/**
	 * Highest requirement from a tier list at an exclusive seconds threshold.
	 *
	 * @param list<array{afterHours: float, breakMinutes: int}> $tiers
	 */
	private function requiredFromTiers(array $tiers, int $workSeconds): int
	{
		$required = 0;
		foreach ($tiers as $tier) {
			$threshold = (int)round(((float)$tier['afterHours']) * 3600);
			if ($workSeconds > $threshold) {
				$required = max($required, (int)$tier['breakMinutes']);
			}
		}
		return $required;
	}

	// ------------------------------------------------------------------ //
	// Break windows (org level)                                           //
	// ------------------------------------------------------------------ //

	/**
	 * @return list<array{start: string, end: string, minutes: int|null, mode: string}>
	 */
	public function windows(): array
	{
		return $this->orgConfig()['windows'];
	}

	/**
	 * Concrete [startTs, endTs) interval of window $w anchored at calendar day
	 * $day (Y-m-d, storage TZ). Overnight windows (`end` < `start`) wrap into
	 * the next day. Returns null when a bound hits a DST-nonexistent local
	 * time — the window does not apply that day (spec §9.7).
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function canonicalWindow(string $day, array $window): ?array
	{
		$tz = $this->timeZoneService->storageTimeZone();
		$start = $this->localTimeToTs($day, (string)$window['start'], $tz);
		if ($start === null) {
			return null;
		}
		$endDay = $day;
		if ($this->timeToMinutes((string)$window['end']) < $this->timeToMinutes((string)$window['start'])) {
			// Overnight window wraps into the next day. Equal times fall through
			// to the $end <= $start guard below → null, never a 24h window.
			$endDay = (new \DateTimeImmutable($day . ' 12:00:00', $tz))->modify('+1 day')->format('Y-m-d');
		}
		$end = $this->localTimeToTs($endDay, (string)$window['end'], $tz);
		if ($end === null) {
			return null;
		}
		if ($end <= $start) {
			return null;
		}
		return [$start, $end];
	}

	/**
	 * Resolve "HH:MM" on $day to an epoch second; null when the local time is
	 * nonexistent on that day (DST spring-forward). Ambiguous times resolve to
	 * the first occurrence — one instance is sufficient (spec §9.7).
	 */
	private function localTimeToTs(string $day, string $hhmm, \DateTimeZone $tz): ?int
	{
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day . ' ' . $hhmm, $tz);
		if ($dt === false) {
			return null;
		}
		// PHP silently rolls nonexistent local times forward; detect + reject.
		if ($dt->format('H:i') !== $hhmm) {
			return null;
		}
		return $dt->getTimestamp();
	}

	private function timeToMinutes(string $hhmm): int
	{
		return ((int)substr($hhmm, 0, 2)) * 60 + (int)substr($hhmm, 3, 2);
	}

	/**
	 * Merged work intervals ([startTs, endTs]) of $userId on the calendar day
	 * (storage TZ) containing all overlapping entries plus an optional
	 * not-yet-persisted live entry.
	 *
	 * @return list<array{0:int,1:int}>
	 */
	public function dayWork(
		string $userId,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		?TimeEntry $liveEntry = null,
		?int $excludeEntryId = null,
	): array {
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();
		if ($dayEndTs <= $dayStartTs) {
			return [];
		}

		$intervals = [];
		try {
			$entries = $this->timeEntryMapper->findOverlapping($userId, $dayStart, $dayEnd) ?? [];
		} catch (\Throwable $e) {
			$this->logWarning('dayWork lookup failed', $e);
			$entries = [];
		}
		$liveId = $liveEntry !== null ? $liveEntry->getId() : null;
		foreach ($entries as $entry) {
			if ($excludeEntryId !== null && $entry->getId() === $excludeEntryId) {
				continue;
			}
			if ($liveId !== null && $entry->getId() === $liveId) {
				continue; // live entry added below in its in-memory state
			}
			$span = $this->entryWorkSpan($entry, $dayStartTs, $dayEndTs);
			if ($span !== null) {
				$intervals[] = $span;
			}
		}
		if ($liveEntry !== null) {
			$span = $this->entryWorkSpan($liveEntry, $dayStartTs, $dayEndTs);
			if ($span !== null) {
				$intervals[] = $span;
			}
		}

		return $this->mergeIntervals($intervals);
	}

	/**
	 * @return array{0:int,1:int}|null
	 */
	private function entryWorkSpan(TimeEntry $entry, int $clipStart, int $clipEnd): ?array
	{
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start === null || $end === null) {
			return null;
		}
		$s = max($start->getTimestamp(), $clipStart);
		$e = min($end->getTimestamp(), $clipEnd);
		return $e > $s ? [$s, $e] : null;
	}

	/**
	 * Day-level break aggregation (E-25): every break portion of every entry
	 * overlapping the calendar day, classified automatic vs manual.
	 * `automatic:true` JSON portions are automatic; all other JSON portions and
	 * legacy breakStartTime/breakEndTime are conservatively manual.
	 *
	 * @return array{
	 *   total_seconds: int,
	 *   automatic_seconds: int,
	 *   manual_seconds: int,
	 *   has_manual: bool,
	 *   intervals: list<array{0:int,1:int}>
	 * }
	 */
	public function dayBreakSummary(
		string $userId,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		?TimeEntry $liveEntry = null,
		?int $excludeEntryId = null,
	): array {
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();
		$empty = [
			'total_seconds' => 0,
			'automatic_seconds' => 0,
			'manual_seconds' => 0,
			'has_manual' => false,
			'intervals' => [],
		];
		if ($dayEndTs <= $dayStartTs) {
			return $empty;
		}

		try {
			$entries = $this->timeEntryMapper->findOverlapping($userId, $dayStart, $dayEnd) ?? [];
		} catch (\Throwable $e) {
			$this->logWarning('dayBreakSummary lookup failed', $e);
			$entries = [];
		}
		$liveId = $liveEntry !== null ? $liveEntry->getId() : null;
		if ($liveEntry !== null) {
			$entries[] = $liveEntry;
		}

		$autoIntervals = [];
		$manualIntervals = [];
		foreach ($entries as $entry) {
			if ($liveId !== null && $entry !== $liveEntry && $entry->getId() === $liveId) {
				continue;
			}
			if ($excludeEntryId !== null && $entry !== $liveEntry && $entry->getId() === $excludeEntryId) {
				continue;
			}
			foreach ($this->entryBreakIntervals($entry, $dayStartTs, $dayEndTs) as $piece) {
				if ($piece['automatic']) {
					$autoIntervals[] = [$piece['start'], $piece['end']];
				} else {
					$manualIntervals[] = [$piece['start'], $piece['end']];
				}
			}
		}

		$all = $this->mergeIntervals(array_merge($autoIntervals, $manualIntervals));
		$auto = $this->mergeIntervals($autoIntervals);
		$manual = $this->mergeIntervals($manualIntervals);

		return [
			'total_seconds' => $this->intervalSeconds($all),
			'automatic_seconds' => $this->intervalSeconds($auto),
			'manual_seconds' => $this->intervalSeconds($manual),
			'has_manual' => $manual !== [],
			'intervals' => $all,
		];
	}

	/**
	 * Break portions of one entry clipped to [clipStart, clipEnd).
	 *
	 * @return list<array{start: int, end: int, automatic: bool}>
	 */
	private function entryBreakIntervals(TimeEntry $entry, int $clipStart, int $clipEnd): array
	{
		$out = [];

		$json = $entry->getBreaks();
		if (is_string($json) && $json !== '') {
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				foreach ($decoded as $portion) {
					if (!is_array($portion) || !isset($portion['start'], $portion['end'])) {
						continue;
					}
					try {
						$s = (new \DateTime((string)$portion['start']))->getTimestamp();
						$e = (new \DateTime((string)$portion['end']))->getTimestamp();
					} catch (\Throwable) {
						continue;
					}
					$s = max($s, $clipStart);
					$e = min($e, $clipEnd);
					if ($e > $s) {
						$out[] = [
							'start' => $s,
							'end' => $e,
							'automatic' => ($portion['automatic'] ?? false) === true,
						];
					}
				}
			}
		}

		$legacyStart = $entry->getBreakStartTime();
		$legacyEnd = $entry->getBreakEndTime();
		if ($legacyStart !== null && $legacyEnd !== null) {
			$s = max($legacyStart->getTimestamp(), $clipStart);
			$e = min($legacyEnd->getTimestamp(), $clipEnd);
			if ($e > $s) {
				$out[] = ['start' => $s, 'end' => $e, 'automatic' => false];
			}
		}

		return $out;
	}

	/**
	 * Whether the window applies to calendar day $day: the day's merged work
	 * (plus next-day work for wrapping windows) intersects the window (E-14).
	 */
	public function windowApplies(string $userId, string $day, array $window, ?TimeEntry $liveEntry = null): bool
	{
		$interval = $this->canonicalWindow($day, $window);
		if ($interval === null) {
			return false;
		}
		$tz = $this->timeZoneService->storageTimeZone();
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage(
			new \DateTime($day . ' 12:00:00', $tz)
		);
		$work = $this->dayWork($userId, $dayStart, $dayEnd, $liveEntry);
		if ($this->timeToMinutes((string)$window['end']) <= $this->timeToMinutes((string)$window['start'])) {
			[$nStart, $nEnd] = $this->timeZoneService->dayWindowInStorage($dayEnd);
			$work = $this->mergeIntervals(array_merge($work, $this->dayWork($userId, $nStart, $nEnd, $liveEntry)));
		}

		return $this->overlapSeconds($work, $interval[0], $interval[1]) > 0;
	}

	// ------------------------------------------------------------------ //
	// Auto-break placement                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Plan an automatic break for $entry: the day-level remainder after
	 * existing day breaks, placed in the first applicable window (daily order),
	 * else the shift midpoint. Never ends at/after the shift end.
	 *
	 * Returns null when nothing should be inserted (day already covered or a
	 * manual break suppresses automatic insertion — E-25).
	 *
	 * @return array{start: \DateTime, end: \DateTime, seconds: int, window: array<string,mixed>|null}|null
	 */
	public function planAutoBreak(TimeEntry $entry, int $requiredMinutes): ?array
	{
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		$userId = (string)$entry->getUserId();
		if ($start === null || $end === null || $requiredMinutes <= 0) {
			return null;
		}
		$startTs = $start->getTimestamp();
		$endTs = $end->getTimestamp();
		if ($endTs <= $startTs) {
			return null;
		}

		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($start);
		$summary = $this->dayBreakSummary($userId, $dayStart, $dayEnd, $entry, $entry->getId());
		if ($summary['has_manual']) {
			return null; // any manual break on the day suppresses auto insertion
		}
		$neededSeconds = $requiredMinutes * 60 - $summary['total_seconds'];
		if ($neededSeconds <= 0) {
			return null; // day already covered — no second auto break
		}

		// First applicable window in daily order wins.
		foreach ($this->windows() as $window) {
			$plan = $this->tryPlaceInWindow($entry, $window, $neededSeconds, $startTs, $endTs);
			if ($plan !== null) {
				return $plan;
			}
		}

		// Fallback: midpoint of this entry's span (legacy behaviour).
		return $this->planMidpoint($start, $endTs, $neededSeconds);
	}

	/**
	 * Try to fit a break inside the intersection of window $w and the entry's
	 * work span. Returns null when no clamped placement satisfies the window.
	 *
	 * @return array{start: \DateTime, end: \DateTime, seconds: int, window: array<string,mixed>}|null
	 */
	private function tryPlaceInWindow(TimeEntry $entry, array $window, int $neededSeconds, int $startTs, int $endTs): ?array
	{
		$userId = (string)$entry->getUserId();
		$windowMinutes = (int)($window['minutes'] ?? 0);
		$duration = max($neededSeconds, $windowMinutes * 60);

		$tz = $this->timeZoneService->storageTimeZone();
		$dayCursor = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz)->format('Y-m-d');
		$lastDay = (new \DateTimeImmutable('@' . ($endTs - 1)))->setTimezone($tz)->format('Y-m-d');

		while ($dayCursor <= $lastDay) {
			$interval = $this->canonicalWindow($dayCursor, $window);
			if ($interval !== null && $this->windowApplies($userId, $dayCursor, $window, $entry)) {
				$slotStart = max($interval[0], $startTs);
				$slotEnd = min($interval[1], $endTs);
				if ($slotEnd > $slotStart) {
					$breakStart = $slotStart;
					// Break must end strictly before the shift end (E-23).
					if ($breakStart + $duration >= $endTs) {
						$breakStart = $endTs - $duration - 1;
					}
					if ($breakStart >= $startTs && $breakStart + $duration < $endTs) {
						$fits = $window['mode'] === self::MODE_REQUIRED
							? $this->overlapSeconds([[$breakStart, $breakStart + $duration]], $interval[0], $interval[1]) >= $windowMinutes * 60
							: ($breakStart >= $interval[0] && $breakStart < $interval[1]);
						if ($fits) {
							return [
								'start' => (new \DateTime('@' . $breakStart))->setTimezone($tz),
								'end' => (new \DateTime('@' . ($breakStart + $duration)))->setTimezone($tz),
								'seconds' => $duration,
								'window' => $window,
							];
						}
					}
				}
			}
			$dayCursor = (new \DateTimeImmutable($dayCursor . ' 12:00:00', $tz))->modify('+1 day')->format('Y-m-d');
		}

		return null;
	}

	private function planMidpoint(\DateTime $start, int $endTs, int $neededSeconds): ?array
	{
		$startTs = $start->getTimestamp();
		$offset = (int)floor(($endTs - $startTs - $neededSeconds) / 2);
		if ($offset < 0) {
			$offset = 0;
		}
		$breakStart = $startTs + $offset;
		$breakEnd = $breakStart + $neededSeconds;
		if ($breakEnd >= $endTs) {
			// Entry too short to host the break inside its span — never write a
			// break outside the work interval.
			if ($endTs - $startTs - 1 <= $neededSeconds) {
				return null;
			}
			$breakStart = $endTs - $neededSeconds - 1;
			$breakEnd = $endTs - 1;
		}
		$tz = $this->timeZoneService->storageTimeZone();
		return [
			'start' => (new \DateTime('@' . $breakStart))->setTimezone($tz),
			'end' => (new \DateTime('@' . $breakEnd))->setTimezone($tz),
			'seconds' => $neededSeconds,
			'window' => null,
		];
	}

	/**
	 * Required-mode window shortfalls for the days touched by $entry.
	 * Coverage = merged day break intervals ∩ window ≥ window.minutes.
	 *
	 * @return list<array{day: string, start: string, end: string, required_minutes: int, covered_minutes: int}>
	 */
	public function windowViolations(string $userId, TimeEntry $entry): array
	{
		$violations = [];
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start === null || $end === null) {
			return $violations;
		}

		$tz = $this->timeZoneService->storageTimeZone();
		$dayCursor = (new \DateTimeImmutable('@' . $start->getTimestamp()))->setTimezone($tz)->format('Y-m-d');
		$lastDay = (new \DateTimeImmutable('@' . ($end->getTimestamp() - 1)))->setTimezone($tz)->format('Y-m-d');

		foreach ($this->windows() as $window) {
			if (($window['mode'] ?? '') !== self::MODE_REQUIRED) {
				continue;
			}
			$cursor = $dayCursor;
			while ($cursor <= $lastDay) {
				$interval = $this->canonicalWindow($cursor, $window);
				if ($interval !== null && $this->windowApplies($userId, $cursor, $window, $entry)) {
					[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage(
						new \DateTime($cursor . ' 12:00:00', $tz)
					);
					$summary = $this->dayBreakSummary($userId, $dayStart, $dayEnd, $entry, $entry->getId());
					$required = max(1, (int)($window['minutes'] ?? 0));
					$covered = (int)floor($this->overlapSeconds($summary['intervals'], $interval[0], $interval[1]) / 60);
					if ($covered < $required) {
						$violations[] = [
							'day' => $cursor,
							'start' => (string)$window['start'],
							'end' => (string)$window['end'],
							'required_minutes' => $required,
							'covered_minutes' => $covered,
						];
					}
				}
				$cursor = (new \DateTimeImmutable($cursor . ' 12:00:00', $tz))->modify('+1 day')->format('Y-m-d');
			}
		}

		return $violations;
	}

	// ------------------------------------------------------------------ //
	// Presence windows (model level) + exemptions (org level)             //
	// ------------------------------------------------------------------ //

	/**
	 * Presence windows of the user's current model for an ISO weekday (1–7).
	 *
	 * @return list<array{weekday: int, start: string, end: string}>
	 */
	public function presenceWindows(?string $userId, int $weekdayIso): array
	{
		$out = [];
		foreach ($this->modelRulesForUser($userId)['presence_windows'] as $w) {
			if ($w['weekday'] === $weekdayIso) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/**
	 * Whether the calendar day (Y-m-d) falls into an org-wide presence
	 * exemption range (school holidays etc., D-17).
	 */
	public function presenceExempt(string $dayYmd): bool
	{
		foreach ($this->orgConfig()['presence_exemptions'] as $range) {
			if ($range['start'] <= $dayYmd && $dayYmd <= $range['end']) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ //
	// Validation / normalisation (admin save + tolerant parse)            //
	// ------------------------------------------------------------------ //

	/**
	 * Strictly validate an admin-submitted policy document.
	 *
	 * @param array<string,mixed> $input Keys: enforced, min_break_minutes, tiers, windows, presence_exemptions
	 * @return array{errors: array<string,string>, normalized: array<string,mixed>}
	 *         errors maps request field names → machine codes.
	 */
	public function validatePolicy(array $input): array
	{
		$errors = [];
		$normalized = self::emptyPolicy();

		// enforced — strict bool only (accepts true/false/'1'/'0'/'true'/'false').
		$enforcedRaw = $input['enforced'] ?? false;
		if (is_bool($enforcedRaw)) {
			$normalized['enforced'] = $enforcedRaw;
		} elseif (in_array($enforcedRaw, ['1', 'true', 1], true)) {
			$normalized['enforced'] = true;
		} elseif (in_array($enforcedRaw, ['0', 'false', 0, '', null], true)) {
			$normalized['enforced'] = false;
		} else {
			$errors[self::FIELD_ENFORCED] = 'VALIDATION_BOOLEAN';
		}

		// min_break_minutes — null or int 1..480.
		$floorRaw = $input['min_break_minutes'] ?? null;
		if ($floorRaw !== null && $floorRaw !== '') {
			$floor = $this->strictInt($floorRaw);
			if ($floor === null || $floor < 1 || $floor > self::MAX_WINDOW_MINUTES) {
				$errors[self::FIELD_MIN_BREAK] = 'VALIDATION_RANGE';
			} else {
				$normalized['min_break_minutes'] = $floor;
			}
		}

		// tiers — ≤8, ascending-normalised.
		$tiersRaw = $input['tiers'] ?? [];
		if (!is_array($tiersRaw)) {
			$errors[self::FIELD_TIERS] = 'VALIDATION_SHAPE';
		} elseif (count($tiersRaw) > self::MAX_TIERS) {
			$errors[self::FIELD_TIERS] = 'VALIDATION_TOO_MANY';
		} else {
			foreach ($tiersRaw as $i => $tier) {
				$t = $this->normalizeTierStrict($tier);
				if ($t === null) {
					$errors[self::FIELD_TIERS . '.' . $i] = 'VALIDATION_TIER';
					continue;
				}
				$normalized['tiers'][] = $t;
			}
			$normalized['tiers'] = $this->sortTiersAscending($normalized['tiers']);
		}

		// windows — ≤4, no overlaps (incl. wrap), required ⇒ minutes ≤ length.
		$windowsRaw = $input['windows'] ?? [];
		if (!is_array($windowsRaw)) {
			$errors[self::FIELD_WINDOWS] = 'VALIDATION_SHAPE';
		} elseif (count($windowsRaw) > self::MAX_WINDOWS) {
			$errors[self::FIELD_WINDOWS] = 'VALIDATION_TOO_MANY';
		} else {
			foreach ($windowsRaw as $i => $window) {
				[$w, $code] = $this->normalizeWindowStrict($window);
				if ($w === null) {
					$errors[self::FIELD_WINDOWS . '.' . $i] = $code;
					continue;
				}
				$normalized['windows'][] = $w;
			}
			if ($this->windowsOverlap($normalized['windows'])) {
				$errors[self::FIELD_WINDOWS] = 'VALIDATION_OVERLAP';
			}
		}

		// presence_exemptions — ≤8 ISO ranges, start ≤ end (overlaps allowed).
		$exemptRaw = $input['presence_exemptions'] ?? [];
		if (!is_array($exemptRaw)) {
			$errors[self::FIELD_EXEMPTIONS] = 'VALIDATION_SHAPE';
		} elseif (count($exemptRaw) > self::MAX_PRESENCE_EXEMPTIONS) {
			$errors[self::FIELD_EXEMPTIONS] = 'VALIDATION_TOO_MANY';
		} else {
			foreach ($exemptRaw as $i => $range) {
				$r = $this->normalizeExemptionRange($range);
				if ($r === null) {
					$errors[self::FIELD_EXEMPTIONS . '.' . $i] = 'VALIDATION_RANGE';
					continue;
				}
				$normalized['presence_exemptions'][] = $r;
			}
		}

		return ['errors' => $errors, 'normalized' => $normalized];
	}

	/**
	 * Tolerant normaliser for a stored policy document — drops invalid
	 * fragments with a logged warning instead of failing (occ path, E-26).
	 */
	private function normalizePolicyDocument(array $decoded, bool $logWarnings): array
	{
		$policy = self::emptyPolicy();
		$drop = function (string $what) use ($logWarnings): void {
			$this->orgConfigMalformed = true;
			if ($logWarnings) {
				$this->logWarning('Dropping malformed break_policy_json fragment: ' . $what);
			}
		};

		$policy['enforced'] = filter_var($decoded['enforced'] ?? false, FILTER_VALIDATE_BOOLEAN);

		$floor = $decoded['min_break_minutes'] ?? null;
		$floorInt = $this->strictInt($floor);
		if ($floorInt !== null && $floorInt >= 1 && $floorInt <= self::MAX_WINDOW_MINUTES) {
			$policy['min_break_minutes'] = $floorInt;
		} elseif ($floor !== null) {
			$drop('min_break_minutes');
		}

		$tiers = $decoded['tiers'] ?? [];
		if (is_array($tiers)) {
			foreach ($tiers as $tier) {
				$t = $this->normalizeTier($tier);
				if ($t !== null) {
					$policy['tiers'][] = $t;
				} else {
					$drop('tier');
				}
			}
			$policy['tiers'] = $this->sortTiersAscending($policy['tiers']);
		} elseif ($tiers !== [] && $tiers !== null) {
			$drop('tiers');
		}

		$windows = $decoded['windows'] ?? [];
		if (is_array($windows)) {
			foreach ($windows as $window) {
				[$w] = $this->normalizeWindowStrict($window);
				if ($w !== null) {
					$policy['windows'][] = $w;
				} else {
					$drop('window');
				}
			}
		} elseif ($windows !== [] && $windows !== null) {
			$drop('windows');
		}

		$exemptions = $decoded['presence_exemptions'] ?? [];
		if (is_array($exemptions)) {
			foreach ($exemptions as $range) {
				$r = $this->normalizeExemptionRange($range);
				if ($r !== null) {
					$policy['presence_exemptions'][] = $r;
				} else {
					$drop('presence_exemption');
				}
			}
		} elseif ($exemptions !== [] && $exemptions !== null) {
			$drop('presence_exemptions');
		}

		return $policy;
	}

	/**
	 * "Is the whole policy shadowed by statutory rules for every active
	 * country?" — gates the CONFIRM_REQUIRED flow. Windows/exemptions always
	 * count as meaningful (they have no statutory counterpart).
	 */
	public function policyIneffectiveEverywhere(array $normalized): bool
	{
		if ($normalized['windows'] !== [] || $normalized['presence_exemptions'] !== []
			|| $normalized['tiers'] === [] && $normalized['min_break_minutes'] === null) {
			return false;
		}

		foreach ($this->activeCountries() as $country) {
			$profile = LaborLawProfileFactory::profileForCountry($country);
			if (!$this->policyShadowedForProfile($normalized, $profile)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Per-country shadow warnings for the admin preview: tiers/floor that can
	 * never tighten anything because the statute already requires at least as
	 * much at the same durations.
	 *
	 * @return list<string> human-readable warnings
	 */
	public function policyWarnings(array $normalized): array
	{
		$warnings = [];
		foreach ($this->activeCountries() as $country) {
			$profile = LaborLawProfileFactory::profileForCountry($country);
			foreach ($normalized['tiers'] as $tier) {
				// Shadowed when the law already requires ≥ tier.breakMinutes at
				// the first duration where the tier binds.
				$lawAtBind = $this->requiredFromTiers($profile->breakTiers, (int)round($tier['afterHours'] * 3600) + 1);
				if ($lawAtBind >= $tier['breakMinutes']) {
					$warnings[] = $this->l10n->t(
						'The rule "break of %1$d min after more than %2$s h" never tightens anything for %3$s — the law already requires at least %4$d min there.',
						[
							$tier['breakMinutes'],
							LaborLawProfile::formatHoursLabel($tier['afterHours']),
							strtoupper($country),
							$lawAtBind,
						]
					);
				}
			}
			$floor = $normalized['min_break_minutes'];
			if ($floor !== null && $floor <= $profile->minBreakMinutes) {
				$warnings[] = $this->l10n->t(
					'The minimum countable break of %1$d min has no effect for %2$s — the statutory floor is already %3$d min.',
					[$floor, strtoupper($country), $profile->minBreakMinutes]
				);
			}
		}
		return array_values(array_unique($warnings));
	}

	/**
	 * @return list<string> uppercase country codes: instance ∪ per-user overrides
	 */
	public function activeCountries(): array
	{
		if ($this->activeCountriesCache !== null) {
			return $this->activeCountriesCache;
		}
		$countries = [$this->lawProfileFactory->getConfiguredCountry()];
		try {
			foreach ($this->userSettingsMapper->findUserIdsWithNonEmptySetting(
				LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY
			) as $uid) {
				$countries[] = $this->lawProfileFactory->getEffectiveCountry((string)$uid);
			}
		} catch (\Throwable $e) {
			$this->logWarning('activeCountries override scan failed', $e);
		}
		return $this->activeCountriesCache = array_values(array_unique($countries));
	}

	/**
	 * Payload for the admin policy editor: current policy, shadow warnings and
	 * a health flag when the stored JSON had to be repaired tolerantly.
	 *
	 * @return array{
	 *   policy: array<string,mixed>,
	 *   warnings: list<string>,
	 *   malformed: bool,
	 *   storageTimezone: string
	 * }
	 */
	public function adminPayload(): array
	{
		$policy = $this->orgConfig();
		return [
			'policy' => $policy,
			'warnings' => $this->policyWarnings($policy),
			'malformed' => $this->orgConfigMalformed,
			'storageTimezone' => $this->timeZoneService->storageTimeZoneName(),
		];
	}

	private function policyShadowedForProfile(array $normalized, LaborLawProfile $profile): bool
	{
		foreach ($normalized['tiers'] as $tier) {
			$lawAtBind = $this->requiredFromTiers($profile->breakTiers, (int)round($tier['afterHours'] * 3600) + 1);
			if ($lawAtBind < $tier['breakMinutes']) {
				return false;
			}
		}
		$floor = $normalized['min_break_minutes'];
		if ($floor !== null && $floor > $profile->minBreakMinutes) {
			return false;
		}
		return true;
	}

	// ------------------------------------------------------------------ //
	// Field normalisers                                                    //
	// ------------------------------------------------------------------ //

	/** @return array{afterHours: float, breakMinutes: int}|null tolerant */
	private function normalizeTier(mixed $tier): ?array
	{
		if (!is_array($tier)) {
			return null;
		}
		$after = $tier['afterHours'] ?? null;
		$minutes = $tier['breakMinutes'] ?? null;
		if (!is_numeric($after)) {
			return null;
		}
		$after = (float)$after;
		$minutes = $this->strictInt($minutes);
		if ($after <= 0 || $after > 24 || $minutes === null || $minutes < 1 || $minutes > self::MAX_WINDOW_MINUTES) {
			return null;
		}
		return ['afterHours' => $after, 'breakMinutes' => $minutes];
	}

	/** Strict variant for admin saves — rejects " 5"/"2e3" style smuggling. */
	private function normalizeTierStrict(mixed $tier): ?array
	{
		if (!is_array($tier)) {
			return null;
		}
		$after = $tier['afterHours'] ?? null;
		if (is_string($after)) {
			$after = trim($after);
			if (!preg_match('/^\d+(\.\d+)?$/', $after)) {
				return null;
			}
			$after = (float)$after;
		} elseif (is_int($after) || is_float($after)) {
			$after = (float)$after;
		} else {
			return null;
		}
		$minutes = $this->strictInt($tier['breakMinutes'] ?? null);
		if ($after <= 0 || $after > 24 || $minutes === null || $minutes < 1 || $minutes > self::MAX_WINDOW_MINUTES) {
			return null;
		}
		return ['afterHours' => $after, 'breakMinutes' => $minutes];
	}

	/**
	 * @return array{0: array{start:string,end:string,minutes:int|null,mode:string}|null, 1: string}
	 */
	private function normalizeWindowStrict(mixed $window): array
	{
		if (!is_array($window)) {
			return [null, 'VALIDATION_SHAPE'];
		}
		$start = $window['start'] ?? null;
		$end = $window['end'] ?? null;
		if (!is_string($start) || !is_string($end)
			|| !preg_match(self::TIME_PATTERN, $start) || !preg_match(self::TIME_PATTERN, $end)) {
			return [null, 'VALIDATION_TIME'];
		}
		if ($start === $end) {
			return [null, 'VALIDATION_EMPTY_WINDOW'];
		}
		$length = $this->timeToMinutes($end) - $this->timeToMinutes($start);
		if ($length <= 0) {
			$length += 1440; // overnight wrap
		}
		if ($length > self::MAX_WINDOW_MINUTES) {
			return [null, 'VALIDATION_WINDOW_TOO_LONG'];
		}
		$mode = $window['mode'] ?? self::MODE_PLACEMENT;
		if (!in_array($mode, [self::MODE_PLACEMENT, self::MODE_REQUIRED], true)) {
			return [null, 'VALIDATION_MODE'];
		}
		$minutesRaw = $window['minutes'] ?? null;
		$minutes = null;
		if ($minutesRaw !== null && $minutesRaw !== '') {
			$minutes = $this->strictInt($minutesRaw);
			if ($minutes === null || $minutes < 1 || $minutes > self::MAX_WINDOW_MINUTES) {
				return [null, 'VALIDATION_MINUTES'];
			}
		}
		if ($mode === self::MODE_REQUIRED && $minutes !== null && $minutes > $length) {
			return [null, 'VALIDATION_MINUTES_EXCEED_WINDOW'];
		}
		if ($mode === self::MODE_REQUIRED && $minutes === null) {
			$minutes = $length; // required without minutes ⇒ the whole window
		}
		return [['start' => $start, 'end' => $end, 'minutes' => $minutes, 'mode' => $mode], ''];
	}

	/**
	 * Strict validation for WTM `break_rules.presence_windows` at the admin
	 * write boundary: ≤8 windows, weekday 1..7, HH:MM, no same-weekday
	 * overlap. Returns machine codes keyed `presence_windows[.i]`.
	 *
	 * @return array{errors: array<string,string>, normalized: list<array{weekday:int,start:string,end:string}>}
	 */
	public function validatePresenceWindows(mixed $raw): array
	{
		$errors = [];
		$normalized = [];

		if ($raw === null || $raw === []) {
			return ['errors' => [], 'normalized' => []];
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			return ['errors' => ['presence_windows' => 'VALIDATION_SHAPE'], 'normalized' => []];
		}
		if (count($raw) > self::MAX_PRESENCE_WINDOWS_WRITE) {
			return ['errors' => ['presence_windows' => 'VALIDATION_TOO_MANY'], 'normalized' => []];
		}

		foreach ($raw as $i => $window) {
			if (!is_array($window)) {
				$errors['presence_windows.' . $i] = 'VALIDATION_SHAPE';
				continue;
			}
			$weekday = $this->strictInt($window['weekday'] ?? null);
			$start = $window['start'] ?? null;
			$end = $window['end'] ?? null;
			if ($weekday === null || $weekday < 1 || $weekday > 7) {
				$errors['presence_windows.' . $i] = 'VALIDATION_WEEKDAY';
				continue;
			}
			if (!is_string($start) || !is_string($end)
				|| !preg_match(self::TIME_PATTERN, $start) || !preg_match(self::TIME_PATTERN, $end)) {
				$errors['presence_windows.' . $i] = 'VALIDATION_TIME';
				continue;
			}
			// Presence windows are same-day only — a wrap (start ≥ end) has no
			// meaningful weekday anchor for a coverage rule.
			if ($start >= $end) {
				$errors['presence_windows.' . $i] = 'VALIDATION_WINDOW_ORDER';
				continue;
			}
			$normalized[] = ['weekday' => $weekday, 'start' => $start, 'end' => $end];
		}

		// Same-weekday overlaps double-count coverage and confuse placement.
		$byWeekday = [];
		foreach ($normalized as $w) {
			$s = $this->timeToMinutes($w['start']);
			$e = $this->timeToMinutes($w['end']);
			foreach ($byWeekday[$w['weekday']] ?? [] as $existing) {
				if ($s < $existing[1] && $existing[0] < $e) {
					$errors['presence_windows'] = 'VALIDATION_OVERLAP';
					break 2;
				}
			}
			$byWeekday[$w['weekday']][] = [$s, $e];
		}

		return ['errors' => $errors, 'normalized' => $normalized];
	}

	/** @return array{weekday: int, start: string, end: string}|null */
	private function normalizePresenceWindow(mixed $window): ?array
	{
		if (!is_array($window)) {
			return null;
		}
		$weekday = $this->strictInt($window['weekday'] ?? null);
		$start = $window['start'] ?? null;
		$end = $window['end'] ?? null;
		if ($weekday === null || $weekday < 1 || $weekday > 7
			|| !is_string($start) || !is_string($end)
			|| !preg_match(self::TIME_PATTERN, $start) || !preg_match(self::TIME_PATTERN, $end)
			|| $start >= $end) {
			return null;
		}
		return ['weekday' => $weekday, 'start' => $start, 'end' => $end];
	}

	/** @return array{start: string, end: string}|null */
	private function normalizeExemptionRange(mixed $range): ?array
	{
		if (!is_array($range)) {
			return null;
		}
		$start = $range['start'] ?? null;
		$end = $range['end'] ?? null;
		if (!is_string($start) || !is_string($end)
			|| !preg_match(self::DATE_PATTERN, $start) || !preg_match(self::DATE_PATTERN, $end)) {
			return null;
		}
		// Verify real calendar dates (2026-02-31 is shaped right but invalid).
		$s = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
		$e = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
		if ($s === false || $e === false || $s->format('Y-m-d') !== $start || $e->format('Y-m-d') !== $end) {
			return null;
		}
		if ($end < $start) {
			return null;
		}
		return ['start' => $start, 'end' => $end];
	}

	private function strictInt(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value)) {
			$v = trim($value);
			if (preg_match('/^-?\d+$/', $v)) {
				return (int)$v;
			}
		}
		if (is_float($value) && floor($value) === $value) {
			return (int)$value;
		}
		return null;
	}

	/**
	 * @param list<array{afterHours: float, breakMinutes: int}> $tiers
	 * @return list<array{afterHours: float, breakMinutes: int}>
	 */
	private function sortTiersAscending(array $tiers): array
	{
		usort($tiers, static fn (array $a, array $b): int => $a['afterHours'] <=> $b['afterHours']);
		return $tiers;
	}

	/**
	 * Pairwise overlap on the minute-of-day circle (wrap-aware).
	 *
	 * @param list<array{start: string, end: string}> $windows
	 */
	private function windowsOverlap(array $windows): bool
	{
		$perWindow = [];
		foreach ($windows as $idx => $w) {
			$s = $this->timeToMinutes($w['start']);
			$e = $this->timeToMinutes($w['end']);
			$perWindow[$idx] = $e <= $s ? [[$s, 1440], [0, $e]] : [[$s, $e]];
		}
		$count = count($perWindow);
		for ($i = 0; $i < $count; $i++) {
			for ($j = $i + 1; $j < $count; $j++) {
				foreach ($perWindow[$i] as $a) {
					foreach ($perWindow[$j] as $b) {
						if ($a[0] < $b[1] && $b[0] < $a[1]) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ //
	// Interval helpers                                                     //
	// ------------------------------------------------------------------ //

	/**
	 * @param list<array{0:int,1:int}> $intervals
	 * @return list<array{0:int,1:int}>
	 */
	private function mergeIntervals(array $intervals): array
	{
		if ($intervals === []) {
			return [];
		}
		usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$merged = [$intervals[0]];
		foreach (array_slice($intervals, 1) as $iv) {
			$last = &$merged[count($merged) - 1];
			if ($iv[0] <= $last[1]) {
				$last[1] = max($last[1], $iv[1]);
			} else {
				$merged[] = $iv;
			}
		}
		return $merged;
	}

	/** @param list<array{0:int,1:int}> $intervals */
	private function intervalSeconds(array $intervals): int
	{
		$sum = 0;
		foreach ($intervals as $iv) {
			$sum += max(0, $iv[1] - $iv[0]);
		}
		return $sum;
	}

	/** @param list<array{0:int,1:int}> $intervals */
	private function overlapSeconds(array $intervals, int $start, int $end): int
	{
		$sum = 0;
		foreach ($intervals as $iv) {
			$sum += max(0, min($iv[1], $end) - max($iv[0], $start));
		}
		return $sum;
	}

	private function logWarning(string $message, ?\Throwable $e = null): void
	{
		$context = ['app' => 'arbeitszeitcheck'];
		if ($e !== null) {
			$context['exception'] = $e;
		}
		\OCP\Log\logger('arbeitszeitcheck')->warning('BreakPolicyResolver: ' . $message, $context);
	}
}
