<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PaidBreakAllocator;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * NN-06 property test: generated day shapes must satisfy the paid-break
 * invariants for EVERY input, not just the hand-picked cases.
 *
 * An independent oracle (re-implemented from SPEC §10, not reusing the
 * allocator) computes the expected per-entry allocation; invariants hold
 * universally:
 *  - paid_break_seconds >= 0 for every entry
 *  - paid <= the entry's own deductible break portion in the day
 *  - Σ paid == min(cap, Σ deductible) — the pool pays out exactly
 *  - waterfall: once the pool is exhausted, all later entries get 0
 *  - rejected entries always stay 0
 *  - determinism: identical inputs yield identical snapshots
 */
final class PaidBreakAllocationPropertyTest extends TestCase
{
	private const PARTICIPATING = [
		TimeEntry::STATUS_ACTIVE,
		TimeEntry::STATUS_BREAK,
		TimeEntry::STATUS_PAUSED,
		TimeEntry::STATUS_COMPLETED,
		TimeEntry::STATUS_PENDING_APPROVAL,
	];

	private const DAY_START = '2026-05-12 00:00:00';
	private const DAY_TZ = 'Europe/Berlin';

	private array $modelBreakRules = [];
	/** @var list<TimeEntry> */
	private array $entries = [];
	/** @var array<int,int> */
	private array $writes = [];

	private PaidBreakAllocator $allocator;

