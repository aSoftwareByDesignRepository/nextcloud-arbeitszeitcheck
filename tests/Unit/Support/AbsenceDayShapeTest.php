<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Support\AbsenceDayShape;
use OCA\ArbeitszeitCheck\Support\ShapeInputException;
use PHPUnit\Framework\TestCase;

class AbsenceDayShapeTest extends TestCase
{
	private function dt(string $ymd): \DateTime
	{
		return new \DateTime($ymd);
	}

	private function absence(string $start, string $end, ?float $sF = null, ?string $sS = null, ?float $eF = null, ?string $eS = null): Absence
	{
		$a = new Absence();
		$a->setStartDate($this->dt($start));
		$a->setEndDate($this->dt($end));
		$a->setStartDayFraction($sF);
		$a->setStartDaySlot($sS);
		$a->setEndDayFraction($eF);
		$a->setEndDaySlot($eS);
		return $a;
	}

	// ---- canonicalize ----------------------------------------------------

	public function testCanonicalizeEmptyIsFullShape(): void
	{
		$s = AbsenceDayShape::canonicalize([], $this->dt('2026-08-12'), $this->dt('2026-08-12'));
		$this->assertTrue($s['single']);
		$this->assertSame(1.0, $s['start_fraction']);
		$this->assertNull($s['start_slot']);
		$this->assertFalse($s['fractional']);
	}

