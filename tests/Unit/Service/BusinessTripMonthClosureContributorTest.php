<?php

declare(strict_types=1);

/**
 * Month-closure contributor contract: submitted trips block closure,
 * the snapshot freezes facts (never purpose/free text), contributions
 * are ledger events only, and the canonical hash is deterministic.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerEntry;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculation;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Service\BusinessTripMonthClosureContributor;
use PHPUnit\Framework\TestCase;

final class BusinessTripMonthClosureContributorTest extends TestCase
{
	private BusinessTripMapper $trips;
	private BusinessTripDayCalculationMapper $dayCalcs;
	private BusinessTripCreditLedgerMapper $ledger;
	private BusinessTripMonthClosureContributor $contributor;

	protected function setUp(): void
	{
		$this->trips = $this->createMock(BusinessTripMapper::class);
		$this->dayCalcs = $this->createMock(BusinessTripDayCalculationMapper::class);
		$this->ledger = $this->createMock(BusinessTripCreditLedgerMapper::class);
		$this->contributor = new BusinessTripMonthClosureContributor($this->trips, $this->dayCalcs, $this->ledger);
	}

	private function trip(int $id, string $status = BusinessTrip::STATUS_APPROVED): BusinessTrip
	{
		$t = new BusinessTrip();
		$t->setId($id);
		$t->setUserId('alice');
		$t->setStatus($status);
		$t->setRevision(5);
		$t->setCurrentSnapshotId(3);
		$t->setEvaluationTimezone('Europe/Berlin');
		$t->setPurpose('secret internal reason');
		$t->setStartsAtUtc(new \DateTime('2026-03-10 06:00:00'));
		$t->setEndsAtUtc(new \DateTime('2026-03-11 18:00:00'));
		return $t;
	}

	private function dayCalc(string $localDate, int $tripId = 7): BusinessTripDayCalculation
	{
		$d = new BusinessTripDayCalculation();
		$d->setTripId($tripId);
		$d->setLocalDate(new \DateTime($localDate));
		$d->setRuleSetId(5);
		$d->setPresenceMinutes(1440);
		$d->setActualWorkMinutes(0);
		$d->setActiveTravelMinutes(120);
		$d->setPassiveTravelMinutes(0);
		$d->setComplianceMinutes(600);
		$d->setAccountTargetMinutes(480);
		$d->setAccountTopUpMinutes(480);
		$d->setExportMinutes(480);
		$d->setSnapshotHash('deadbeef');
		return $d;
	}

	public function testSubmittedTripBlocksClosure(): void
	{
		$this->trips->method('findClosureBlockers')->willReturn([$this->trip(7, BusinessTrip::STATUS_SUBMITTED)]);
		$this->assertSame(['BUSINESS_TRIP_SUBMITTED_PENDING'], $this->contributor->blockers('alice', '2026-03'));
	}

	public function testNoPendingTripsNoBlockers(): void
	{
		$this->trips->method('findClosureBlockers')->willReturn([]);
		$this->assertSame([], $this->contributor->blockers('alice', '2026-03'));
	}

	public function testSnapshotExcludesPurposeAndRestrictsDaysToMonth(): void
	{
		// Trip spans Feb 28 → Mar 2; only March days belong in the March snapshot.
		$this->trips->method('findForUserBetween')->willReturn([$this->trip(7)]);
		$this->dayCalcs->method('findForTrip')->willReturn([
			$this->dayCalc('2026-02-28'),
			$this->dayCalc('2026-03-01'),
			$this->dayCalc('2026-03-02'),
		]);
		$snapshot = $this->contributor->snapshot('alice', '2026-03');
		$this->assertCount(1, $snapshot['trips']);
		$days = $snapshot['trips'][0]['days'];
		$this->assertSame(['2026-03-01', '2026-03-02'], array_column($days, 'local_date'));
		// Purpose / free text must never land in a closure payload.
		$this->assertStringNotContainsString('secret internal reason', json_encode($snapshot));
		$this->assertArrayNotHasKey('purpose', $snapshot['trips'][0]);
	}

	public function testContributionsExposeOnlyLedgerFacts(): void
	{
		$entry = new BusinessTripCreditLedgerEntry();
		$entry->setId(11);
		$entry->setEventKey('trip:7:day:2026-03-10');
		$entry->setTripId(7);
		$entry->setLocalDate(new \DateTime('2026-03-10'));
		$entry->setKind(BusinessTripCreditLedgerEntry::KIND_TOP_UP);
		$entry->setMinutes(480);
		$entry->setCreatedAt(new \DateTime('2026-03-20 12:00:00'));
		$this->ledger->method('findForUserBetween')->willReturn([$entry]);
		$rows = $this->contributor->contributions('alice', '2026-03');
		$this->assertCount(1, $rows);
		$this->assertSame('trip:7:day:2026-03-10', $rows[0]['event_key']);
		$this->assertSame(480, $rows[0]['minutes']);
		$this->assertSame('top_up', $rows[0]['kind']);
	}

	public function testCanonicalHashIsDeterministicAndInputSensitive(): void
	{
		$this->trips->method('findForUserBetween')->willReturn([$this->trip(7)]);
		$this->trips->method('findClosureBlockers')->willReturn([]);
		$this->dayCalcs->method('findForTrip')->willReturn([$this->dayCalc('2026-03-10')]);
		$this->ledger->method('findForUserBetween')->willReturn([]);
		$h1 = $this->contributor->canonicalHash('alice', '2026-03');
		$h2 = $this->contributor->canonicalHash('alice', '2026-03');
		$this->assertSame($h1, $h2);
		// A different user/month input path produces a different digest.
		$this->trips = $this->createMock(BusinessTripMapper::class);
		$this->trips->method('findForUserBetween')->willReturn([]);
		$this->trips->method('findClosureBlockers')->willReturn([]);
		$other = new BusinessTripMonthClosureContributor($this->trips, $this->createMock(BusinessTripDayCalculationMapper::class), $this->ledger);
		$this->assertNotSame($h1, $other->canonicalHash('alice', '2026-03'));
	}

	public function testInvalidMonthRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->contributor->blockers('alice', '2026-3');
	}
}
