<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use PHPUnit\Framework\TestCase;

class WeekdayScheduleTest extends TestCase
{
	public function testBanssPresetNets(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$this->assertSame(8.5, $schedule->netHoursForWeekday('mon'));
		$this->assertSame(8.5, $schedule->netHoursForWeekday('thu'));
		$this->assertSame(4.5, $schedule->netHoursForWeekday('fri'));
		$this->assertSame(0.0, $schedule->netHoursForWeekday('sat'));
		$this->assertSame(38.5, $schedule->weeklyNetHours());
		$this->assertSame(5.0, $schedule->workDaysPerWeek());
		$this->assertEqualsWithDelta(7.7, $schedule->averageDailyNetHours(), 0.0001);
	}

	public function testRequiredHoursWeekNoHolidays(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		// Mon 2026-08-03 .. Fri 2026-08-07
		$start = new \DateTime('2026-08-03');
		$end = new \DateTime('2026-08-07');
		$hours = $schedule->requiredHoursForDateRange($start, $end, static fn (): float => 0.0);
		$this->assertSame(38.5, $hours);
	}

	public function testHalfDayFridayHoliday(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$start = new \DateTime('2026-08-07'); // Friday
		$end = new \DateTime('2026-08-07');
		$hours = $schedule->requiredHoursForDateRange(
			$start,
			$end,
			static function (\DateTime $d): float {
				return $d->format('Y-m-d') === '2026-08-07' ? 0.5 : 0.0;
			}
		);
		$this->assertSame(2.25, $hours);
	}