	public function testCanonicalizeSingleDayHalfWithSlot(): void
	{
		$s = AbsenceDayShape::canonicalize(
			['day_fraction' => '0.5', 'day_slot' => 'am'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
		$this->assertSame(0.5, $s['start_fraction']);
		$this->assertSame('am', $s['start_slot']);
		$this->assertTrue($s['fractional']);
	}

	public function testCanonicalizeBoundaryHalves(): void
	{
		$s = AbsenceDayShape::canonicalize(
			['start_day_fraction' => '0.5', 'start_day_slot' => 'pm', 'end_day_fraction' => '0.5', 'end_day_slot' => 'am'],
			$this->dt('2026-08-12'), $this->dt('2026-08-15')
		);
		$this->assertFalse($s['single']);
		$this->assertSame(0.5, $s['start_fraction']);
		$this->assertSame('pm', $s['start_slot']);
		$this->assertSame(0.5, $s['end_fraction']);
		$this->assertSame('am', $s['end_slot']);
	}

	public function testGhostSlotWithoutFractionThrows(): void
	{
		$this->expectException(ShapeInputException::class);
		AbsenceDayShape::canonicalize(
			['day_slot' => 'am'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
	}

	public function testInvalidSlotThrows(): void
	{
		$this->expectException(ShapeInputException::class);
		AbsenceDayShape::canonicalize(
			['day_fraction' => '0.5', 'day_slot' => 'noon'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
	}

	public function testQuarterFractionRejectedOnGenericPath(): void
	{
		$this->expectException(ShapeInputException::class);
		AbsenceDayShape::canonicalize(
			['day_fraction' => '0.25'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
	}

	public function testArrayAndBoolFractionRejected(): void
	{
		foreach ([true, ['x' => 'y']] as $bad) {
			try {
				AbsenceDayShape::canonicalize(['day_fraction' => $bad], $this->dt('2026-08-12'), $this->dt('2026-08-12'));
				$this->fail('expected ShapeInputException for ' . var_export($bad, true));
			} catch (ShapeInputException $e) {
				$this->assertInstanceOf(ShapeInputException::class, $e);
			}
		}
	}

	public function testEndFractionOnSingleDayThrows(): void
	{
		$this->expectException(ShapeInputException::class);
		AbsenceDayShape::canonicalize(
			['end_day_fraction' => '0.5'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
	}

	public function testLegacyDayFractionOnRangeMapsToStartBoundary(): void
	{
		$s = AbsenceDayShape::canonicalize(
			['day_fraction' => '0.5'],
			$this->dt('2026-08-12'), $this->dt('2026-08-14')
		);
		$this->assertSame(0.5, $s['start_fraction']);
		$this->assertTrue($s['legacy_range_fraction']);
	}

	// ---- coversCompatible (complementary overlap predicate) --------------

	public function testComplementaryAmPmSingleDayPasses(): void
	{
		$this->assertTrue(AbsenceDayShape::coversCompatible([
			[0.5, 'am'], [0.5, 'pm'],
		]));
	}

	public function testSameSlotRejected(): void
	{
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.5, 'am'], [0.5, 'am'],
		]));
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.5, 'pm'], [0.5, 'pm'],
		]));
	}

	public function testSlotlessHalfCannotPair(): void
	{
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.5, 'am'], [0.5, null],
		]));
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.5, null], [0.5, null],
		]));
	}

	public function testFullCoverAlwaysConflicts(): void
	{
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[1.0, null], [0.5, 'am'],
		]));
	}

	public function testThreeHalvesRejected(): void
	{
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.5, 'am'], [0.5, 'pm'], [0.5, 'am'],
		]));
	}

	public function testDistinctSlotsOverfillRejected(): void
	{
		// Distinct slots alone do not legalise a pair — combined coverage
		// must stay ≤ 1.0.
		$this->assertFalse(AbsenceDayShape::coversCompatible([
			[0.75, 'am'], [0.75, 'pm'],
		]));
		$this->assertTrue(AbsenceDayShape::coversCompatible([
			[0.5, 'am'], [0.5, 'pm'],
		]));
	}

	// ---- debitForShape -----------------------------------------------------

	public function testDebitSingleDayHalfWeightRelative(): void
	{
		$shape = AbsenceDayShape::canonicalize(
			['day_fraction' => '0.5'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
		$r = AbsenceDayShape::debitForShape(
			$shape, 'u', $this->dt('2026-08-12'), $this->dt('2026-08-12'),
			fn ($a, $b) => 0.5 // half-weight company holiday
		);
		$this->assertEqualsWithDelta(0.25, $r['days'], 0.0001);
	}

	public function testDebitBoundaryHalvesMultiDay(): void
	{
		$shape = AbsenceDayShape::canonicalize(
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			$this->dt('2026-08-10'), $this->dt('2026-08-14')
		);
		$calls = [
			'2026-08-10|2026-08-14' => 5.0, // whole range
			'2026-08-10|2026-08-10' => 1.0, // start day
			'2026-08-14|2026-08-14' => 1.0, // end day
		];
		$r = AbsenceDayShape::debitForShape(
			$shape, 'u', $this->dt('2026-08-10'), $this->dt('2026-08-14'),
			function ($a, $b) use ($calls) {
				return $calls[$a->format('Y-m-d') . '|' . $b->format('Y-m-d')];
			}
		);
		$this->assertEqualsWithDelta(4.0, $r['days'], 0.0001);
	}

	public function testDebitFullShapeIsWholeRangeWeight(): void
	{
		$shape = AbsenceDayShape::canonicalize([], $this->dt('2026-08-10'), $this->dt('2026-08-14'));
		$r = AbsenceDayShape::debitForShape(
			$shape, 'u', $this->dt('2026-08-10'), $this->dt('2026-08-14'),
			fn ($a, $b) => 5.0
		);
		$this->assertEqualsWithDelta(5.0, $r['days'], 0.0001);
	}

	// ---- columnsForShape / columnsInheritedForRange ------------------------

	public function testColumnsForHalfShape(): void
	{
		$shape = AbsenceDayShape::canonicalize(
			['day_fraction' => '0.5', 'day_slot' => 'pm'],
			$this->dt('2026-08-12'), $this->dt('2026-08-12')
		);
		$this->assertSame([0.5, 'pm', null, null], AbsenceDayShape::columnsForShape($shape));
	}

	public function testColumnsForFullShapeAreNull(): void
	{
		$shape = AbsenceDayShape::canonicalize([], $this->dt('2026-08-12'), $this->dt('2026-08-12'));
		$this->assertSame([null, null, null, null], AbsenceDayShape::columnsForShape($shape));
	}

	public function testInheritedColumnsKeepSurvivingBoundary(): void
	{
		$src = $this->absence('2026-08-10', '2026-08-14', 0.5, 'am', 0.5, 'pm');
		// shortened: end half falls away, start half survives
		$this->assertSame(
			[0.5, 'am', null, null],
			AbsenceDayShape::columnsInheritedForRange($src, $this->dt('2026-08-10'), $this->dt('2026-08-12'))
		);
		// split tail keeps end half, start becomes interior
		$this->assertSame(
			[null, null, 0.5, 'pm'],
			AbsenceDayShape::columnsInheritedForRange($src, $this->dt('2026-08-12'), $this->dt('2026-08-14'))
		);
		// disjoint range inherits nothing
		$this->assertSame(
			[null, null, null, null],
			AbsenceDayShape::columnsInheritedForRange($src, $this->dt('2026-08-20'), $this->dt('2026-08-21'))
		);
	}

	// ---- fractionForBoundary (credit/read path) -----------------------------

	public function testFractionForBoundaryReadsCorrectColumn(): void
	{
		$a = $this->absence('2026-08-10', '2026-08-14', 0.5, 'am', 0.5, 'pm');
		$this->assertSame(0.5, AbsenceDayShape::fractionForBoundary($a, $this->dt('2026-08-10')));
		$this->assertNull(AbsenceDayShape::fractionForBoundary($a, $this->dt('2026-08-12'))); // interior = full
		$this->assertSame(0.5, AbsenceDayShape::fractionForBoundary($a, $this->dt('2026-08-14')));
	}

	public function testFractionForBoundaryLegacyRowIsNull(): void
	{
		$a = $this->absence('2026-08-12', '2026-08-12');
		$this->assertNull(AbsenceDayShape::fractionForBoundary($a, $this->dt('2026-08-12')));
	}
}
