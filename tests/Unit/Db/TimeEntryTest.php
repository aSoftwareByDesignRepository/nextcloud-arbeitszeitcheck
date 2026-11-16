<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use PHPUnit\Framework\TestCase;

class TimeEntryTest extends TestCase
{
	public function testGetBreakDurationHoursMergesOverlapsAndIgnoresShortBreaksByDefault(): void
	{
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		// Breaks:
		// - 10:00–10:20 (20m)
		// - 10:10–10:30 (20m) overlaps -> merged 10:00–10:30 (30m)
		// - 11:00–11:10 (10m) should be ignored (min 15m DE default)
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T10:00:00+00:00', 'end' => '2024-01-01T10:20:00+00:00'],
			['start' => '2024-01-01T10:10:00+00:00', 'end' => '2024-01-01T10:30:00+00:00'],
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001); // 30m = 0.5h
		$this->assertEqualsWithDelta(7.5, $entry->getDurationHours(), 0.0001); // 8h total - 0.5h break
	}

	public function testAzgTenMinutePortionsCountWhenFloorIsTen(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('at-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setCountableMinBreakMinutes(10);
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'],
			['start' => '2024-01-01T13:00:00+00:00', 'end' => '2024-01-01T13:10:00+00:00'],
			['start' => '2024-01-01T15:00:00+00:00', 'end' => '2024-01-01T15:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001);
		$this->assertSame([], $entry->validate(10));
	}

	public function testValidateRejectsTenMinuteBreakOnGermanFloor(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setCountableMinBreakMinutes(15);
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T12:00:00+00:00', 'end' => '2024-01-01T12:10:00+00:00'],
		]));

		$errors = $entry->validate(15);
		$this->assertArrayHasKey('breaks', $errors);
		$this->assertStringContainsString('15 minutes', $errors['breaks']);
	}

	public function testSubFloorAutomaticPortionCountsAndValidates(): void
	{
		// EC-5/MH-06: a capped overhang portion (e.g. 10 min) is below the
		// 15-min manual floor — it must still count toward the recorded net
		// and must not be rejected by validate(). The floor gates only
		// user-recorded reality, never system insertions.
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 15:10:00')); // 6h10m
		$entry->setCountableMinBreakMinutes(15);
		$entry->setBreaks(json_encode([
			[
				'start' => '2024-01-01T15:00:00+00:00',
				'end' => '2024-01-01T15:10:00+00:00',
				'duration_minutes' => 10,
				'automatic' => true,
				'capped' => true,
				'deduction_mode' => 'cap_overhang',
			],
		]));

		$this->assertEqualsWithDelta(10 / 60, $entry->getBreakDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(6.0, $entry->getDurationHours(), 0.0001);
		$errors = $entry->validate(15);
		$this->assertArrayNotHasKey('breaks', $errors);
	}

	public function testSubFloorManualPortionStillRejectedBesideAutomatic(): void
	{
		// A manual sub-floor portion must still be flagged even when a valid
		// automatic portion shares the entry (NN-06 boundary).
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 18:00:00'));
		$entry->setCountableMinBreakMinutes(15);
		$entry->setBreaks(json_encode([
			[
				'start' => '2024-01-01T17:50:00+00:00',
				'end' => '2024-01-01T18:00:00+00:00',
				'automatic' => true,
				'capped' => true,
			],
			['start' => '2024-01-01T12:00:00+00:00', 'end' => '2024-01-01T12:10:00+00:00'],
		]));

		$this->assertEqualsWithDelta(10 / 60, $entry->getBreakDurationHours(), 0.0001,
			'manual 10m stays uncounted, automatic 10m counts');
		$errors = $entry->validate(15);
		$this->assertArrayHasKey('breaks', $errors);
		$this->assertStringContainsString('Break #2', $errors['breaks']);
	}

	public function testCanDeleteManualCompletedEntry(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('-30 days'));

		$this->assertTrue($entry->canDelete());
	}

	public function testCanDeleteStampedEntryWithinEditWindow(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('-3 days 09:00:00'));
		$entry->setEndTime(new \DateTime('-3 days 17:00:00'));

		$this->assertTrue($entry->canDelete());
	}

	public function testCannotDeleteStampedEntryOutsideEditWindow(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		$this->assertFalse($entry->canDelete());
	}

	public function testCannotDeleteActiveStampedSession(): void
	{
		$entry = new TimeEntry();
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-1 hour'));

		$this->assertFalse($entry->canDelete());
	}

	public function testGetBreakDurationHoursCanIncludeShortBreaksWhenConfigured(): void
	{
		$entry = new TimeEntry();
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));

		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T11:00:00+00:00', 'end' => '2024-01-01T11:10:00+00:00'], // 10m
		]));

		$this->assertEqualsWithDelta((10 / 60), $entry->getBreakDurationHours(false), 0.0001);
	}

	public function testPendingApprovalWithoutEndOccupiesLikePaused(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$updated = new \DateTime('2026-08-30T10:00:00+00:00');

		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);
		$entry->setUpdatedAt($updated);

		$end = $entry->occupancyEnd($now);
		$this->assertNotNull($end);
		$this->assertSame('2026-08-30T10:00:00+00:00', $end->format('c'));
	}

	public function testPendingApprovalWithEndUsesRealEnd(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(new \DateTime('2026-08-30T16:00:00+00:00'));

		$end = $entry->occupancyEnd($now);
		$this->assertSame('2026-08-30T16:00:00+00:00', $end->format('c'));
	}

	public function testCompletedWithoutEndDoesNotOccupy(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);

		$this->assertNull($entry->occupancyEnd($now));
	}

	public function testActiveWithoutEndOccupiesUntilNow(): void
	{
		$now = new \DateTime('2026-08-30T12:00:00+00:00');
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('2026-08-30T08:00:00+00:00'));
		$entry->setEndTime(null);

		$end = $entry->occupancyEnd($now);
		$this->assertSame('2026-08-30T12:00:00+00:00', $end->format('c'));
	}

	public function testPaidBreakSnapshotDefaultsToZero(): void
	{
		$entry = new TimeEntry();
		$this->assertSame(0, $entry->getPaidBreakSeconds());
		$this->assertEqualsWithDelta(0.0, $entry->getPaidBreakHours(), 0.0001);
	}

	public function testPaidDurationIsNetPlusSnapshot(): void
	{
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T12:00:00+00:00', 'end' => '2024-01-01T12:30:00+00:00'],
		]));

		// Net = 8h span − 30min break = 7.5h; snapshot adds 30min back ⇒ 8h paid.
		$this->assertEqualsWithDelta(7.5, $entry->getDurationHours(), 0.0001);
		$entry->setPaidBreakSeconds(1800);
		$this->assertEqualsWithDelta(0.5, $entry->getPaidBreakHours(), 0.0001);
		$this->assertEqualsWithDelta(8.0, $entry->getPaidDurationHours(), 0.0001);
	}

	public function testPaidDurationEqualsNetWithoutSnapshot(): void
	{
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setBreaks(json_encode([
			['start' => '2024-01-01T12:00:00+00:00', 'end' => '2024-01-01T12:30:00+00:00'],
		]));

		$this->assertSame($entry->getDurationHours(), $entry->getPaidDurationHours());
	}

	public function testSummaryExposesPaidFieldsAdditively(): void
	{
		$entry = new TimeEntry();
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$entry->setPaidBreakSeconds(900);

		$summary = $entry->getSummary();
		$this->assertSame(900, $summary['paidBreakSeconds']);
		$this->assertSame(15, $summary['paidBreakMinutes']);
		$this->assertEqualsWithDelta(0.25, $summary['paidBreakHours'], 0.0001);
		$this->assertArrayHasKey('paidDurationHours', $summary);
		$this->assertArrayHasKey('durationHours', $summary);
	}

	/* ------------------------------------------------------------------
	 * Split-service context (spec split-day-working-model §9.5/§9.6/§9.8)
	 * ------------------------------------------------------------------ */

	/**
	 * Canonical F1 context (spec §8 A04 / §9.5): split applied,
	 * interruption 10:00–13:30 excluded (12,600 s), break covered.
	 */
	private function splitContextFixture(string $state = 'applied'): array
	{
		return [
			'v' => 1,
			'model_id' => 123,
			'schedule_revision' => 'deadbeef',
			'timezone' => 'Europe/Berlin',
			'day' => ['blocks' => [['06:00', '10:00'], ['13:30', '16:30']]],
			'split' => ['automatic' => true, 'cutoff' => '16:00', 'break_cover' => true],
			'overrides' => ['decision' => 'auto', 'worked' => []],
			'result' => [
				'state' => $state,
				'split' => true,
				'reason' => 'clock_out_after_cutoff',
				'excluded' => [['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']],
				'segments' => [
					['2026-11-16T06:00:00+01:00', '2026-11-16T10:00:00+01:00'],
					['2026-11-16T13:30:00+01:00', '2026-11-16T16:30:00+01:00'],
				],
				'break_handling' => 'covered_by_interruption',
				'engine' => 1,
			],
		];
	}

	private function f1Entry(): TimeEntry
	{
		// 06:00–16:30 = 37,800 s presence (F1, spec §8 A04).
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2026-11-16T06:00:00+01:00'));
		$entry->setEndTime(new \DateTime('2026-11-16T16:30:00+01:00'));
		return $entry;
	}

	public function testNullContextKeepsByteIdenticalLegacyPath(): void
	{
		$entry = $this->f1Entry();
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T12:00:00+01:00', 'end' => '2026-11-16T12:30:00+01:00'],
		]));

		$this->assertNull($entry->getWorkContextJson());
		$this->assertNull($entry->getWorkContext());
		$this->assertNull($entry->getAccountingResult());
		$this->assertFalse($entry->hasInvalidWorkContext());
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
		$this->assertSame($entry->getBreakDurationHours(), $entry->getEffectiveBreakDurationHours());

		// 10.5 h presence − 0.5 h break = 10.0 h, exactly the legacy result.
		$this->assertEqualsWithDelta(10.0, $entry->getDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(10.5, $entry->getTotalDurationHours(), 0.0001);

		$summary = $entry->getSummary();
		$this->assertArrayNotHasKey('accounting', $summary);
	}

	public function testEmptyStringContextBehavesAsNull(): void
	{
		$entry = $this->f1Entry();
		$entry->setWorkContextJson('');

		$this->assertNull($entry->getWorkContext());
		$this->assertFalse($entry->hasInvalidWorkContext());
		$this->assertEqualsWithDelta(10.5, $entry->getDurationHours(), 0.0001);
		$this->assertArrayNotHasKey('accounting', $entry->getSummary());
	}

	public function testSplitContextDurationMathF1(): void
	{
		// A04/F1: |R| = 37,800 s, X = 12,600 s, no recorded breaks
		// → net = 25,200 s = 7.0 h.
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture()));
		$entry->setExcludedWorkSeconds(12600);

		$this->assertFalse($entry->hasInvalidWorkContext());
		$this->assertEqualsWithDelta(7.0, $entry->getDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(7.0, $entry->getWorkingDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(7.0, $entry->getPaidDurationHours(), 0.0001);
		// Wall-clock span and recorded break evidence stay untouched.
		$this->assertEqualsWithDelta(10.5, $entry->getTotalDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(0.0, $entry->getBreakDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(0.0, $entry->getEffectiveBreakDurationHours(), 0.0001);
	}

	public function testBreakInsideExclusionContributesNothingA12(): void
	{
		// A12: manual break 11:00–11:45 lies inside X — evidence kept but
		// deducts 0 extra (B \ X); net stays 25,200 s.
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture()));
		$entry->setExcludedWorkSeconds(12600);
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T11:00:00+01:00', 'end' => '2026-11-16T11:45:00+01:00'],
		]));

		$this->assertEqualsWithDelta(0.75, $entry->getBreakDurationHours(), 0.0001,
			'recorded break evidence preserved (45 min)');
		$this->assertEqualsWithDelta(0.0, $entry->getEffectiveBreakDurationHours(), 0.0001,
			'break fully inside X deducts nothing extra');
		$this->assertEqualsWithDelta(7.0, $entry->getDurationHours(), 0.0001);
	}

	public function testBreakPartiallyInsideExclusionCountsOnlyOutside(): void
	{
		// Break 09:45–10:15 (1,800 s) straddles X start: only the 900 s
		// outside X are effective → net = 37,800 − 12,600 − 900 = 24,300 s.
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture()));
		$entry->setExcludedWorkSeconds(12600);
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T09:45:00+01:00', 'end' => '2026-11-16T10:15:00+01:00'],
		]));

		$this->assertEqualsWithDelta(0.5, $entry->getBreakDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(0.25, $entry->getEffectiveBreakDurationHours(), 0.0001);
		$this->assertEqualsWithDelta(6.75, $entry->getDurationHours(), 0.0001);
	}

	public function testExcludedOnlyContextWithoutBreaks(): void
	{
		// Smaller context: single exclusion 10:00–11:00, state applied,
		// no recorded breaks → net = 14,400 − 3,600 = 10,800 s = 3.0 h.
		$entry = new TimeEntry();
		$entry->setUserId('de-user');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2026-11-16T08:00:00+01:00'));
		$entry->setEndTime(new \DateTime('2026-11-16T12:00:00+01:00'));

		$context = $this->splitContextFixture();
		$context['result']['excluded'] = [['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']];
		$context['result']['segments'] = [
			['2026-11-16T08:00:00+01:00', '2026-11-16T10:00:00+01:00'],
			['2026-11-16T11:00:00+01:00', '2026-11-16T12:00:00+01:00'],
		];
		$context['result']['break_handling'] = 'standard';
		$entry->setWorkContextJson(json_encode($context));
		$entry->setExcludedWorkSeconds(3600);

		$this->assertEqualsWithDelta(3.0, $entry->getDurationHours(), 0.0001);
		$summary = $entry->getSummary();
		$this->assertSame(3600, $summary['accounting']['excludedSeconds']);
		$this->assertSame(10800, $summary['accounting']['netWorkSeconds']);
		$this->assertSame('standard', $summary['accounting']['breakHandling']);
	}

	public function testInvalidContextJsonFlagsDefectAndKeepsLegacyResult(): void
	{
		// FM-11: corrupt non-NULL context — never a silent legacy fallback:
		// getWorkContext() is null AND hasInvalidWorkContext() flags it.
		$entry = $this->f1Entry();
		$entry->setWorkContextJson('{"result":{"state":"applied",');
		$entry->setExcludedWorkSeconds(12600); // column present but context corrupt
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T12:00:00+01:00', 'end' => '2026-11-16T12:30:00+01:00'],
		]));

		$this->assertTrue($entry->hasInvalidWorkContext());
		$this->assertNull($entry->getWorkContext());
		$this->assertNull($entry->getAccountingResult());

		// Conservative legacy result: 10.5 − 0.5 = 10.0 h; the excluded
		// column is NOT trusted without a decodable context.
		$this->assertEqualsWithDelta(10.0, $entry->getDurationHours(), 0.0001);
		$this->assertSame($entry->getBreakDurationHours(), $entry->getEffectiveBreakDurationHours());

		$this->assertArrayNotHasKey('accounting', $entry->getSummary());
	}

	public function testScalarContextJsonCountsAsInvalid(): void
	{
		$entry = $this->f1Entry();
		$entry->setWorkContextJson('42');

		$this->assertTrue($entry->hasInvalidWorkContext());
		$this->assertNull($entry->getWorkContext());
		$this->assertEqualsWithDelta(10.5, $entry->getDurationHours(), 0.0001);
	}

	public function testActiveContextStaysOnLegacyDuration(): void
	{
		// state `active` = open/provisional entry → legacy result, the
		// stored exclusion is not applied on reads.
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture('active')));
		$entry->setExcludedWorkSeconds(12600);
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T12:00:00+01:00', 'end' => '2026-11-16T12:30:00+01:00'],
		]));

		$this->assertEqualsWithDelta(10.0, $entry->getDurationHours(), 0.0001);
		$this->assertSame($entry->getBreakDurationHours(), $entry->getEffectiveBreakDurationHours());

		// Context is still valid → summary carries the provisional block.
		$accounting = $entry->getSummary()['accounting'] ?? null;
		$this->assertNotNull($accounting);
		$this->assertSame('active', $accounting['state']);
		$this->assertSame(36000, $accounting['netWorkSeconds']);
	}

	public function testReviewStateAccountsUnsplitLikeLegacy(): void
	{
		// `review` entries are accounted unsplit (spec §9.6 closure rule):
		// X is empty, so the formula reduces to the legacy result.
		$context = $this->splitContextFixture('review');
		$context['result']['split'] = false;
		$context['result']['reason'] = 'dst_review';
		$context['result']['excluded'] = [];
		$context['result']['segments'] = [['2026-11-16T06:00:00+01:00', '2026-11-16T16:30:00+01:00']];
		$context['result']['break_handling'] = 'not_applicable';

		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($context));
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T12:00:00+01:00', 'end' => '2026-11-16T12:30:00+01:00'],
		]));

		$this->assertEqualsWithDelta(10.0, $entry->getDurationHours(), 0.0001);
		$this->assertSame('not_applicable', $entry->getSummary()['accounting']['breakHandling']);
	}

	public function testSummaryAccountingBlockShapeF1(): void
	{
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture()));
		$entry->setExcludedWorkSeconds(12600);

		$summary = $entry->getSummary();
		$this->assertArrayHasKey('accounting', $summary);

		$accounting = $summary['accounting'];
		$this->assertSame(2, $accounting['contractVersion']);
		$this->assertSame('applied', $accounting['state']);
		$this->assertTrue($accounting['split']);
		$this->assertSame('clock_out_after_cutoff', $accounting['reason']);
		$this->assertSame([
			['no' => 1, 'start' => '2026-11-16T06:00:00+01:00', 'end' => '2026-11-16T10:00:00+01:00', 'workSeconds' => 14400],
			['no' => 2, 'start' => '2026-11-16T13:30:00+01:00', 'end' => '2026-11-16T16:30:00+01:00', 'workSeconds' => 10800],
		], $accounting['segments']);
		$this->assertSame([
			['start' => '2026-11-16T10:00:00+01:00', 'end' => '2026-11-16T13:30:00+01:00', 'seconds' => 12600],
		], $accounting['interruptions']);
		$this->assertSame(12600, $accounting['excludedSeconds']);
		$this->assertSame('covered_by_interruption', $accounting['breakHandling']);
		$this->assertSame(25200, $accounting['netWorkSeconds']);
		$this->assertSame(25200, $accounting['paidWorkSeconds']);

		// Existing fields keep meaning: original clocking + evidence.
		$this->assertSame('2026-11-16T06:00:00+01:00', $summary['startTime']);
		$this->assertSame('2026-11-16T16:30:00+01:00', $summary['endTime']);
		$this->assertEqualsWithDelta(7.0, $summary['durationHours'], 0.0001);
		$this->assertEqualsWithDelta(7.0, $summary['workingDurationHours'], 0.0001);
	}

	public function testAccountingSummaryIncludesPaidBreakSnapshot(): void
	{
		// paidWorkSeconds = getPaidDurationHours × 3600 (net + paid snapshot).
		$entry = $this->f1Entry();
		$entry->setWorkContextJson(json_encode($this->splitContextFixture()));
		$entry->setExcludedWorkSeconds(12600);
		$entry->setPaidBreakSeconds(1800);

		$accounting = $entry->getSummary()['accounting'];
		$this->assertSame(25200, $accounting['netWorkSeconds']);
		$this->assertSame(27000, $accounting['paidWorkSeconds']);
	}

	public function testContextSettersMarkFieldsUpdatedAndClamp(): void
	{
		$entry = new TimeEntry();

		$entry->setWorkContextJson('{"v":1}');
		$this->assertSame('{"v":1}', $entry->getWorkContextJson());
		$this->assertArrayHasKey('workContextJson', $entry->getUpdatedFields());

		$entry->setExcludedWorkSeconds(-5);
		$this->assertSame(0, $entry->getExcludedWorkSeconds(), 'negative clamps to 0');

		$entry->setExcludedWorkSeconds(120);
		$this->assertSame(120, $entry->getExcludedWorkSeconds());
		$this->assertArrayHasKey('excludedWorkSeconds', $entry->getUpdatedFields());
	}
}
