<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Explainability breakdown: every classification branch plus the
 * hard invariant that `credit_hours`/`reduced_hours`/`days_*` reconcile
 * EXACTLY with the aggregate methods on the same fixture — the breakdown
 * must never disagree with the number the dashboard tile shows.
 */
class PaidAbsenceCreditBreakdownTest extends TestCase
{
	/** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var AbsenceMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;
	/** @var UserWorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userModelMapper;
	/** @var WorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $modelMapper;
	/** @var HolidayService&\PHPUnit\Framework\MockObject\MockObject */
	private $holidayService;
	/** @var DutyRotationSollProvider&\PHPUnit\Framework\MockObject\MockObject */
	private $rotation;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->modelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);
		$this->holidayService->method('getHolidayWeightForUser')->willReturn(0.0);
		$this->rotation = $this->createMock(DutyRotationSollProvider::class);
		$this->rotation->method('isEnabledForOrg')->willReturn(false);
		// No contract rows: legacy Mon–Fri 8 h/day model.
		$this->userModelMapper->method('findByUserAndDate')->willReturn(null);
		$this->userModelMapper->method('findCurrentByUser')->willReturn(null);
	}

	private function service(): PaidAbsencePlannedHoursCreditService
	{
		return new PaidAbsencePlannedHoursCreditService(
			$this->config,
			$this->absenceMapper,
			$this->userModelMapper,
			$this->modelMapper,
			$this->holidayService,
			$this->rotation,
		);
	}

	private function flags(string $sickOptIn, string $leaveEffects): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($sickOptIn, $leaveEffects): string {
				return match ($key) {
					Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT => $sickOptIn,
					Constants::CONFIG_LEAVE_SOLL_EFFECTS => $leaveEffects,
					default => $default,
				};
			}
		);
	}

	private function absence(
		string $type,
		string $status,
		string $start,
		string $end,
		float $days = 1.0,
		?float $durationHours = null,
	): Absence {
		$a = new Absence();
		$a->setUserId('alice');
		$a->setType($type);
		$a->setStatus($status);
		$a->setStartDate(new \DateTime($start));
		$a->setEndDate(new \DateTime($end));
		$a->setDays($days);
		if ($durationHours !== null) {
			$a->setDurationHours($durationHours);
		}
		return $a;
	}

	private function withAbsences(array $absences): void
	{
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn($absences);
	}

	public function testCreditedAbsenceReconcilesExactlyWithAggregate(): void
	{
		$this->flags('0', '1');
		// Mon–Wed 2026-09-14..16, legacy 8 h/day, Tuesday fully worked.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-16', 3.0),
		]);
		$worked = ['2026-09-15' => 8.0];
		$svc = $this->service();

		$bd = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-16'), $worked);
		$agg = $svc->creditHoursForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-16'), $worked);

		$this->assertSame($agg['hours'], $bd['credit_hours']);
		$this->assertSame($agg['days_credited'], $bd['days_credited']);
		$this->assertEqualsWithDelta(16.0, $bd['credit_hours'], 0.0001);
		$this->assertSame(2, $bd['days_credited']);

		$this->assertCount(1, $bd['credited']);
		$item = $bd['credited'][0];
		$this->assertSame(Absence::TYPE_VACATION, $item['type']);
		$this->assertEqualsWithDelta(16.0, $item['credit_hours'], 0.001);
		$this->assertCount(3, $item['days']);
		$this->assertSame('2026-09-14', $item['days'][0]['date']);
		$this->assertEqualsWithDelta(8.0, $item['days'][0]['credited'], 0.001);
		$this->assertEqualsWithDelta(0.0, $item['days'][1]['credited'], 0.001);
		$this->assertEqualsWithDelta(8.0, $item['days'][1]['worked'], 0.001);
		$this->assertEqualsWithDelta(8.0, $item['days'][1]['planned'], 0.001);
		$this->assertEqualsWithDelta(8.0, $item['days'][1]['absent'], 0.001);
		$this->assertSame(0, $item['overlap_days']);
		$this->assertSame([], $bd['reduced']);
		$this->assertSame([], $bd['not_counted']);
	}

	public function testOverworkedDayCannotProduceNegativeCredit(): void
	{
		$this->flags('0', '1');
		// Mon–Tue vacation; Tuesday has 10 h worked > 8 h planned. The
		// per-day clamp must mirror the aggregate — without it the row
		// would show credited=-2 and silently eat Monday's credit.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-15', 2.0),
		]);
		$worked = ['2026-09-15' => 10.0];
		$svc = $this->service();

		$bd = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'), $worked);
		$agg = $svc->creditHoursForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'), $worked);

		$this->assertSame($agg['hours'], $bd['credit_hours']);
		$this->assertEqualsWithDelta(8.0, $bd['credit_hours'], 0.0001);
		$this->assertSame(1, $bd['days_credited']);
		$this->assertCount(1, $bd['credited']);
		$days = $bd['credited'][0]['days'];
		$this->assertEqualsWithDelta(0.0, $days[1]['credited'], 0.0001);
		$this->assertGreaterThanOrEqual(0.0, $days[1]['credited']);
		foreach ($days as $row) {
			$this->assertGreaterThanOrEqual(0.0, $row['credited']);
		}
	}

	public function testPendingAbsenceIsNotApproved(): void
	{
		$this->flags('0', '1');
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_PENDING, '2026-09-14', '2026-09-14'),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));

		$this->assertEqualsWithDelta(0.0, $bd['credit_hours'], 0.0001);
		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('not_approved', $bd['not_counted'][0]['reason']);
		$this->assertSame(Absence::STATUS_PENDING, $bd['not_counted'][0]['status']);
	}

	public function testBusinessTripIsEffectNone(): void
	{
		$this->flags('1', '1');
		$this->withAbsences([
			$this->absence(Absence::TYPE_BUSINESS_TRIP, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-15', 2.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));

		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('effect_none', $bd['not_counted'][0]['reason']);
		$this->assertSame([], $bd['credited']);
	}

	public function testSickBehindClosedGateIsGateDisabled(): void
	{
		$this->flags('0', '1'); // leave on, sick opt-in off
		$this->withAbsences([
			$this->absence(Absence::TYPE_SICK_LEAVE, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14'),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));

		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('gate_disabled', $bd['not_counted'][0]['reason']);
	}

	public function testSickWithOpenGateIsCredited(): void
	{
		$this->flags('1', '1');
		$this->withAbsences([
			$this->absence(Absence::TYPE_SICK_LEAVE, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14'),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));

		$this->assertCount(1, $bd['credited']);
		$this->assertEqualsWithDelta(8.0, $bd['credit_hours'], 0.0001);
	}

	public function testVacationBehindClosedLeaveGateIsGateDisabled(): void
	{
		$this->flags('0', '0'); // both gates off
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14'),
			$this->absence(Absence::TYPE_UNPAID_LEAVE, Absence::STATUS_APPROVED, '2026-09-15', '2026-09-15'),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));

		$this->assertCount(2, $bd['not_counted']);
		$reasons = array_column($bd['not_counted'], 'reason');
		$this->assertSame(['gate_disabled', 'gate_disabled'], $reasons);
		$this->assertEqualsWithDelta(0.0, $bd['credit_hours'], 0.0001);
		$this->assertEqualsWithDelta(0.0, $bd['reduced_hours'], 0.0001);
	}

	public function testWeekendOnlyAbsenceIsNoPlannedHours(): void
	{
		$this->flags('0', '1');
		// 2026-09-19/20 are Saturday/Sunday — legacy model plans nothing.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-19', '2026-09-20', 2.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-19'), new \DateTime('2026-09-20'));

		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('no_planned_hours', $bd['not_counted'][0]['reason']);
		$this->assertEqualsWithDelta(0.0, $bd['credit_hours'], 0.0001);
	}

	public function testWorkedDayAlreadyCoversIsFullyWorked(): void
	{
		$this->flags('0', '1');
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14'),
		]);

		$bd = $this->service()->creditBreakdownForRange(
			'alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'),
			['2026-09-14' => 8.0],
		);

		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('fully_worked', $bd['not_counted'][0]['reason']);
		$this->assertEqualsWithDelta(0.0, $bd['credit_hours'], 0.0001);
		$this->assertSame(0, $bd['days_credited']);
	}

	public function testOverlappingAbsenceLosesMergeToOverlapReason(): void
	{
		$this->flags('0', '1');
		// Imported overlap (writes reject overlaps, imports may not):
		// A covers Mon+Tue, B covers only Tue — B loses every produced day.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-15', 2.0),
			$this->absence(Absence::TYPE_SPECIAL_LEAVE, Absence::STATUS_APPROVED, '2026-09-15', '2026-09-15', 1.0),
		]);
		$svc = $this->service();

		$bd = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));
		$agg = $svc->creditHoursForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));

		$this->assertSame($agg['hours'], $bd['credit_hours']);
		$this->assertEqualsWithDelta(16.0, $bd['credit_hours'], 0.0001);
		$this->assertCount(1, $bd['credited']);
		$this->assertSame(Absence::TYPE_VACATION, $bd['credited'][0]['type']);
		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('overlap', $bd['not_counted'][0]['reason']);
		$this->assertSame(1, $bd['not_counted'][0]['overlap_days']);
	}

	public function testPartialOverlapWinnerShowsOverlapDaysCount(): void
	{
		$this->flags('0', '1');
		// B spans Tue+Wed; the earlier-starting A keeps contested Tuesday
		// (max-merge keeps the first on equal targets), B wins Wednesday.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-15', '2026-09-15', 1.0),
			$this->absence(Absence::TYPE_SPECIAL_LEAVE, Absence::STATUS_APPROVED, '2026-09-15', '2026-09-16', 2.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-15'), new \DateTime('2026-09-16'));

		$this->assertCount(2, $bd['credited']);
		$b = $bd['credited'][1];
		$this->assertSame(Absence::TYPE_SPECIAL_LEAVE, $b['type']);
		$this->assertCount(1, $b['days']);
		$this->assertSame('2026-09-16', $b['days'][0]['date']);
		$this->assertSame(1, $b['overlap_days']); // produced 2, won 1
		$this->assertEqualsWithDelta(16.0, $bd['credit_hours'], 0.0001);
	}

	public function testUnpaidLeaveReducesSollAndReconciles(): void
	{
		$this->flags('0', '1');
		$this->withAbsences([
			$this->absence(Absence::TYPE_UNPAID_LEAVE, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-15', 2.0),
		]);
		$svc = $this->service();

		$bd = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));
		$agg = $svc->sollReductionHoursForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));

		$this->assertSame($agg['hours'], $bd['reduced_hours']);
		$this->assertSame($agg['days_reduced'], $bd['days_reduced']);
		$this->assertEqualsWithDelta(16.0, $bd['reduced_hours'], 0.0001);
		$this->assertCount(1, $bd['reduced']);
		$this->assertEqualsWithDelta(16.0, $bd['reduced'][0]['reduced_hours'], 0.001);
		$this->assertCount(2, $bd['reduced'][0]['days']);
		$this->assertSame([], $bd['credited']);
	}

	public function testHalfDayVacationCreditsFractionOfPlanned(): void
	{
		$this->flags('0', '1');
		// Stored 0.5 days on a full-weight Monday → half of 8 h planned.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14', 0.5),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));

		$this->assertEqualsWithDelta(4.0, $bd['credit_hours'], 0.0001);
		$day = $bd['credited'][0]['days'][0];
		$this->assertEqualsWithDelta(8.0, $day['planned'], 0.001);
		$this->assertEqualsWithDelta(4.0, $day['absent'], 0.001);
		$this->assertEqualsWithDelta(4.0, $day['credited'], 0.001);
	}

	public function testDurationHoursVacationSpreadsByPlannedWeight(): void
	{
		$this->flags('0', '1');
		// Hours-mode vacation: 4 absent hours over two planned days → 2 h each.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-15', 0.5, 4.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));

		$this->assertEqualsWithDelta(4.0, $bd['credit_hours'], 0.0001);
		$this->assertEqualsWithDelta(2.0, $bd['credited'][0]['days'][0]['absent'], 0.001);
		$this->assertEqualsWithDelta(2.0, $bd['credited'][0]['days'][1]['absent'], 0.001);
	}

	public function testAbsenceClippedToRangeShowsOnlyInRangeDays(): void
	{
		$this->flags('0', '1');
		// Vacation starts before the queried period — only in-range days count.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-12', '2026-09-14', 3.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));

		$this->assertEqualsWithDelta(8.0, $bd['credit_hours'], 0.0001);
		$this->assertCount(1, $bd['credited'][0]['days']);
		// Item keeps the absence's own span for context.
		$this->assertSame('2026-09-12', $bd['credited'][0]['start']);
		$this->assertSame('2026-09-14', $bd['credited'][0]['end']);
	}

	public function testEmptyUserIdAndInvertedRangeReturnEmpty(): void
	{
		$this->flags('1', '1');
		$svc = $this->service();

		$emptyUser = $svc->creditBreakdownForRange('', new \DateTime('2026-09-14'), new \DateTime('2026-09-15'));
		$this->assertSame([], $emptyUser['credited']);
		$this->assertSame([], $emptyUser['not_counted']);
		$this->assertEqualsWithDelta(0.0, $emptyUser['credit_hours'], 0.0001);

		$inverted = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-15'), new \DateTime('2026-09-14'));
		$this->assertSame([], $inverted['credited']);
		$this->assertEqualsWithDelta(0.0, $inverted['credit_hours'], 0.0001);
	}

	public function testMixedFixtureBucketsEveryAbsence(): void
	{
		$this->flags('0', '1');
		// The customer-style mix: vacation credited, business trip neutral,
		// pending sick not approved — one pass, three buckets.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14', 1.0),
			$this->absence(Absence::TYPE_BUSINESS_TRIP, Absence::STATUS_APPROVED, '2026-09-15', '2026-09-15', 1.0),
			$this->absence(Absence::TYPE_SICK_LEAVE, Absence::STATUS_PENDING, '2026-09-16', '2026-09-16', 1.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-16'));

		$this->assertCount(1, $bd['credited']);
		$this->assertCount(2, $bd['not_counted']);
		$reasons = array_column($bd['not_counted'], 'reason');
		sort($reasons);
		$this->assertSame(['effect_none', 'not_approved'], $reasons);
		$this->assertEqualsWithDelta(8.0, $bd['credit_hours'], 0.0001);
	}

	public function testBreakdownFetchesAbsencesExactlyOnce(): void
	{
		$this->flags('0', '1');
		// Single-fetch invariant: the merge consumes the same fetched rows as
		// the candidate pass — a second findByUserAndDateRange would reopen
		// the approve-between-fetches attribution race and double the DB work.
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([
				$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-14', 1.0),
			]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-14'));
		$this->assertCount(1, $bd['credited']);
	}

	public function testDustCreditExcludedExactlyLikeAggregate(): void
	{
		$this->flags('0', '1');
		// Three days each missing only 0.00005 h of Soll — per-day credit is
		// sub-epsilon "dust". The aggregate filters credit ≤ 0.0001 per day;
		// the breakdown must use the identical filter or the headline drifts
		// (3 × 0.00005 = 0.00015 → rounds to 0.0002 ≠ the tile's 0.0).
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-16', 3.0),
		]);
		$worked = [
			'2026-09-14' => 7.99995,
			'2026-09-15' => 7.99995,
			'2026-09-16' => 7.99995,
		];
		$svc = $this->service();

		$bd = $svc->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-16'), $worked);
		$agg = $svc->creditHoursForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-16'), $worked);

		$this->assertSame(0.0, $agg['hours']);
		$this->assertSame($agg['hours'], $bd['credit_hours']);
		$this->assertSame(0, $bd['days_credited']);
		$this->assertSame([], $bd['credited']);
		$this->assertSame('fully_worked', $bd['not_counted'][0]['reason']);
	}

	public function testUnplannedSpanDaysReportedOnItems(): void
	{
		$this->flags('0', '1');
		// Mon–Sun vacation over a Mon–Fri schedule: 5 planned days, 2 span
		// days (Sat/Sun) with nothing planned — must surface so "where did
		// the weekend go" is answered in the drill-down.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-14', '2026-09-20', 5.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-14'), new \DateTime('2026-09-20'));

		$this->assertCount(1, $bd['credited']);
		$this->assertSame(2, $bd['credited'][0]['unplanned_days']);
		$this->assertSame(0, $bd['credited'][0]['overlap_days']);
		$this->assertCount(5, $bd['credited'][0]['days']);
	}

	public function testWeekendOnlyAbsenceReportsUnplannedDays(): void
	{
		$this->flags('0', '1');
		// Sat–Sun absence on a Mon–Fri schedule: nothing produced →
		// 'no_planned_hours' AND the full 2-day span is unplanned.
		$this->withAbsences([
			$this->absence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, '2026-09-19', '2026-09-20', 2.0),
		]);

		$bd = $this->service()->creditBreakdownForRange('alice', new \DateTime('2026-09-19'), new \DateTime('2026-09-20'));

		$this->assertCount(1, $bd['not_counted']);
		$this->assertSame('no_planned_hours', $bd['not_counted'][0]['reason']);
		$this->assertSame(2, $bd['not_counted'][0]['unplanned_days']);
	}
}
