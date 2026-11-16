<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\SplitServiceAccounting as S;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Engine fixtures per split-day SPEC §8 / §9.6. All expectations are derived
 * from the normative algorithm; Europe/Berlin 2026-11-16 has no DST (+01:00).
 */
class SplitServiceAccountingTest extends TestCase
{
	private const TZ = 'Europe/Berlin';

	private static function ts(string $local, string $tz = self::TZ): int
	{
		return (new \DateTimeImmutable($local, new \DateTimeZone($tz)))->getTimestamp();
	}

	/** Model M: blocks 06:00–10:00 + 13:30–16:30, gap 10:00–13:30, cutoff 16:00. */
	private static function ctx(
		array $split = [],
		array $overrides = [],
		array $blocks = [['06:00', '10:00'], ['13:30', '16:30']],
		string $tz = self::TZ,
		string $date = '2026-11-16',
	): array {
		$day = ['blocks' => $blocks];
		return [
			'v' => 1,
			'model_id' => 123,
			'schedule_revision' => 'deadbeef',
			'timezone' => $tz,
			'day' => $day,
			'week' => [
				'mon' => $blocks, 'tue' => $blocks, 'wed' => $blocks,
				'thu' => $blocks, 'fri' => $blocks, 'sat' => [], 'sun' => [],
			],
			'split' => array_merge(
				['automatic' => true, 'cutoff' => '16:00', 'break_cover' => true],
				$split
			),
			'overrides' => array_merge(
				['decision' => 'auto', 'worked' => []],
				$overrides
			),
		];
	}

	private static function worked(string $startLocal, string $endLocal, string $tz = self::TZ): array
	{
		return [
			(new \DateTimeImmutable($startLocal, new \DateTimeZone($tz)))->format('c'),
			(new \DateTimeImmutable($endLocal, new \DateTimeZone($tz)))->format('c'),
		];
	}

	// ------------------------------------------------------------- //
	// A04 / F1 — canonical split                                     //
	// ------------------------------------------------------------- //

