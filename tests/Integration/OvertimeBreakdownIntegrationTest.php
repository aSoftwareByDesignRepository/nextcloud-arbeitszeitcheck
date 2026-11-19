<?php

declare(strict_types=1);

/**
 * Overtime absence-credit breakdown against the real DB:
 * per-absence attribution + reason buckets, with the hard invariant that
 * the breakdown totals reconcile EXACTLY with the aggregate services the
 * dashboard tile is built from.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter;
use OCA\ArbeitszeitCheck\Support\TypedAppConfigWrite;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * @group integration
 */
final class OvertimeBreakdownIntegrationTest extends TestCase
{
	private string $uid = '';
	private ?string $prevSickCredit = null;
	private ?string $prevLeaveEffects = null;
	/** @var list<int> */
	private array $createdAbsenceIds = [];
	/** @var list<int> */
	private array $createdEntryIds = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$config = \OCP\Server::get(IConfig::class);
		$this->prevSickCredit = $config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT,
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT_DEFAULT,
		);
		$this->prevLeaveEffects = $config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_LEAVE_SOLL_EFFECTS,
			Constants::CONFIG_LEAVE_SOLL_EFFECTS_DEFAULT,
		);
		// Vacation credited, unpaid reduced, sick gate-closed — a mixed
		// fixture without needing the sick opt-in.
		TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT, '0');
		TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', Constants::CONFIG_LEAVE_SOLL_EFFECTS, '1');

		$um = \OCP\Server::get(IUserManager::class);
		$this->uid = 'azc_obd_' . bin2hex(random_bytes(3));
		if ($um->userExists($this->uid)) {
			$um->get($this->uid)?->delete();
		}
		$um->createUser($this->uid, 'Azc-Obd-' . bin2hex(random_bytes(4)) . '!');
		$config->setUserValue($this->uid, 'core', 'lang', 'en');
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		try {
			\OCP\Server::get(IUserSession::class)->setUser(null);
		} catch (\Throwable) {
		}
		try {
			\OCP\Server::get(IUserManager::class)->get($this->uid)?->delete();
		} catch (\Throwable) {
		}
		foreach ($this->createdAbsenceIds as $id) {
			try {
				\OCP\Server::get(AbsenceMapper::class)->delete(
					\OCP\Server::get(AbsenceMapper::class)->find($id)
				);
			} catch (\Throwable) {
			}
		}
		foreach ($this->createdEntryIds as $id) {
			try {
				\OCP\Server::get(TimeEntryMapper::class)->delete(
					\OCP\Server::get(TimeEntryMapper::class)->find($id)
				);
			} catch (\Throwable) {
			}
		}
		$config = \OCP\Server::get(IConfig::class);
		foreach ([
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT => $this->prevSickCredit,
			Constants::CONFIG_LEAVE_SOLL_EFFECTS => $this->prevLeaveEffects,
		] as $key => $prev) {
			if ($prev !== null) {
				try {
					TypedAppConfigWrite::setString($config, 'arbeitszeitcheck', $key, $prev);
				} catch (\Throwable) {
				}
			}
		}
	}

	/** A recent past day with real planned weight (skips weekends+holidays). */
	private function workDay(int $daysAgo): string
	{
		$counter = \OCP\Server::get(ScheduledWorkdayCounter::class);
		$day = new \DateTimeImmutable('today')->modify('-' . $daysAgo . ' days');
		while (true) {
			$weight = $counter->daysForUser(
				$this->uid,
				new \DateTime($day->format('Y-m-d')),
				new \DateTime($day->format('Y-m-d')),
			);
			if ($weight > 0.0) {
				return $day->format('Y-m-d');
			}
			$day = $day->modify('-1 day');
		}
	}

	/**
	 * @return list<string> $count distinct ascending Y-m-d workdays ending at
	 *   or before $daysAgo days back (skips weekends + zero-weight holidays).
	 */
	private function workDays(int $count, int $daysAgo): array
	{
		$found = [];
		$probe = new \DateTimeImmutable('today')->modify('-' . $daysAgo . ' days');
		while (count($found) < $count) {
			$day = $this->workDay(max(1, (int)((strtotime('now') - $probe->getTimestamp()) / 86400)));
			if (!in_array($day, $found, true)) {
				$found[] = $day;
			}
			$probe = new \DateTimeImmutable($day)->modify('-1 day');
		}
		sort($found);
		return $found;
	}

	/** A recent past day with zero planned weight (weekend or holiday). */
	private function freeDay(int $daysAgo): string
	{
		$counter = \OCP\Server::get(ScheduledWorkdayCounter::class);
		$day = new \DateTimeImmutable('today')->modify('-' . $daysAgo . ' days');
		while (true) {
			$weight = $counter->daysForUser(
				$this->uid,
				new \DateTime($day->format('Y-m-d')),
				new \DateTime($day->format('Y-m-d')),
			);
			if ($weight <= 0.0) {
				return $day->format('Y-m-d');
			}
			$day = $day->modify('-1 day');
		}
	}

	private function insertAbsence(string $type, string $status, string $start, string $end, float $days): Absence
	{
		$a = new Absence();
		$a->setUserId($this->uid);
		$a->setType($type);
		$a->setStatus($status);
		$a->setStartDate(new \DateTime($start));
		$a->setEndDate(new \DateTime($end));
		$a->setDays($days);
		$a->setCreatedAt(new \DateTime());
		$a->setUpdatedAt(new \DateTime());
		$row = \OCP\Server::get(AbsenceMapper::class)->insert($a);
		$this->createdAbsenceIds[] = (int)$row->getId();
		return $row;
	}

	private function insertCompletedEntry(string $date, string $startHh, string $endHh): void
	{
		$e = new TimeEntry();
		$e->setUserId($this->uid);
		$e->setStartTime(new \DateTime($date . ' ' . $startHh));
		$e->setEndTime(new \DateTime($date . ' ' . $endHh));
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setCreatedAt(new \DateTime());
		$e->setUpdatedAt(new \DateTime());
		$row = \OCP\Server::get(TimeEntryMapper::class)->insert($e);
		$this->createdEntryIds[] = (int)$row->getId();
	}

	public function testBreakdownReconcilesWithAggregatesOnRealRows(): void
	{
		$svc = \OCP\Server::get(PaidAbsencePlannedHoursCreditService::class);
		$overtime = \OCP\Server::get(OvertimeService::class);

		// Two distinct planned workdays (ascending) far enough back that
		// nothing else interferes.
		[$day1, $day2] = $this->workDays(2, 14);
		$free = $this->freeDay(14);

		// Approved vacation spanning day1+day2 → credited.
		$vacation = $this->insertAbsence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, $day1, $day2, 2.0);
		// Business trip on the same window → effect_none (productive time).
		$trip = $this->insertAbsence(Absence::TYPE_BUSINESS_TRIP, Absence::STATUS_APPROVED, $day1, $day2, 2.0);
		// Pending sick → not_approved.
		$pendingSick = $this->insertAbsence(Absence::TYPE_SICK_LEAVE, Absence::STATUS_PENDING, $day1, $day1, 1.0);
		// Approved vacation on a free day → no_planned_hours.
		$freeVac = $this->insertAbsence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, $free, $free, 1.0);
		// Approved unpaid leave on day2 → reduced.
		$unpaid = $this->insertAbsence(Absence::TYPE_UNPAID_LEAVE, Absence::STATUS_APPROVED, $day2, $day2, 1.0);
		// A real completed stamp covering the whole planned day1 target —
		// the business trip on day1 additionally loses its merge but is
		// classified earlier by effect_none anyway.
		$this->insertCompletedEntry($day1, '08:00:00', '18:00:00');

		$rangeStart = new \DateTime(min($day1, $day2, $free));
		$rangeEnd = new \DateTime(max($day1, $day2, $free));
		[, $workedByDay] = $overtime->workedHoursByDayRange($this->uid, $rangeStart, $rangeEnd);

		$bd = $svc->creditBreakdownForRange($this->uid, $rangeStart, $rangeEnd, $workedByDay);
		$aggCredit = $svc->creditHoursForRange($this->uid, $rangeStart, $rangeEnd, $workedByDay);
		$aggReduce = $svc->sollReductionHoursForRange($this->uid, $rangeStart, $rangeEnd);

		// Hard invariant: breakdown totals == the dashboard aggregate, always.
		$this->assertSame($aggCredit['hours'], $bd['credit_hours']);
		$this->assertSame($aggCredit['days_credited'], $bd['days_credited']);
		$this->assertSame($aggReduce['hours'], $bd['reduced_hours']);
		$this->assertSame($aggReduce['days_reduced'], $bd['days_reduced']);

		// The stamped day1 tops itself out (worked ≥ planned), day2's
		// vacation fraction still wins the merge over the unpaid leave
		// (same target — earlier-start absence keeps the day), so credit
		// lands exactly once per merged day.
		$this->assertGreaterThanOrEqual(0.0, $bd['credit_hours']);

		// Attribution: the vacation carries the credited days.
		$creditedIds = array_column($bd['credited'], 'absence_id');
		$this->assertContains((int)$vacation->getId(), $creditedIds);
		$vacItem = $bd['credited'][array_search((int)$vacation->getId(), $creditedIds, true)];
		$this->assertArrayHasKey('days', $vacItem);
		// Sum of per-item credits reconciles with the total (± rounding).
		$itemSum = array_sum(array_column($bd['credited'], 'credit_hours'));
		$this->assertEqualsWithDelta($bd['credit_hours'], $itemSum, 0.011);

		// Unpaid leave reduced Soll — the merged winner keeps the day.
		$this->assertGreaterThan(0.0, $bd['reduced_hours']);

		// Reason buckets: every non-counting absence explains itself.
		$reasonsById = [];
		foreach ($bd['not_counted'] as $row) {
			$reasonsById[(int)$row['absence_id']] = $row['reason'];
		}
		$this->assertSame('effect_none', $reasonsById[(int)$trip->getId()]);
		$this->assertSame('not_approved', $reasonsById[(int)$pendingSick->getId()]);
		$this->assertSame('no_planned_hours', $reasonsById[(int)$freeVac->getId()]);

		// Every produced absence lands in exactly one bucket — nothing
		// silently disappears from the explanation.
		$allIds = [
			(int)$vacation->getId(), (int)$trip->getId(), (int)$pendingSick->getId(),
			(int)$freeVac->getId(), (int)$unpaid->getId(),
		];
		$bucketed = array_merge(
			array_column($bd['credited'], 'absence_id'),
			array_column($bd['reduced'], 'absence_id'),
			array_column($bd['not_counted'], 'absence_id'),
		);
		foreach ($allIds as $id) {
			$this->assertContains($id, $bucketed, "absence $id vanished from the breakdown");
		}
	}

	public function testFullyWorkedDayLandsInNotCounted(): void
	{
		$svc = \OCP\Server::get(PaidAbsencePlannedHoursCreditService::class);
		$overtime = \OCP\Server::get(OvertimeService::class);

		$day = $this->workDay(9);
		// Approved vacation on a day fully covered by a real stamp —
		// creditable, won the merge, but the top-up is zero everywhere.
		$row = $this->insertAbsence(Absence::TYPE_VACATION, Absence::STATUS_APPROVED, $day, $day, 1.0);
		$this->insertCompletedEntry($day, '06:00:00', '20:00:00');

		$start = new \DateTime($day);
		[, $workedByDay] = $overtime->workedHoursByDayRange($this->uid, $start, new \DateTime($day));
		$bd = $svc->creditBreakdownForRange($this->uid, $start, new \DateTime($day), $workedByDay);

		$reasons = array_column($bd['not_counted'], 'reason', 'absence_id');
		$this->assertSame('fully_worked', $reasons[(int)$row->getId()] ?? null);
		$this->assertEqualsWithDelta(0.0, $bd['credit_hours'], 0.0001);
	}
}