	protected function setUp(): void
	{
		parent::setUp();
		$this->modelBreakRules = [];
		$this->entries = [];
		$this->writes = [];

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default) => $key === 'app_timezone' ? self::DAY_TZ : $default
		);
		$config->method('getUserValue')->willReturn('');

		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->method('findUserIdsWithNonEmptySetting')->willReturn([]);

		$assignment = new UserWorkingTimeModel();
		$assignment->setUserId('u1');
		$assignment->setWorkingTimeModelId(7);
		$userWtmMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtmMapper->method('findCurrentByUser')->willReturn($assignment);
		$userWtmMapper->method('findByUserAndDate')->willReturn($assignment);

		$wtmMapper = $this->createMock(WorkingTimeModelMapper::class);
		$wtmMapper->method('find')->willReturnCallback(function (): WorkingTimeModel {
			$model = new WorkingTimeModel();
			$model->setId(7);
			$model->setBreakRules(json_encode($this->modelBreakRules));
			return $model;
		});

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findOverlapping')->willReturnCallback(
			fn () => $this->entries
		);
		$timeEntryMapper->method('update')->willReturnCallback(
			function (TimeEntry $e): TimeEntry {
				$this->writes[(int)$e->getId()] = $e->getPaidBreakSeconds();
				return $e;
			}
		);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone(self::DAY_TZ));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $text);

		$resolver = new BreakPolicyResolver(
			$config,
			new LaborLawProfileFactory($config, $userSettingsMapper),
			$userSettingsMapper,
			$userWtmMapper,
			$wtmMapper,
			$timeEntryMapper,
			$timeZoneService,
			$l10n,
		);

		$monthClosure = $this->createMock(MonthClosureService::class);
		$monthClosure->method('isMonthFinalized')->willReturn(false);

		$this->allocator = new PaidBreakAllocator(
			$timeEntryMapper,
			$resolver,
			$timeZoneService,
			$monthClosure,
			new NullLogger(),
		);
	}

	/**
	 * Independent SPEC §10 oracle: per-entry merged deductible seconds inside
	 * the day, distributed by (start_time, id) up to the cap.
	 *
	 * @return array<int,int> entry id ⇒ expected paid seconds
	 */
	private function oracle(int $dayStart, int $dayEnd, ?int $capMinutes): array
	{
		$rows = [];
		foreach ($this->entries as $entry) {
			$id = (int)$entry->getId();
			if (!in_array($entry->getStatus(), self::PARTICIPATING, true)) {
				continue;
			}
			$floor = max(0, $entry->resolveCountableMinBreakMinutes()) * 60;
			$portions = [];
			foreach ((array)json_decode((string)$entry->getBreaks(), true) as $p) {
				if (!is_array($p) || !isset($p['start'], $p['end'])) {
					continue;
				}
				$s = max(strtotime((string)$p['start']), $dayStart);
				$e = min(strtotime((string)$p['end']), $dayEnd);
				if ($e > $s && ($floor <= 0 || ($e - $s) >= $floor)) {
					$portions[] = [$s, $e];
				}
			}
			usort($portions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
			$own = 0;
			$mergeEnd = null;
			foreach ($portions as [$s, $e]) {
				if ($mergeEnd !== null && $s <= $mergeEnd) {
					$own += max(0, $e - $mergeEnd);
					$mergeEnd = max($mergeEnd, $e);
				} else {
					$own += $e - $s;
					$mergeEnd = $e;
				}
			}
			if ($own > 0) {
				$rows[] = ['id' => $id, 'start' => $entry->getStartTime()?->getTimestamp() ?? PHP_INT_MAX, 'own' => $own];
			}
		}
		usort($rows, static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['id'] <=> $b['id']);

		$rest = $capMinutes === null ? PHP_INT_MAX : $capMinutes * 60;
		$expected = [];
		foreach ($rows as $row) {
			if ($rest <= 0) {
				break;
			}
			$paid = min($row['own'], $rest);
			$expected[$row['id']] = $paid;
			$rest -= $paid;
		}
		return $expected;
	}

	/** Deterministic pseudo-random day shapes (mt_srand → reproducible). */
	private function generate(int $seed): void
	{
		mt_srand($seed);
		$this->entries = [];
		$this->writes = [];

		$tz = new \DateTimeZone(self::DAY_TZ);
		$dayStart = (new \DateTime(self::DAY_START, $tz))->getTimestamp();
		$dayEnd = $dayStart + 86400;

		$count = mt_rand(1, 4);
		for ($i = 1; $i <= $count; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId('u1');
			$entry->setStatus(mt_rand(0, 9) === 0 ? TimeEntry::STATUS_REJECTED : TimeEntry::STATUS_COMPLETED);

			$start = $dayStart + mt_rand(0, 20 * 3600);
			$end = min($dayEnd, $start + mt_rand(30 * 60, 10 * 3600));
			$entry->setStartTime((new \DateTime('@' . $start))->setTimezone($tz));
			$entry->setEndTime((new \DateTime('@' . $end))->setTimezone($tz));
			$entry->setCountableMinBreakMinutes([0, 5, 15, 30][mt_rand(0, 3)]);

			$breaks = [];
			$breakCount = mt_rand(0, 4);
			for ($b = 0; $b < $breakCount; $b++) {
				$bs = $start + mt_rand(0, max(1, $end - $start - 60));
				$be = min($end, $bs + mt_rand(3 * 60, 120 * 60));
				$breaks[] = [
					'start' => (new \DateTime('@' . $bs))->setTimezone($tz)->format(\DateTime::ATOM),
					'end' => (new \DateTime('@' . $be))->setTimezone($tz)->format(\DateTime::ATOM),
				];
			}
			if ($breaks !== []) {
				$entry->setBreaks(json_encode($breaks));
			}
			$this->entries[] = $entry;
		}

		$cap = [null, 0, mt_rand(1, 480)][mt_rand(0, 2)];
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		if ($cap !== null) {
			$this->modelBreakRules['paid_break_cap_minutes'] = $cap;
		}
	}

	public function testAllocationInvariantsHoldAcrossGeneratedDays(): void
	{
		$tz = new \DateTimeZone(self::DAY_TZ);
		$dayStart = (new \DateTime(self::DAY_START, $tz))->getTimestamp();
		$dayEnd = $dayStart + 86400;
		$from = new \DateTime(self::DAY_START, $tz);
		$to = (clone $from)->modify('+1 day');

		for ($seed = 1; $seed <= 60; $seed++) {
			$this->generate($seed);
			$cap = $this->modelBreakRules['paid_break_cap_minutes'] ?? null;
			$expected = $this->oracle($dayStart, $dayEnd, $cap);

			$this->allocator->recomputeSpan('u1', $from, $to);

			// Expected-vs-actual per entry (the oracle IS the spec, independently coded).
			foreach ($this->entries as $entry) {
				$id = (int)$entry->getId();
				$want = $entry->getStatus() === TimeEntry::STATUS_REJECTED ? 0 : ($expected[$id] ?? 0);
				$this->assertSame(
					$want,
					$entry->getPaidBreakSeconds(),
					"seed {$seed}: entry {$id} paid mismatch"
				);
			}

			// Universal invariants.
			$sumPaid = 0;
			foreach ($this->entries as $entry) {
				$paid = $entry->getPaidBreakSeconds();
				$this->assertGreaterThanOrEqual(0, $paid, "seed {$seed}: negative paid");
				if ($entry->getStatus() === TimeEntry::STATUS_REJECTED) {
					$this->assertSame(0, $paid, "seed {$seed}: rejected entry must never be paid");
				}
				if ($entry->getStatus() !== TimeEntry::STATUS_REJECTED) {
					$sumPaid += $paid;
				}
			}
			if ($cap !== null) {
				$this->assertLessThanOrEqual($cap * 60, $sumPaid, "seed {$seed}: cap exceeded");
			}

			// Pool pays out exactly: Σ paid == min(cap, Σ own deductible).
			$ownSum = array_sum($expected);
			$this->assertSame(
				min($cap === null ? PHP_INT_MAX : $cap * 60, $ownSum),
				$sumPaid,
				"seed {$seed}: pool did not pay out exactly"
			);

			// Waterfall: entries sorted by (start, id); once the pool is
			// exhausted, every later entry must be zero.
			$ordered = array_filter(
				$this->entries,
				static fn (TimeEntry $e) => in_array($e->getStatus(), self::PARTICIPATING, true)
			);
			usort($ordered, static fn (TimeEntry $a, TimeEntry $b): int =>
				($a->getStartTime()?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->getStartTime()?->getTimestamp() ?? PHP_INT_MAX)
					?: (int)$a->getId() <=> (int)$b->getId());
			$exhausted = false;
			foreach ($ordered as $entry) {
				$own = $expected[(int)$entry->getId()] ?? null;
				if ($exhausted) {
					$this->assertSame(0, $entry->getPaidBreakSeconds(), "seed {$seed}: paid after pool exhausted");
					continue;
				}
				// Entries with no deductible portion neither receive nor stop
				// the pool — only an under-paid entry exhausts it.
				if ($own !== null && $entry->getPaidBreakSeconds() < $own) {
					$exhausted = true;
				}
			}

			// Determinism: same day, same inputs → identical snapshot.
			$this->writes = [];
			$secondRun = $this->allocator->recomputeSpan('u1', $from, $to);
			$this->assertSame(0, $secondRun['changed'], "seed {$seed}: second run must be a no-op (convergent)");
		}
	}

	public function testPoolNeverExceedsDeductibleEvenWithoutCap(): void
	{
		// Focused bound check: uncapped models must pay exactly the
		// deductible, never the raw break time (sub-floor + overlaps excluded).
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$tz = new \DateTimeZone(self::DAY_TZ);
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('u1');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime(self::DAY_START . ' +8 hours', $tz));
		$entry->setEndTime(new \DateTime(self::DAY_START . ' +17 hours', $tz));
		$entry->setCountableMinBreakMinutes(15);
		$entry->setBreaks(json_encode([
			['start' => '2026-05-12T12:00:00+02:00', 'end' => '2026-05-12T12:20:00+02:00'], // 20m counts
			['start' => '2026-05-12T12:10:00+02:00', 'end' => '2026-05-12T12:25:00+02:00'], // overlaps → merged once
			['start' => '2026-05-12T15:00:00+02:00', 'end' => '2026-05-12T15:10:00+02:00'], // 10m sub-floor
		]));
		$this->entries = [$entry];

		$this->allocator->recomputeSpan(
			'u1',
			new \DateTime(self::DAY_START, $tz),
			(new \DateTime(self::DAY_START, $tz))->modify('+1 day'),
		);
		// merged 12:00–12:25 = 1500s; sub-floor excluded.
		$this->assertSame(1500, $entry->getPaidBreakSeconds());
	}
}
