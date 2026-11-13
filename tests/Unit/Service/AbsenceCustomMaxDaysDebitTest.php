<?php

declare(strict_types=1);

/**
 * EC-16: a custom absence type's `max_days` limit must count the
 * fractional `days` debit (schedule weight × boundary fractions), not
 * the raw calendar range.
 *
 * Before the fix the shared `validateAbsenceTypeRules` counted
 * `start.diff(end) + 1` calendar days for every type — a custom type
 * with maxDays=3 falsely rejected a 4-calendar-day request whose first
 * and last day are halves (real debit 3.0). Built-in caps stay calendar
 * sanity limits; only `custom_*` codes use the debit count.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AbsenceCustomMaxDaysDebitTest extends TestCase
{
	private AbsenceService $svc;

	protected function setUp(): void
	{
		$ref = new ReflectionClass(AbsenceService::class);
		/** @var AbsenceService $svc */
		$svc = $ref->newInstanceWithoutConstructor();

		// Weekday weight 1.0, except 2026-08-05 which simulates a full
		// company holiday (weight 0.0) — needed for the weight-reduced case.
		$holidays = $this->createMock(HolidayService::class);
		$holidays->method('computeWorkingDaysForUser')->willReturnCallback(
			static function (string $userId, \DateTime $start, \DateTime $end): float {
				$n = 0.0;
				for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
					if ((int)$d->format('N') <= 5 && $d->format('Y-m-d') !== '2026-08-05') {
						$n += 1.0;
					}
				}
				return $n;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, array $p = []) => $p !== [] ? vsprintf($s, $p) : $s
		);

		foreach ([
			'holidayCalendarService' => $holidays,
			'l10n' => $l10n,
			'scheduledWorkdayCounter' => null,
			'specialEntitlementService' => null,
		] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setAccessible(true);
			$p->setValue($svc, $val);
		}
		$this->svc = $svc;

		// Seed the static custom-type catalog: `custom_training` allows max 3
		// fractional days per request. validateAbsenceTypeRules resolves the cap
		// through AbsenceTypeCatalog::maxCalendarDays, so the real call site only
		// runs when this definition is visible.
		$custom = new CustomAbsenceType();
		$custom->setCode('custom_training');
		$custom->setMaxDays(3);
		$cat = new ReflectionClass(AbsenceTypeCatalog::class);
		foreach (['customLoaded' => true, 'customCache' => ['custom_training' => $custom]] as $prop => $val) {
			$p = $cat->getProperty($prop);
			$p->setAccessible(true);
			$p->setValue(null, $val);
		}
	}

	protected function tearDown(): void
	{
		AbsenceTypeCatalog::resetCustomTypeCache();
	}

	private function satisfied(string $type, string $start, string $end, array $data, string $userId, int $maxDays): bool
	{
		$ref = new ReflectionClass($this->svc);
		$m = $ref->getMethod('customMaxDaysSatisfiedByDebit');
		$m->setAccessible(true);
		return (bool)$m->invoke($this->svc, $type, new \DateTime($start), new \DateTime($end), $data, $userId, $maxDays);
	}

	/**
	 * Runs the real validateAbsenceTypeRules call site; returns the rejection
	 * message or null when the range passes.
	 */
	private function ruleViolation(string $type, string $start, string $end, array $data, string $userId): ?string
	{
		$ref = new ReflectionClass($this->svc);
		$m = $ref->getMethod('validateAbsenceTypeRules');
		$m->setAccessible(true);
		try {
			$m->invoke($this->svc, $type, new \DateTime($start), new \DateTime($end), $data, $userId);
			return null;
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}

	public function testBoundaryHalvesBringDebitWithinMax(): void
	{
		// Mon–Thu (holiday-free), first + last day half → debit 3.0 ≤ maxDays 3
		// while the raw calendar count 4 would exceed it.
		$this->assertTrue($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'alice', 3
		));
		// Tight limit: debit 3.0 > maxDays 2 → false.
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'alice', 2
		));
	}

	public function testSingleBoundaryHalfStillOverMaxStaysRejected(): void
	{
		// Mon–Thu (holiday-free), only first day half → debit 3.5 > maxDays 3.
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5'],
			'alice', 3
		));
	}

	public function testBuiltInTypeNeverUsesDebitCount(): void
	{
		// Same range+halves would debit 3.0 ≤ 3 — but built-ins keep the
		// calendar cap, so the helper must not apply.
		$this->assertFalse($this->satisfied(
			'sick_leave', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'alice', 3
		));
	}

	public function testWeightReducedRangeCountsAsDebit(): void
	{
		// Mon–Thu includes the company holiday (weight 0) → debit 3.0
		// without any fraction — `days` semantics is weight-based.
		$this->assertTrue($this->satisfied(
			'custom_training', '2026-08-03', '2026-08-06',
			[],
			'alice', 3
		));
	}

	public function testFullWeightRangeStaysRejected(): void
	{
		// Holiday-free Mon–Fri at full weight → debit 5.0 > maxDays 3.
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-14',
			[],
			'alice', 3
		));
	}

	public function testMissingUserContextFallsBackToCalendar(): void
	{
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'', 3
		));
	}

	public function testInvalidShapeInputNeverFailsOpen(): void
	{
		// A slot without a fraction is invalid — the helper must not
		// turn that into a pass; the real shape error surfaces downstream.
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_slot' => 'am'],
			'alice', 3
		));
	}

	public function testLegacyRangeFractionCannotSneakUnderCap(): void
	{
		// Legacy day_fraction=0.5 on a range maps onto the start boundary
		// → debit 3.5 > 3 → still rejected (RANGE_FORBIDDEN fires downstream
		// whenever it does fit — either way the request fails closed).
		$this->assertFalse($this->satisfied(
			'custom_training', '2026-08-10', '2026-08-13',
			['day_fraction' => '0.5'],
			'alice', 3
		));
	}

	public function testCallSiteAcceptsCustomRangeWhenHalvesFitCap(): void
	{
		// End-to-end through validateAbsenceTypeRules: 4 calendar days over the
		// configured max 3, but explicit boundary halves bring the debit to
		// exactly 3.0 — the request must be accepted.
		$this->assertNull($this->ruleViolation(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'alice'
		));
	}

	public function testCallSiteRejectsCustomRangeWhenDebitStillOverCap(): void
	{
		$msg = $this->ruleViolation(
			'custom_training', '2026-08-10', '2026-08-13',
			['start_day_fraction' => '0.5'],
			'alice'
		);
		$this->assertNotNull($msg);
		$this->assertStringContainsString('3', (string)$msg);
	}

	public function testCallSiteKeepsCalendarCapForBuiltIn(): void
	{
		// personal_leave caps at 5 calendar days; a 6-day range with halves
		// (debit 5.0) must NOT be rescued — the debit path is custom-only.
		$this->assertNotNull($this->ruleViolation(
			'personal_leave', '2026-08-10', '2026-08-15',
			['start_day_fraction' => '0.5', 'end_day_fraction' => '0.5'],
			'alice'
		));
	}
}
