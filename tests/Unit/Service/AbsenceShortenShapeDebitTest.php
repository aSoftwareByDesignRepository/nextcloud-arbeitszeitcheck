<?php

declare(strict_types=1);

/**
 * Shorten must re-derive the stored day-shape columns together with the
 * debit (EC-13 + slots-boundaries contract).
 *
 * Boundary fractions belong to concrete calendar days: a start half on a
 * shortened range stays truthful (the day already happened), while a
 * stored end half is stale once the old end day leaves the range and must
 * be cleared. Before this fix both shorten paths recomputed `days` at
 * full schedule weight and left the four shape columns untouched — a
 * Mon–Fri vacation with a start half shortened to Mon–Wed debited 3.0
 * instead of 2.5 AND kept an `end_day_fraction=0.5` claiming the new end
 * day was half (stored-shape lie).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AbsenceShortenShapeDebitTest extends TestCase
{
	private AbsenceService $svc;

	protected function setUp(): void
	{
		$ref = new ReflectionClass(AbsenceService::class);
		/** @var AbsenceService $svc */
		$svc = $ref->newInstanceWithoutConstructor();
		// Plain Mon–Fri weight via the HolidayService fallback path
		// (ScheduledWorkdayCounter is final): every weekday counts 1.0 —
		// the scenarios exercise shape inheritance, not the calendar.
		$holidays = $this->createMock(HolidayService::class);
		$holidays->method('computeWorkingDaysForUser')->willReturnCallback(
			static function (string $userId, \DateTime $start, \DateTime $end): float {
				$n = 0.0;
				for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
					if ((int)$d->format('N') <= 5) {
						$n += 1.0;
					}
				}
				return $n;
			}
		);
		foreach (['scheduledWorkdayCounter' => null, 'holidayCalendarService' => $holidays] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setAccessible(true);
			$p->setValue($svc, $val);
		}
		$this->svc = $svc;
	}

	private function applyShorten(Absence $absence, string $newEndYmd): float
	{
		$ref = new ReflectionClass($this->svc);
		$m = $ref->getMethod('applyShortenedRangeShape');
		$m->setAccessible(true);
		return (float)$m->invoke($this->svc, $absence, 'alice', new \DateTime($newEndYmd));
	}

	private function absenceMonFri(): Absence
	{
		$a = new Absence();
		$a->setUserId('alice');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2026-08-03')); // Monday
		$a->setEndDate(new \DateTime('2026-08-07'));   // Friday
		return $a;
	}

	public function testStartHalfSurvivesShortenAndKeepsReducingDebit(): void
	{
		$a = $this->absenceMonFri();
		$a->setDays(4.5);
		$a->setStartDayFraction(0.5);
		$a->setStartDaySlot('am');

		$days = $this->applyShorten($a, '2026-08-05'); // → Mon–Wed

		$this->assertSame(2.5, $days, 'start half must keep reducing the debit');
		$this->assertSame(0.5, $a->getStartDayFraction());
		$this->assertSame('am', $a->getStartDaySlot());
		$this->assertNull($a->getEndDayFraction());
		$this->assertNull($a->getEndDaySlot());
	}

	public function testStaleEndHalfIsClearedAndDoesNotTravel(): void
	{
		$a = $this->absenceMonFri();
		$a->setDays(4.5);
		$a->setEndDayFraction(0.5);
		$a->setEndDaySlot('pm');

		$days = $this->applyShorten($a, '2026-08-05'); // → Mon–Wed

		$this->assertSame(3.0, $days, 'the dropped end half must not travel to the new end day');
		$this->assertNull($a->getStartDayFraction());
		$this->assertNull($a->getStartDaySlot());
		$this->assertNull($a->getEndDayFraction());
		$this->assertNull($a->getEndDaySlot());
	}

	public function testBothBoundaryHalvesShorten(): void
	{
		$a = $this->absenceMonFri();
		$a->setDays(4.0);
		$a->setStartDayFraction(0.5);
		$a->setStartDaySlot('am');
		$a->setEndDayFraction(0.5);
		$a->setEndDaySlot('pm');

		$days = $this->applyShorten($a, '2026-08-05');

		$this->assertSame(2.5, $days);
		$this->assertSame(0.5, $a->getStartDayFraction());
		$this->assertSame('am', $a->getStartDaySlot());
		$this->assertNull($a->getEndDayFraction());
		$this->assertNull($a->getEndDaySlot());
	}

	public function testShortenToSingleDayKeepsStartHalfAsSingleDayShape(): void
	{
		$a = $this->absenceMonFri();
		$a->setDays(4.5);
		$a->setStartDayFraction(0.5);
		$a->setStartDaySlot('am');

		$days = $this->applyShorten($a, '2026-08-03'); // → Monday only

		$this->assertSame(0.5, $days);
		$this->assertSame(0.5, $a->getStartDayFraction());
		$this->assertSame('am', $a->getStartDaySlot());
		$this->assertNull($a->getEndDayFraction(), 'single-day rows must not carry end columns');
		$this->assertNull($a->getEndDaySlot());
	}

	public function testFullShapeShortenStaysFullWeight(): void
	{
		$a = $this->absenceMonFri();
		$a->setDays(5.0);

		$days = $this->applyShorten($a, '2026-08-05');

		$this->assertSame(3.0, $days);
		$this->assertNull($a->getStartDayFraction());
		$this->assertNull($a->getEndDayFraction());
	}

	public function testNonVacationTypeKeepsStartHalf(): void
	{
		$a = $this->absenceMonFri();
		$a->setType(Absence::TYPE_SICK_LEAVE);
		$a->setDays(4.5);
		$a->setStartDayFraction(0.5);

		$days = $this->applyShorten($a, '2026-08-05');

		$this->assertSame(2.5, $days);
		$this->assertSame(0.5, $a->getStartDayFraction());
		$this->assertNull($a->getEndDayFraction());
	}

	public function testSourceUsesShapeAwareDebitOnBothShortenPaths(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AbsenceService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function applyShortenedRangeShape', $src);
		$this->assertStringContainsString('columnsInheritedForRange', $src);
		$this->assertStringContainsString('debitForShape', $src);
		// Both owner + manager shorten must route through the helper — a
		// bare weight recompute after setEndDate is the fixed bug pattern.
		$this->assertSame(
			2,
			substr_count($src, 'applyShortenedRangeShape($absence'),
			'owner and manager shorten paths must share the shape-aware helper'
		);
	}
}