	public function testFullHolidayZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$start = new \DateTime('2026-08-03');
		$end = new \DateTime('2026-08-03');
		$hours = $schedule->requiredHoursForDateRange($start, $end, static fn (): float => 1.0);
		$this->assertSame(0.0, $hours);
	}

	public function testValidateRejectsBreakOutsideSpan(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['breaks'] = [
			['start' => '06:00', 'end' => '07:00', 'paid' => false],
		];
		$errors = WeekdaySchedule::validate($raw);
		$this->assertContains('SCHEDULE_INVALID_BREAK', $errors);
	}

	public function testTryFromBreakRulesIgnoresCorrupt(): void
	{
		$this->assertNull(WeekdaySchedule::tryFromBreakRules(null));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules([]));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules(['break_policy' => 'flex']));
		$this->assertNull(WeekdaySchedule::tryFromBreakRules([
			WeekdaySchedule::KEY => ['days' => ['mon' => ['work' => true]]],
		]));
	}

	public function testTryFromBreakRulesPreservesLegacyKeys(): void
	{
		$merged = WeekdaySchedule::mergeIntoBreakRules(
			['break_policy' => 'flex', 'auto_fallback_minutes' => 120],
			WeekdaySchedule::banssPreset()
		);
		$this->assertSame('flex', $merged['break_policy']);
		$this->assertSame(120, $merged['auto_fallback_minutes']);
		$schedule = WeekdaySchedule::tryFromBreakRules($merged);
		$this->assertNotNull($schedule);
		$this->assertSame(38.5, $schedule->weeklyNetHours());
	}

	public function testPaidBreakDoesNotReduceNet(): void
	{
		$raw = [
			'version' => 1,
			'days' => [
				'mon' => [
					'work' => true,
					'start' => '08:00',
					'end' => '16:00',
					'breaks' => [
						['start' => '12:00', 'end' => '13:00', 'paid' => true],
					],
				],
				'tue' => ['work' => false],
				'wed' => ['work' => false],
				'thu' => ['work' => false],
				'fri' => ['work' => false],
				'sat' => ['work' => false],
				'sun' => ['work' => false],
			],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(8.0, $schedule->netHoursForWeekday('mon'));
	}

	public function testStoredStringFalseWorkIsNotAWorkday(): void
	{
		// "work":"false" (form-encoded string) must never become a workday.
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['sun'] = [
			'work' => 'false',
			'start' => '08:00',
			'end' => '16:00',
			'breaks' => [],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertFalse($schedule->isWorkDay('sun'));
		$this->assertSame(0.0, $schedule->netHoursForWeekday('sun'));
	}

	public function testStoredStringFalsePaidStaysUnpaid(): void
	{
		// "paid":"false" must not turn an unpaid break into a paid one.
		$raw = [
			'version' => 1,
			'days' => [
				'mon' => [
					'work' => true,
					'start' => '08:00',
					'end' => '16:00',
					'breaks' => [
						['start' => '12:00', 'end' => '13:00', 'paid' => 'false'],
					],
				],
				'tue' => ['work' => false],
				'wed' => ['work' => false],
				'thu' => ['work' => false],
				'fri' => ['work' => false],
				'sat' => ['work' => false],
				'sun' => ['work' => false],
			],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		// unpaid 60-min break is deducted → 7h net, not 8h
		$this->assertSame(7.0, $schedule->netHoursForWeekday('mon'));
		$this->assertFalse($schedule->toArray()['days']['mon']['breaks'][0]['paid']);
	}

	public function testValidateRejectsEndBeforeStart(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['end'] = '06:00';
		$this->assertContains('SCHEDULE_END_BEFORE_START', WeekdaySchedule::validate($raw));
	}

	public function testValidateRejectsMissingDaysKey(): void
	{
		$this->assertSame(['SCHEDULE_MISSING_DAYS'], WeekdaySchedule::validate(['version' => 1]));
	}

	public function testValidateRejectsEmptyWorkdayTimes(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['mon']['start'] = '';
		$this->assertContains('SCHEDULE_EMPTY_WORKDAY', WeekdaySchedule::validate($raw));
	}

	public function testRequiredHoursInvertedRangeIsZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$hours = $schedule->requiredHoursForDateRange(
			new \DateTime('2026-08-07'),
			new \DateTime('2026-08-03'),
			static fn (): float => 0.0
		);
		$this->assertSame(0.0, $hours);
	}

	public function testWeekendOnlyRangeIsZero(): void
	{
		$schedule = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$hours = $schedule->requiredHoursForDateRange(
			new \DateTime('2026-08-08'),
			new \DateTime('2026-08-09'),
			static fn (): float => 0.0
		);
		$this->assertSame(0.0, $hours);
	}

	public function testToArrayRoundTrip(): void
	{
		$preset = WeekdaySchedule::banssPreset();
		$schedule = WeekdaySchedule::fromValidated($preset);
		$again = WeekdaySchedule::fromValidated($schedule->toArray());
		$this->assertSame(38.5, $again->weeklyNetHours());
		$this->assertSame(WeekdaySchedule::VERSION, $again->toArray()['version']);
	}

	/* ---------------------------------------------------------- v2 blocks */

	/** @return array<string, mixed> Cecilienstift-style two-block day fixture */
	private static function v2Fixture(): array
	{
		$day = [
			'work' => true,
			'blocks' => [
				['start' => '06:00', 'end' => '10:00'],
				['start' => '13:30', 'end' => '16:30'],
			],
			'breaks' => [],
		];
		$off = ['work' => false];
		return [
			'version' => 2,
			'interruption_mode' => 'recorded_only',
			'days' => [
				'mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day,
				'fri' => $day, 'sat' => $off, 'sun' => $off,
			],
		];
	}

	public function testV2ValidatesAndNetsTwoBlocks(): void
	{
		$raw = self::v2Fixture();
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(2, $schedule->getVersion());
		// 4h + 3h = 7h per day, 5 days
		$this->assertSame(7.0, $schedule->netHoursForWeekday('mon'));
		$this->assertSame(35.0, $schedule->weeklyNetHours());
		$this->assertSame(7.0, $schedule->averageDailyNetHours());
		$this->assertSame(5.0, $schedule->workDaysPerWeek());
	}

	public function testV2BlocksAndGapsAccessors(): void
	{
		$schedule = WeekdaySchedule::fromValidated(self::v2Fixture());
		$this->assertSame([[360, 600], [810, 990]], $schedule->blocksForWeekday('mon'));
		$this->assertSame([[600, 810]], $schedule->gapsForWeekday('mon'));
		$this->assertSame([], $schedule->blocksForWeekday('sat'));
		$this->assertSame([], $schedule->gapsForWeekday('sat'));
		// v1 exposes its single outer span as one block, no gaps
		$v1 = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$this->assertSame([[420, 975]], $v1->blocksForWeekday('mon'));
		$this->assertSame([], $v1->gapsForWeekday('mon'));
	}

	public function testV2RejectsUnknownVersion(): void
	{
		$raw = self::v2Fixture();
		$raw['version'] = 3;
		$this->assertSame(['SCHEDULE_UNKNOWN_VERSION'], WeekdaySchedule::validate($raw));
	}

	public function testV2RejectsUnknownInterruptionMode(): void
	{
		$raw = self::v2Fixture();
		$raw['interruption_mode'] = 'split_service';
		$this->assertSame(['SCHEDULE_UNKNOWN_INTERRUPTION_MODE'], WeekdaySchedule::validate($raw));
	}

	public function testV2RejectsOverlappingBlocks(): void
	{
		$raw = self::v2Fixture();
		$raw['days']['mon']['blocks'][1] = ['start' => '09:30', 'end' => '16:30'];
		$this->assertContains('SCHEDULE_BLOCKS_OVERLAP', WeekdaySchedule::validate($raw));
	}

	public function testV2RejectsTouchingBlocksWithDedicatedCode(): void
	{
		$raw = self::v2Fixture();
		// end == next start is touching, not overlapping — the dedicated code
		// matters for field-level UI errors (it points at the gap, not the
		// block body).
		$raw['days']['mon']['blocks'][1] = ['start' => '10:00', 'end' => '16:30'];
		$errors = WeekdaySchedule::validate($raw);
		$this->assertContains('SCHEDULE_BLOCK_TOUCHING', $errors);
		$this->assertNotContains('SCHEDULE_BLOCKS_OVERLAP', $errors);
	}

	public function testV2RejectsTooManyBlocks(): void
	{
		$raw = self::v2Fixture();
		$raw['days']['mon']['blocks'] = [
			['start' => '05:00', 'end' => '06:00'],
			['start' => '06:30', 'end' => '07:30'],
			['start' => '08:00', 'end' => '09:00'],
			['start' => '10:00', 'end' => '11:00'],
			['start' => '12:00', 'end' => '13:00'],
		];
		$this->assertContains('SCHEDULE_INVALID_BLOCKS', WeekdaySchedule::validate($raw));
	}

	public function testV2RejectsBreakOutsideBlocks(): void
	{
		$raw = self::v2Fixture();
		// Break inside the gap 10:00–13:30 envelope still passes envelope
		// check; a break fully outside the block envelope must fail.
		$raw['days']['mon']['breaks'] = [['start' => '17:00', 'end' => '17:30']];
		$this->assertContains('SCHEDULE_BREAK_OUTSIDE_BLOCKS', WeekdaySchedule::validate($raw));
	}

	public function testV2BreakInGapIsNotDoubleDeducted(): void
	{
		$raw = self::v2Fixture();
		$raw['days']['mon']['breaks'] = [['start' => '11:00', 'end' => '12:00']];
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		// Break sits in the gap (not working time) → net stays 7h.
		$this->assertSame(7.0, $schedule->netHoursForWeekday('mon'));
		// Break inside a block deducts once.
		$raw['days']['mon']['breaks'] = [['start' => '08:00', 'end' => '08:30']];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(6.5, $schedule->netHoursForWeekday('mon'));
	}

	public function testV2RoundTripPreservesVersionAndBlocks(): void
	{
		$schedule = WeekdaySchedule::fromValidated(self::v2Fixture());
		$arr = $schedule->toArray();
		$this->assertSame(2, $arr['version']);
		$this->assertSame('recorded_only', $arr['interruption_mode']);
		$again = WeekdaySchedule::tryFromBreakRules([WeekdaySchedule::KEY => $arr]);
		$this->assertNotNull($again);
		$this->assertSame(7.0, $again->netHoursForWeekday('mon'));
		$this->assertSame([[600, 810]], $again->gapsForWeekday('mon'));
	}

	public function testV2RejectsUnsortedBlocks(): void
	{
		$raw = self::v2Fixture();
		$raw['days']['mon']['blocks'] = array_reverse($raw['days']['mon']['blocks']);
		// Blocks must arrive sorted (schema contract) — out-of-order fails
		// closed rather than being silently reordered.
		$this->assertContains('SCHEDULE_BLOCKS_OVERLAP', WeekdaySchedule::validate($raw));
	}

	public function testV2RejectsNonWorkdayContent(): void
	{
		$raw = self::v2Fixture();
		$raw['days']['sat'] = ['work' => false, 'blocks' => [['start' => '06:00', 'end' => '08:00']]];
		$this->assertContains('SCHEDULE_NONWORKDAY_CONTENT', WeekdaySchedule::validate($raw));
	}

	public function testV1UnchangedByteShape(): void
	{
		// Legacy v1 payloads must still validate/round-trip untouched.
		$preset = WeekdaySchedule::banssPreset();
		$this->assertSame([], WeekdaySchedule::validate($preset));
		$this->assertSame(1, WeekdaySchedule::versionOf($preset));
		$schedule = WeekdaySchedule::tryFromBreakRules([WeekdaySchedule::KEY => $preset]);
		$this->assertNotNull($schedule);
		$this->assertSame(1, $schedule->getVersion());
		$this->assertSame(8.5, $schedule->netHoursForWeekday('mon'));
	}

	/* ---------------------------------------------- v2 split_service */

	/** @return array<string, mixed> two-block week with automatic split on */
	private static function v2SplitFixture(): array
	{
		$raw = self::v2Fixture();
		$raw['split_service'] = [
			'automatic_split' => true,
			'split_when_clock_out_after' => '16:00',
			'interruption_counts_as_break' => true,
		];
		return $raw;
	}

	public function testSplitServiceValidatesAndNormalizes(): void
	{
		$raw = self::v2SplitFixture();
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(
			['automatic' => true, 'cutoff' => '16:00', 'breakCover' => true, 'weekdayCutoffs' => []],
			$schedule->getSplitService()
		);
		$this->assertTrue($schedule->hasActiveSplitService());
		// Canonical stored form: exactly the three keys, spec order.
		$this->assertSame(
			[
				'automatic_split' => true,
				'split_when_clock_out_after' => '16:00',
				'interruption_counts_as_break' => true,
			],
			$schedule->toArray()['split_service']
		);
	}

	public function testSplitServiceAbsentIsAllOff(): void
	{
		$raw = self::v2Fixture();
		$this->assertArrayNotHasKey('split_service', $raw);
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(
			['automatic' => false, 'cutoff' => null, 'breakCover' => false, 'weekdayCutoffs' => []],
			$schedule->getSplitService()
		);
		$this->assertFalse($schedule->hasActiveSplitService());
		// Absent stays absent — the group is not injected on save.
		$this->assertArrayNotHasKey('split_service', $schedule->toArray());
	}

	public function testWeekdayCutoffsValidateAndResolve(): void
	{
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['fri' => '14:00', 'mon' => '17:00'];
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(
			['mon' => 1020, 'fri' => 840],
			$schedule->getSplitService()['weekdayCutoffs']
		);
		// Override wins on its day; the default applies elsewhere.
		$this->assertSame(1020, $schedule->splitCutoffMinutesForWeekday('mon'));
		$this->assertSame(960, $schedule->splitCutoffMinutesForWeekday('tue'));
		$this->assertSame(840, $schedule->splitCutoffMinutesForWeekday('fri'));
		$this->assertSame(960, $schedule->splitCutoffMinutesForWeekday('sat'));
		// Resolved map covers every interruption day (audit-grade context).
		$resolved = $schedule->splitWeekdayCutoffsHm();
		$this->assertSame('17:00', $resolved['mon']);
		$this->assertSame('16:00', $resolved['tue']);
		$this->assertSame('14:00', $resolved['fri']);
		$this->assertArrayNotHasKey('sat', $resolved);
		// Canonical stored form carries the map in weekday order.
		$this->assertSame(
			['mon' => '17:00', 'fri' => '14:00'],
			$schedule->toArray()['split_service']['weekday_cutoffs']
		);
	}

	public function testWeekdayCutoffsValidationErrors(): void
	{
		// Non-HH:MM value.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['mon' => '25:00'];
		$this->assertContains('SPLIT_CUTOFF_INVALID', WeekdaySchedule::validate($raw));

		// Unknown weekday key fails closed like every other unknown key.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['noday' => '16:00'];
		$this->assertContains('SCHEDULE_UNKNOWN_KEY', WeekdaySchedule::validate($raw));

		// Per-day cut-off before that weekday's own interruption end.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['mon' => '13:00'];
		$this->assertContains('SPLIT_CUTOFF_BEFORE_INTERRUPTION_END', WeekdaySchedule::validate($raw));

		// Cut-off on a gap-less day can never fire — rejected, not a no-op.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['sat' => '16:00'];
		$this->assertContains('SPLIT_NO_INTERRUPTION', WeekdaySchedule::validate($raw));

		// Overrides with automatic split off are contradictory state.
		$raw = self::v2SplitFixture();
		$raw['split_service']['automatic_split'] = false;
		$raw['split_service']['split_when_clock_out_after'] = null;
		$raw['split_service']['weekday_cutoffs'] = ['mon' => '17:00'];
		$this->assertContains('SPLIT_CUTOFF_INVALID', WeekdaySchedule::validate($raw));

		// Non-map shape is a config error, not silently ignored.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = ['16:00'];
		$this->assertContains('SPLIT_CONFIG_INVALID', WeekdaySchedule::validate($raw));
	}

	public function testWeekdayCutoffsOmittedWhenEmptyOrAutomaticOff(): void
	{
		// Empty map is dropped from the canonical stored form.
		$raw = self::v2SplitFixture();
		$raw['split_service']['weekday_cutoffs'] = [];
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertArrayNotHasKey('weekday_cutoffs', $schedule->toArray()['split_service']);

		// Legacy document without the key resolves to the default only.
		$schedule = WeekdaySchedule::fromValidated(self::v2SplitFixture());
		$this->assertSame([], $schedule->getSplitService()['weekdayCutoffs']);
		$this->assertSame(960, $schedule->splitCutoffMinutesForWeekday('mon'));
		$this->assertSame('16:00', $schedule->splitWeekdayCutoffsHm()['wed']);
	}

	public function testSplitServicePresentButAllOffRoundTripsCanonical(): void
	{
		$raw = self::v2Fixture();
		$raw['split_service'] = [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => false,
		];
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertFalse($schedule->hasActiveSplitService());
		$this->assertSame(
			[
				'automatic_split' => false,
				'split_when_clock_out_after' => null,
				'interruption_counts_as_break' => false,
			],
			$schedule->toArray()['split_service']
		);
	}

	public function testSplitServiceBreakCoverAloneIsValid(): void
	{
		// interruption_counts_as_break may be on without automatic split;
		// cut-off stays null. Needs ≥1 day with ≥2 blocks (fixture has them).
		$raw = self::v2Fixture();
		$raw['split_service'] = [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => true,
		];
		$this->assertSame([], WeekdaySchedule::validate($raw));
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(
			['automatic' => false, 'cutoff' => null, 'breakCover' => true, 'weekdayCutoffs' => []],
			$schedule->getSplitService()
		);
		$this->assertTrue($schedule->hasActiveSplitService());
		$this->assertNull($schedule->toArray()['split_service']['split_when_clock_out_after']);
	}

	public function testSplitServiceRoundTripIsIdempotent(): void
	{
		$schedule = WeekdaySchedule::fromValidated(self::v2SplitFixture());
		$stored = $schedule->toArray();
		$this->assertSame([], WeekdaySchedule::validate($stored));
		$again = WeekdaySchedule::fromValidated($stored);
		$this->assertSame($stored, $again->toArray());
		// And through the break_rules wrapper as the model would store it.
		$fromRules = WeekdaySchedule::tryFromBreakRules([WeekdaySchedule::KEY => $stored]);
		$this->assertNotNull($fromRules);
		$this->assertSame($stored, $fromRules->toArray());
	}

	public function testSplitServiceRequiresRealBooleans(): void
	{
		foreach (['false', '0', '1', 'true', 0, 1] as $bogus) {
			$raw = self::v2SplitFixture();
			$raw['split_service']['automatic_split'] = $bogus;
			$this->assertContains(
				'SPLIT_CONFIG_INVALID',
				WeekdaySchedule::validate($raw),
				'automatic_split=' . var_export($bogus, true)
			);

			$raw = self::v2SplitFixture();
			$raw['split_service']['interruption_counts_as_break'] = $bogus;
			$this->assertContains(
				'SPLIT_CONFIG_INVALID',
				WeekdaySchedule::validate($raw),
				'interruption_counts_as_break=' . var_export($bogus, true)
			);
		}
	}

	public function testSplitServiceMissingOptionKeysAreInvalid(): void
	{
		foreach (['automatic_split', 'interruption_counts_as_break'] as $missing) {
			$raw = self::v2SplitFixture();
			unset($raw['split_service'][$missing]);
			$this->assertContains('SPLIT_CONFIG_INVALID', WeekdaySchedule::validate($raw));
		}
	}

	public function testSplitServiceAutomaticNeedsValidCutoff(): void
	{
		$raw = self::v2SplitFixture();
		unset($raw['split_service']['split_when_clock_out_after']);
		$this->assertContains('SPLIT_CUTOFF_INVALID', WeekdaySchedule::validate($raw));

		foreach ([null, '', '24:00', '8:00', '16:0', 'ab:cd', 1600, '13:00 '] as $bogus) {
			$raw = self::v2SplitFixture();
			$raw['split_service']['split_when_clock_out_after'] = $bogus;
			$this->assertContains(
				'SPLIT_CUTOFF_INVALID',
				WeekdaySchedule::validate($raw),
				'cutoff=' . var_export($bogus, true)
			);
		}
	}

	public function testSplitServiceOffMustNotCarryCutoff(): void
	{
		$raw = self::v2SplitFixture();
		$raw['split_service']['automatic_split'] = false;
		// '16:00' left over while automatic is off → contradictory state.
		$this->assertContains('SPLIT_CUTOFF_INVALID', WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceCutoffAtOrAfterLatestGapEnd(): void
	{
		// Fixture gap runs 10:00–13:30; interruption ends 13:30 (810 min).
		$raw = self::v2SplitFixture();
		$raw['split_service']['split_when_clock_out_after'] = '13:29';
		$this->assertContains('SPLIT_CUTOFF_BEFORE_INTERRUPTION_END', WeekdaySchedule::validate($raw));

		// Exactly at the interruption end is allowed ("at or after").
		$raw['split_service']['split_when_clock_out_after'] = '13:30';
		$this->assertSame([], WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceThreeBlockDayUsesSecondGapEnd(): void
	{
		// Mon gains a third block: gaps end 10:00 and 15:00; other days end
		// 13:30 → latest gap end is 15:00 (900 min).
		$raw = self::v2SplitFixture();
		$raw['days']['mon']['blocks'] = [
			['start' => '06:00', 'end' => '08:00'],
			['start' => '10:00', 'end' => '12:00'],
			['start' => '15:00', 'end' => '17:00'],
		];
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertSame(900, $schedule->latestGapEndMinutes());

		$raw['split_service']['split_when_clock_out_after'] = '14:59';
		$this->assertContains('SPLIT_CUTOFF_BEFORE_INTERRUPTION_END', WeekdaySchedule::validate($raw));
		$raw['split_service']['split_when_clock_out_after'] = '15:00';
		$this->assertSame([], WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceNoInterruption(): void
	{
		// All work days collapse to a single block → nothing to interrupt.
		$raw = self::v2SplitFixture();
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
			$raw['days'][$day]['blocks'] = [['start' => '06:00', 'end' => '16:30']];
		}
		$errors = WeekdaySchedule::validate($raw);
		$this->assertContains('SPLIT_NO_INTERRUPTION', $errors);
		$this->assertNotContains('SPLIT_CUTOFF_BEFORE_INTERRUPTION_END', $errors);

		// breakCover alone triggers the same requirement.
		$raw['split_service'] = [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => true,
		];
		$this->assertContains('SPLIT_NO_INTERRUPTION', WeekdaySchedule::validate($raw));

		// All-off days likewise.
		$raw = self::v2SplitFixture();
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
			$raw['days'][$day] = ['work' => false];
		}
		$this->assertContains('SPLIT_NO_INTERRUPTION', WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceOnV1IsRejected(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['split_service'] = [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => false,
		];
		$this->assertSame(['SPLIT_REQUIRES_SCHEDULE_V2'], WeekdaySchedule::validate($raw));

		// Versionless documents are v1 — same rejection.
		unset($raw['version']);
		$this->assertSame(['SPLIT_REQUIRES_SCHEDULE_V2'], WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceRejectsUnknownNestedKey(): void
	{
		$raw = self::v2SplitFixture();
		$raw['split_service']['split_when_clockout_after'] = '16:00';
		$this->assertContains('SCHEDULE_UNKNOWN_KEY', WeekdaySchedule::validate($raw));

		$raw = self::v2SplitFixture();
		$raw['split_service']['extra_flag'] = true;
		$this->assertContains('SCHEDULE_UNKNOWN_KEY', WeekdaySchedule::validate($raw));
	}

	public function testSplitServiceNonArrayIsRejected(): void
	{
		foreach (['yes', 1, true, '16:00'] as $bogus) {
			$raw = self::v2Fixture();
			$raw['split_service'] = $bogus;
			$this->assertContains(
				'SPLIT_CONFIG_INVALID',
				WeekdaySchedule::validate($raw),
				'split_service=' . var_export($bogus, true)
			);
		}
	}

	public function testLatestGapEndMinutes(): void
	{
		// Two-block fixture: gap 10:00–13:30 on every work day → 810.
		$schedule = WeekdaySchedule::fromValidated(self::v2Fixture());
		$this->assertSame(810, $schedule->latestGapEndMinutes());

		// Single-block days have no interruptions at all.
		$raw = self::v2Fixture();
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
			$raw['days'][$day]['blocks'] = [['start' => '06:00', 'end' => '16:30']];
		}
		$schedule = WeekdaySchedule::fromValidated($raw);
		$this->assertNull($schedule->latestGapEndMinutes());

		// v1 has a single outer span → never a gap.
		$v1 = WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset());
		$this->assertNull($v1->latestGapEndMinutes());
		$this->assertSame(
			['automatic' => false, 'cutoff' => null, 'breakCover' => false, 'weekdayCutoffs' => []],
			$v1->getSplitService()
		);
	}
}