	public function testA04LongDaySplits(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertSame('applied', $r['state']);
		$this->assertTrue($r['split']);
		$this->assertSame('clock_out_after_cutoff', $r['reason']);
		$this->assertNull($r['error']);
		$this->assertSame(12600, $r['excludedSeconds']); // 10:00–13:30 = 210 min
		$this->assertSame(
			[[self::ts('2026-11-16 10:00:00'), self::ts('2026-11-16 13:30:00')]],
			$r['excludedEpoch']
		);
		$this->assertSame(
			[
				[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 16:30:00')],
			],
			$r['segmentsEpoch']
		);
		$this->assertSame(14400, $r['segmentsEpoch'][0][1] - $r['segmentsEpoch'][0][0]);
		$this->assertSame(10800, $r['segmentsEpoch'][1][1] - $r['segmentsEpoch'][1][0]);
		$this->assertSame(1, $r['engine']);
		// ISO output in context timezone, format 'c'.
		$this->assertSame('2026-11-16T10:00:00+01:00', $r['excluded'][0][0]);
		$this->assertSame('2026-11-16T13:30:00+01:00', $r['excluded'][0][1]);
		$this->assertSame('2026-11-16T06:00:00+01:00', $r['segments'][0][0]);
		$this->assertSame('2026-11-16T16:30:00+01:00', $r['segments'][1][1]);
	}

	// ------------------------------------------------------------- //
	// Cut-off boundaries A06/A07/A08 (e > cutoff strictly)           //
	// ------------------------------------------------------------- //

	public function testA06ClockOutBeforeCutoffStaysContinuous(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 13:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('not_after_cutoff', $r['reason']);
		$this->assertSame([], $r['excludedEpoch']);
		$this->assertSame(0, $r['excludedSeconds']);
		$this->assertSame(
			[[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 13:30:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testA07ClockOutExactlyAtCutoffDoesNotSplit(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:00:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('not_after_cutoff', $r['reason']);
		$this->assertSame(0, $r['excludedSeconds']);
	}

	public function testA08ClockOutOneSecondAfterCutoffSplits(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:00:01'));
		$this->assertTrue($r['split']);
		$this->assertSame('clock_out_after_cutoff', $r['reason']);
		$this->assertSame(12600, $r['excludedSeconds']);
		// own work = raw 36,001 s − 12,600 s = 23,401 s (A08)
		$this->assertSame(23401, S::totalSeconds($r['segmentsEpoch']));
		$this->assertSame(
			[
				[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 16:00:01')],
			],
			$r['segmentsEpoch']
		);
	}

	public function testCutoffMinusOneSecondDoesNotSplit(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 15:59:59'));
		$this->assertFalse($r['split']);
		$this->assertSame('not_after_cutoff', $r['reason']);
	}

	// ------------------------------------------------------------- //
	// A09 / gap-start candidacy boundaries                           //
	// ------------------------------------------------------------- //

	public function testA09ClockInDuringInterruption(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 10:45:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('clock_in_during_interruption', $r['reason']);
		$this->assertSame([], $r['excludedEpoch']);
		$this->assertSame(
			[[self::ts('2026-11-16 10:45:00'), self::ts('2026-11-16 16:30:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testClockInExactlyAtGapStartIsCandidate(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 10:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(12600, $r['excludedSeconds']);
	}

	public function testClockInOneSecondBeforeGapStartIsCandidate(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 09:59:59'), self::ts('2026-11-16 16:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(12600, $r['excludedSeconds']);
		$this->assertSame(
			[
				[self::ts('2026-11-16 09:59:59'), self::ts('2026-11-16 10:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 16:30:00')],
			],
			$r['segmentsEpoch']
		);
	}

	public function testClockInOneSecondAfterGapStartIsNotCandidate(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 10:00:01'), self::ts('2026-11-16 16:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('clock_in_during_interruption', $r['reason']);
	}

	public function testClockInAtGapEndIsNotCandidate(): void
	{
		// A10 second explicit entry 13:30–16:30: no candidate gap → excluded 0.
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('clock_in_during_interruption', $r['reason']);
		$this->assertSame(0, $r['excludedSeconds']);
	}

	public function testA10FirstExplicitEntryAlsoExcludesNothing(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('not_after_cutoff', $r['reason']);
		$this->assertSame(0, $r['excludedSeconds']);
	}

	// ------------------------------------------------------------- //
	// A14 / F9 — work outside planned blocks is never clipped        //
	// ------------------------------------------------------------- //

	public function testA14WorkOutsideBlocksKept(): void
	{
		$r = S::resolveSplit(self::ctx(), self::ts('2026-11-16 05:30:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(12600, $r['excludedSeconds']);
		$this->assertSame(
			[
				[self::ts('2026-11-16 05:30:00'), self::ts('2026-11-16 10:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 18:00:00')],
			],
			$r['segmentsEpoch']
		);
		$this->assertSame(32400, S::totalSeconds($r['segmentsEpoch']));
	}

	public function testEntryEndingInsideGapExcludesOnlyOverlap(): void
	{
		$ctx = self::ctx(['cutoff' => '10:30']);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 11:00:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(
			[[self::ts('2026-11-16 10:00:00'), self::ts('2026-11-16 11:00:00')]],
			$r['excludedEpoch']
		);
		$this->assertSame(
			[[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00')]],
			$r['segmentsEpoch']
		);
	}

	// ------------------------------------------------------------- //
	// Three-block day: two gaps, both excluded                       //
	// ------------------------------------------------------------- //

	public function testThreeBlockDayExcludesBothGaps(): void
	{
		$ctx = self::ctx(
			['cutoff' => '17:00'],
			[],
			[['06:00', '10:00'], ['12:00', '14:00'], ['16:00', '18:00']]
		);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(
			[
				[self::ts('2026-11-16 10:00:00'), self::ts('2026-11-16 12:00:00')],
				[self::ts('2026-11-16 14:00:00'), self::ts('2026-11-16 16:00:00')],
			],
			$r['excludedEpoch']
		);
		$this->assertSame(14400, $r['excludedSeconds']);
		$this->assertSame(3, count($r['segmentsEpoch']));
	}

	public function testThreeBlockDayClockInBetweenGapsExcludesOnlyLater(): void
	{
		$ctx = self::ctx(
			['cutoff' => '17:00'],
			[],
			[['06:00', '10:00'], ['12:00', '14:00'], ['16:00', '18:00']]
		);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 11:00:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(
			[[self::ts('2026-11-16 14:00:00'), self::ts('2026-11-16 16:00:00')]],
			$r['excludedEpoch']
		);
	}

	// ------------------------------------------------------------- //
	// Options off (A03) / frozen cut-off (A16)                       //
	// ------------------------------------------------------------- //

	public function testA03AutomaticOffNeverSplits(): void
	{
		$ctx = self::ctx(['automatic' => false, 'cutoff' => null]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertFalse($r['split']);
		$this->assertSame(0, $r['excludedSeconds']);
		$this->assertSame(1, count($r['segmentsEpoch']));
	}

	public function testA16FrozenCutoffAppliesNotCurrent(): void
	{
		// Model was edited to 17:00 after clock-in; the context froze 16:00.
		$r = S::resolveSplit(self::ctx(['cutoff' => '16:00']), self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertTrue($r['split']);
	}

	// ------------------------------------------------------------- //
	// No interruption today                                          //
	// ------------------------------------------------------------- //

	public function testSingleBlockDayHasNoInterruption(): void
	{
		$ctx = self::ctx([], [], [['08:00', '12:00']]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('no_interruption_today', $r['reason']);
		$this->assertSame(
			[[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 18:00:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testEmptyBlocksDayHasNoInterruption(): void
	{
		$ctx = self::ctx([], [], []);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 18:00:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('no_interruption_today', $r['reason']);
	}

	// ------------------------------------------------------------- //
	// A29 crosses midnight / A30 DST                                 //
	// ------------------------------------------------------------- //

	public function testA29CrossesMidnight(): void
	{
		$r = S::resolveSplit(
			self::ctx(),
			self::ts('2026-11-16 22:00:00'),
			self::ts('2026-11-17 02:00:00')
		);
		$this->assertFalse($r['split']);
		$this->assertSame('crosses_midnight', $r['reason']);
		$this->assertSame('applied', $r['state']);
		$this->assertSame(0, $r['excludedSeconds']);
	}

	public function testA30NonexistentCutoffTimeOnSpringForward(): void
	{
		// 2027-03-28 Europe/Berlin: 02:00→03:00 CEST, 02:30 does not exist.
		$ctx = self::ctx(['cutoff' => '02:30'], [], [['06:00', '10:00'], ['13:30', '16:30']], self::TZ, '2027-03-28');
		$r = S::resolveSplit($ctx, self::ts('2027-03-28 06:00:00'), self::ts('2027-03-28 16:30:00'));
		$this->assertSame('review', $r['state']);
		$this->assertFalse($r['split']);
		$this->assertSame('dst_review', $r['reason']);
		$this->assertSame(
			[[self::ts('2027-03-28 06:00:00'), self::ts('2027-03-28 16:30:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testAmbiguousBoundaryOnFallBack(): void
	{
		// 2027-10-31 Europe/Berlin: 03:00→02:00 CET, 02:30 occurs twice.
		$ctx = self::ctx(['cutoff' => '02:30'], [], [['06:00', '10:00'], ['13:30', '16:30']], self::TZ, '2027-10-31');
		$r = S::resolveSplit($ctx, self::ts('2027-10-31 06:00:00'), self::ts('2027-10-31 16:30:00'));
		$this->assertSame('review', $r['state']);
		$this->assertSame('dst_review', $r['reason']);
	}

	public function testDstDayWithUnambiguousBoundariesStillSplits(): void
	{
		// Spring-forward day, but all boundaries (06/10/13:30/16:30/16:00) exist once.
		$ctx = self::ctx([], [], [['06:00', '10:00'], ['13:30', '16:30']], self::TZ, '2027-03-28');
		$r = S::resolveSplit($ctx, self::ts('2027-03-28 06:00:00'), self::ts('2027-03-28 16:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(12600, $r['excludedSeconds']);
	}

	public function testDstReviewTakesPrecedenceOverOverride(): void
	{
		$ctx = self::ctx(
			['cutoff' => '02:30'],
			['decision' => 'force_split'],
			[['06:00', '10:00'], ['13:30', '16:30']],
			self::TZ,
			'2027-03-28'
		);
		$r = S::resolveSplit($ctx, self::ts('2027-03-28 06:00:00'), self::ts('2027-03-28 16:30:00'));
		$this->assertSame('review', $r['state']);
		$this->assertNull($r['error']);
	}

	// ------------------------------------------------------------- //
	// Active preview (endTs null)                                    //
	// ------------------------------------------------------------- //

	public function testActivePreviewUsesInjectedNow(): void
	{
		$r = S::resolveSplit(
			self::ctx(),
			self::ts('2026-11-16 06:00:00'),
			null,
			self::ts('2026-11-16 16:30:00')
		);
		$this->assertSame('active', $r['state']);
		$this->assertTrue($r['split']);
		$this->assertSame(12600, $r['excludedSeconds']);
	}

	public function testActivePreviewBeforeCutoffShowsContinuous(): void
	{
		$r = S::resolveSplit(
			self::ctx(),
			self::ts('2026-11-16 06:00:00'),
			null,
			self::ts('2026-11-16 12:00:00')
		);
		$this->assertSame('active', $r['state']);
		$this->assertFalse($r['split']);
		$this->assertSame('not_after_cutoff', $r['reason']);
	}

	public function testActivePreviewWithoutNowThrows(): void
	{
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit(self::ctx(), self::ts('2026-11-16 06:00:00'), null, null);
	}

	public function testEndBeforeStartThrows(): void
	{
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit(self::ctx(), self::ts('2026-11-16 16:00:00'), self::ts('2026-11-16 06:00:00'));
	}

	// ------------------------------------------------------------- //
	// Overrides: force_continuous / force_split (A18, US-005)        //
	// ------------------------------------------------------------- //

	public function testA18ForceContinuous(): void
	{
		$ctx = self::ctx([], ['decision' => 'force_continuous']);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('override_continuous', $r['reason']);
		$this->assertSame(0, $r['excludedSeconds']);
		$this->assertSame(37800, S::totalSeconds($r['segmentsEpoch']));
	}

	public function testA18ForceSplitBelowCutoff(): void
	{
		// Approved "split" on 06:00–15:30 (not after cut-off) → split anyway.
		$ctx = self::ctx([], ['decision' => 'force_split']);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 15:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame('override_split', $r['reason']);
		$this->assertSame(12600, $r['excludedSeconds']);
		$this->assertSame(
			[
				[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 15:30:00')],
			],
			$r['segmentsEpoch']
		);
	}

	public function testForceSplitAppliesWhenEndEqualsGapEnd(): void
	{
		// Boundary: clock-out exactly at the interruption's end still covers the
		// whole gap, so the force-split override is applicable (spec §9.7).
		$ctx = self::ctx([], ['decision' => 'force_split']);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 13:30:00'));
		$this->assertNull($r['error']);
		$this->assertTrue($r['split']);
		$this->assertSame('override_split', $r['reason']);
		$this->assertSame(12600, $r['excludedSeconds']);
		$this->assertSame(
			[[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 10:00:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testForceSplitNotApplicableWhenEndInsideGap(): void
	{
		$ctx = self::ctx([], ['decision' => 'force_split']);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 12:00:00'));
		$this->assertSame(S::ERR_OVERRIDE_NOT_APPLICABLE, $r['error']);
		$this->assertSame('SPLIT_OVERRIDE_NOT_APPLICABLE', $r['error']);
		$this->assertFalse($r['split']);
		$this->assertNull($r['reason']);
		$this->assertSame([], $r['excludedEpoch']);
		$this->assertSame([], $r['segmentsEpoch']);
	}

	public function testForceSplitNotApplicableWithoutCandidateGap(): void
	{
		$ctx = self::ctx([], ['decision' => 'force_split']);
		// Clock-in during the interruption → candidacy check fires first.
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 10:45:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertFalse($r['split']);
		$this->assertSame('clock_in_during_interruption', $r['reason']);
		$this->assertNull($r['error']);
	}

	// ------------------------------------------------------------- //
	// A17 worked overrides                                           //
	// ------------------------------------------------------------- //

	public function testA17WorkedOverridePartial(): void
	{
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 10:00:00', '2026-11-16 11:00:00')]]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(9000, $r['excludedSeconds']); // 150 min
		$this->assertSame(
			[[self::ts('2026-11-16 11:00:00'), self::ts('2026-11-16 13:30:00')]],
			$r['excludedEpoch']
		);
		$this->assertSame(
			[
				[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 11:00:00')],
				[self::ts('2026-11-16 13:30:00'), self::ts('2026-11-16 16:30:00')],
			],
			$r['segmentsEpoch']
		);
	}

	public function testWorkedOverrideCoveringWholeGapLeavesNoExclusion(): void
	{
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 10:00:00', '2026-11-16 13:30:00')]]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertTrue($r['split']);
		$this->assertSame(0, $r['excludedSeconds']);
		$this->assertSame(
			[[self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00')]],
			$r['segmentsEpoch']
		);
	}

	public function testWorkedOutsideGapIsIgnored(): void
	{
		// 14:00–15:00 lies inside the second block, not in any gap.
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 14:00:00', '2026-11-16 15:00:00')]]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertSame(12600, $r['excludedSeconds']);
	}

	public function testWorkedPartiallyInsideGapIsClipped(): void
	{
		// 09:30–10:30: only 10:00–10:30 lies inside the gap.
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 09:30:00', '2026-11-16 10:30:00')]]);
		$r = S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
		$this->assertSame(12600 - 1800, $r['excludedSeconds']);
		$this->assertSame(
			[[self::ts('2026-11-16 10:30:00'), self::ts('2026-11-16 13:30:00')]],
			$r['excludedEpoch']
		);
	}

	public function testNineWorkedIntervalsThrow(): void
	{
		$worked = [];
		for ($i = 0; $i < 9; $i++) {
			$worked[] = self::worked('2026-11-16 10:0' . $i . ':00', '2026-11-16 10:0' . $i . ':30');
		}
		$ctx = self::ctx([], ['worked' => $worked]);
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
	}

	public function testWorkedWithReversedIntervalThrows(): void
	{
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 11:00:00', '2026-11-16 10:00:00')]]);
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
	}

	public function testWorkedWithGarbageTimestampThrows(): void
	{
		$ctx = self::ctx([], ['worked' => [['next Tuesday', '2026-11-16T11:00:00+01:00']]]);
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
	}

	// ------------------------------------------------------------- //
	// Corrupt context → InvalidArgumentException, never silent       //
	// ------------------------------------------------------------- //

	/**
	 * @dataProvider corruptContexts
	 */
	public function testCorruptContextThrows(\Closure $mutate): void
	{
		$ctx = self::ctx();
		$mutate($ctx);
		$this->expectException(InvalidArgumentException::class);
		S::resolveSplit($ctx, self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00'));
	}

	public static function corruptContexts(): array
	{
		return [
			'v missing' => [function (&$c): void { unset($c['v']); }],
			'v wrong version' => [function (&$c): void { $c['v'] = 2; }],
			'v string' => [function (&$c): void { $c['v'] = '1'; }],
			'timezone missing' => [function (&$c): void { unset($c['timezone']); }],
			'timezone invalid' => [function (&$c): void { $c['timezone'] = 'Mars/Olympus'; }],
			'timezone non-string' => [function (&$c): void { $c['timezone'] = 42; }],
			'day missing' => [function (&$c): void { unset($c['day']); }],
			'blocks missing' => [function (&$c): void { unset($c['day']['blocks']); }],
			'blocks not list' => [function (&$c): void { $c['day']['blocks'] = ['a' => ['06:00', '10:00']]; }],
			'block bad shape' => [function (&$c): void { $c['day']['blocks'] = [['06:00']]; }],
			'block bad time' => [function (&$c): void { $c['day']['blocks'] = [['6:00', '10:00'], ['13:30', '16:30']]; }],
			'block 24:00' => [function (&$c): void { $c['day']['blocks'] = [['00:00', '24:00']]; }],
			'block reversed' => [function (&$c): void { $c['day']['blocks'] = [['10:00', '06:00'], ['13:30', '16:30']]; }],
			'blocks unsorted' => [function (&$c): void { $c['day']['blocks'] = [['13:30', '16:30'], ['06:00', '10:00']]; }],
			'blocks touching' => [function (&$c): void { $c['day']['blocks'] = [['06:00', '10:00'], ['10:00', '16:30']]; }],
			'blocks overlapping' => [function (&$c): void { $c['day']['blocks'] = [['06:00', '11:00'], ['10:00', '16:30']]; }],
			'blocks five' => [function (&$c): void { $c['day']['blocks'] = [['01:00','02:00'],['03:00','04:00'],['05:00','06:00'],['07:00','08:00'],['09:00','10:00']]; }],
			'split missing' => [function (&$c): void { unset($c['split']); }],
			'split.automatic string' => [function (&$c): void { $c['split']['automatic'] = 'true'; }],
			'split.break_cover int' => [function (&$c): void { $c['split']['break_cover'] = 1; }],
			'automatic without cutoff' => [function (&$c): void { $c['split'] = ['automatic' => true, 'cutoff' => null, 'break_cover' => false]; }],
			'cutoff bad format' => [function (&$c): void { $c['split']['cutoff'] = '4pm'; }],
			'cutoff out of range' => [function (&$c): void { $c['split']['cutoff'] = '25:00'; }],
			'overrides wrong type' => [function (&$c): void { $c['overrides'] = 'auto'; }],
			'overrides.decision invalid' => [function (&$c): void { $c['overrides']['decision'] = 'split_pls'; }],
			'overrides.worked not list' => [function (&$c): void { $c['overrides']['worked'] = ['a' => 1]; }],
			'worked pair wrong shape' => [function (&$c): void { $c['overrides']['worked'] = [['2026-11-16T10:00:00+01:00']]; }],
		];
	}

	// ------------------------------------------------------------- //
	// A19 idempotency / determinism                                  //
	// ------------------------------------------------------------- //

	public function testA19DeterministicReplay(): void
	{
		$ctx = self::ctx([], ['worked' => [self::worked('2026-11-16 10:00:00', '2026-11-16 11:00:00')]]);
		$args = [self::ts('2026-11-16 06:00:00'), self::ts('2026-11-16 16:30:00')];
		$hashes = [];
		for ($i = 0; $i < 3; $i++) {
			$hashes[] = S::contextResultHash(S::resolveSplit($ctx, ...$args));
		}
		$this->assertSame($hashes[0], $hashes[1]);
		$this->assertSame($hashes[1], $hashes[2]);
		$this->assertSame(64, strlen($hashes[0]));
	}

	public function testContextResultHashIsKeyOrderInsensitive(): void
	{
		$a = ['split' => true, 'state' => 'applied', 'engine' => 1, 'nested' => ['b' => 1, 'a' => 2]];
		$b = ['engine' => 1, 'nested' => ['a' => 2, 'b' => 1], 'state' => 'applied', 'split' => true];
		$this->assertSame(S::contextResultHash($a), S::contextResultHash($b));
		$c = $b;
		$c['split'] = false;
		$this->assertNotSame(S::contextResultHash($a), S::contextResultHash($c));
	}

	// ------------------------------------------------------------- //
	// coverageCheck — §9.6 step 10b                                  //
	// ------------------------------------------------------------- //

	public function testCoverageCoveredByInterruption(): void
	{
		// A04: portion 210 ≥ floor 15, required 30 (sum-only), stretches ≤ 6 h.
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [210], [], [14400, 10800], null, 15, 21600)
		);
	}

	public function testCoveragePortionExactlyAtRequired(): void
	{
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [30], [], [1000], null, 15, 21600)
		);
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [29], [], [1000], null, 15, 21600)
		);
	}

	public function testCoverageA14NineHourDayExactly(): void
	{
		// A14: own work 9 h → required 30 (9 h is not > 9 h); stretches 16200 each.
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [210], [], [16200, 16200], null, 15, 21600)
		);
	}

	public function testCoverageA15SegmentTooLong(): void
	{
		// 06:00–20:00: segment 13:30–20:00 = 23,400 s > 21,600 s.
		$this->assertSame(
			'standard_segment_too_long',
			S::coverageCheck(30, [210], [], [14400, 23400], null, 15, 21600)
		);
	}

	public function testCoverageStretchExactlyAtLimitPasses(): void
	{
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [30], [], [21600], null, 15, 21600)
		);
		$this->assertSame(
			'standard_segment_too_long',
			S::coverageCheck(30, [30], [], [21601], null, 15, 21600)
		);
	}

	public function testCoverageNullStretchLimitMeansNoLimit(): void
	{
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [30], [], [999999], null, 15, null)
		);
	}

	public function testCoverageFloorFiltering(): void
	{
		// 14-min portion < 15-min floor → not counted → too short.
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [14, 15], [], [100], null, 15, 21600)
		);
		// Same portions with floor 14 → 14+15 = 29 still < 30.
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [14, 15], [], [100], null, 14, 21600)
		);
		// floor null → all positive portions count (14+15 = 29 < 30).
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [14, 15], [], [100], null, null, 21600)
		);
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [15, 15], [], [100], null, null, 21600)
		);
	}

	public function testCoverageRecordedBreaksContribute(): void
	{
		// Interruption 15 + recorded 15 = 30 (sum-only), each ≥ floor.
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [15], [15], [100], null, 15, 21600)
		);
		// ...but portions below the floor drop out on BOTH lists (§9.6.10b):
		// interruption 10 (< 15) + recorded 20 → only 20 counts → 20 < 30.
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [10], [20], [100], null, 15, 21600)
		);
	}

	public function testCoverageAustrianPatterns(): void
	{
		$patterns = [[15, 15], [10, 10, 10]];
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(30, [15, 15], [], [100, 100, 100], $patterns, 10, 21600)
		);
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [10, 10, 10, 10], [], [100], $patterns, 10, 21600)
		);
		// Pattern regime with empty patterns → continuous-only.
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [15, 15], [], [100], [], 15, 21600)
		);
	}

	public function testCoverageNotApplicableWithoutInterruption(): void
	{
		$this->assertSame(
			'not_applicable',
			S::coverageCheck(30, [], [60], [100], null, 15, 21600)
		);
		$this->assertSame(
			'not_applicable',
			S::coverageCheck(0, [], [], [100], null, 15, 21600)
		);
	}

	public function testCoverageBothFailuresReportBreakTooShort(): void
	{
		// Portions insufficient AND stretch too long → documented precedence.
		$this->assertSame(
			'standard_break_too_short',
			S::coverageCheck(30, [10], [], [99999], null, 15, 21600)
		);
	}

	public function testCoverageZeroRequirementIsCovered(): void
	{
		$this->assertSame(
			'covered_by_interruption',
			S::coverageCheck(0, [210], [], [100], null, 15, 21600)
		);
	}

	// ------------------------------------------------------------- //
	// Interval helpers                                               //
	// ------------------------------------------------------------- //

	public function testUnionMergesOverlappingAndTouching(): void
	{
		$this->assertSame(
			[[0, 10], [20, 40]],
			S::union([[30, 40], [0, 5], [20, 30], [4, 10]])
		);
	}

	public function testIntersectPairwise(): void
	{
		$this->assertSame(
			[[5, 8], [12, 15]],
			S::intersect([[0, 10], [12, 20]], [[5, 8], [10, 15]])
		);
	}

	public function testSubtractCarvesOut(): void
	{
		$this->assertSame(
			[[0, 5], [8, 10]],
			S::subtract([[0, 10]], [[5, 8]])
		);
		$this->assertSame([], S::subtract([[0, 10]], [[0, 10]]));
		$this->assertSame([[0, 10]], S::subtract([[0, 10]], [[20, 30]]));
	}

	public function testSubtractSplitsIntoMultiple(): void
	{
		$this->assertSame(
			[[0, 2], [4, 6], [8, 10]],
			S::subtract([[0, 10]], [[2, 4], [6, 8]])
		);
	}

	public function testIntervalHelpersValidate(): void
	{
		$this->expectException(InvalidArgumentException::class);
		S::union([[10, 5]]);
	}

	public function testIntervalHelpersRejectNonInt(): void
	{
		$this->expectException(InvalidArgumentException::class);
		S::union([['a', 5]]);
	}

	public function testZeroLengthIntervalDropped(): void
	{
		$this->assertSame([[0, 5]], S::union([[0, 5], [7, 7]]));
	}

	// ------------------------------------------------------------- //
	// Property-style randomized invariants (fixed seed, ≥ 200 cases) //
	// ------------------------------------------------------------- //

	public function testRandomizedInvariants(): void
	{
		mt_srand(20261116);
		for ($case = 0; $case < 250; $case++) {
			// Random day: 2–4 disjoint non-touching blocks inside 00:00–23:59.
			$cursor = mt_rand(0, 300);
			$blocks = [];
			$nBlocks = mt_rand(2, 4);
			for ($b = 0; $b < $nBlocks; $b++) {
				$bs = $cursor + mt_rand(0, 120);
				$be = $bs + mt_rand(15, 240);
				if ($be >= 1439) {
					break;
				}
				$blocks[] = [sprintf('%02d:%02d', intdiv($bs, 60), $bs % 60), sprintf('%02d:%02d', intdiv($be, 60), $be % 60)];
				$cursor = $be + mt_rand(15, 200);
				if ($cursor >= 1430) {
					break;
				}
			}
			if (count($blocks) < 2) {
				continue;
			}
			$cutH = mt_rand(0, 23);
			$cutM = mt_rand(0, 59);
			$ctx = self::ctx(
				['automatic' => mt_rand(0, 3) > 0, 'cutoff' => sprintf('%02d:%02d', $cutH, $cutM)],
				[],
				$blocks
			);
			$sMin = mt_rand(0, 1400);
			$eMin = min(1439, $sMin + mt_rand(1, 600));
			$s = self::ts('2026-11-16 00:00:00') + $sMin * 60 + mt_rand(0, 59);
			$e = self::ts('2026-11-16 00:00:00') + $eMin * 60;
			if ($e <= $s) {
				continue;
			}
			// Random worked overrides (0–3), all inside the day.
			$worked = [];
			for ($w = 0; $w < mt_rand(0, 3); $w++) {
				$ws = mt_rand($s, $e - 1);
				$we = mt_rand($ws + 1, $e);
				$worked[] = [
					(new \DateTimeImmutable('@' . $ws))->setTimezone(new \DateTimeZone(self::TZ))->format('c'),
					(new \DateTimeImmutable('@' . $we))->setTimezone(new \DateTimeZone(self::TZ))->format('c'),
				];
			}
			$ctx['overrides']['worked'] = $worked;
			$workedEpochs = array_map(
				static fn (array $p): array => [(new \DateTimeImmutable($p[0]))->getTimestamp(), (new \DateTimeImmutable($p[1]))->getTimestamp()],
				$worked
			);

			$r = S::resolveSplit($ctx, $s, $e);

			// Invariant 1: X ⊆ R.
			foreach ($r['excludedEpoch'] as [$xs, $xe]) {
				$this->assertGreaterThanOrEqual($s, $xs, "case $case: excluded starts before R");
				$this->assertLessThanOrEqual($e, $xe, "case $case: excluded ends after R");
			}
			// Invariant 2: excluded ∩ worked = ∅ after subtract.
			$this->assertSame(
				[],
				S::intersect($r['excludedEpoch'], $workedEpochs),
				"case $case: excluded overlaps worked"
			);
			// Invariant 3: segments + excluded partition R exactly (net ≥ 0).
			$this->assertSame(
				$e - $s,
				S::totalSeconds($r['segmentsEpoch']) + S::totalSeconds($r['excludedEpoch']),
				"case $case: segments + excluded ≠ R"
			);
			// Invariant 4: overrides never INCREASE X.
			if ($worked !== []) {
				$base = $ctx;
				$base['overrides']['worked'] = [];
				$rb = S::resolveSplit($base, $s, $e);
				$this->assertLessThanOrEqual(
					$rb['excludedSeconds'],
					$r['excludedSeconds'],
					"case $case: worked override increased X"
				);
			}
			// Invariant 5: determinism — byte-identical canonical hash.
			$this->assertSame(
				S::contextResultHash($r),
				S::contextResultHash(S::resolveSplit($ctx, $s, $e)),
				"case $case: non-deterministic result"
			);
			// Invariant 6: excluded is merged and ordered.
			$this->assertSame($r['excludedEpoch'], S::union($r['excludedEpoch']));
		}
	}

	// ------------------------------------------------------------- //
	// localGapEpochs helper                                          //
	// ------------------------------------------------------------- //

	public function testLocalGapEpochsReturnsInterruptionWindows(): void
	{
		$gaps = S::localGapEpochs(self::ctx(), self::ts('2026-11-16 06:00:00'));
		$this->assertSame(
			[[self::ts('2026-11-16 10:00:00'), self::ts('2026-11-16 13:30:00')]],
			$gaps
		);
	}

	public function testLocalGapEpochsNullOnDstAmbiguity(): void
	{
		$ctx = self::ctx(['cutoff' => '02:30'], [], [['06:00', '10:00'], ['13:30', '16:30']], self::TZ, '2027-03-28');
		$this->assertNull(S::localGapEpochs($ctx, self::ts('2027-03-28 06:00:00')));
	}
}
