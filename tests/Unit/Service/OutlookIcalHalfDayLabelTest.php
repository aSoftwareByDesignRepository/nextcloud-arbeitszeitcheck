<?php

declare(strict_types=1);

/**
 * ICS feed half-day label must follow the persisted day-shape columns,
 * not a literal days≈0.5 test. A sealed half day on a half-weight
 * schedule day debits 0.25 and would lose its "Half day" label under
 * the literal check; a stored 0.5 on a weight-0.5 day is a FULL day
 * but would keep it. Shape columns decide; the literal 0.5 stays only
 * as the legacy fallback for unsealed rows.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionFeedService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OutlookIcalHalfDayLabelTest extends TestCase
{
	private function label(?float $days, ?float $startFrac, string $start, string $end): ?string
	{
		$ref = new ReflectionClass(OutlookIcalSubscriptionFeedService::class);
		$svc = $ref->newInstanceWithoutConstructor();
		$m = $ref->getMethod('halfDayLabel');

		$absence = new Absence();
		$absence->setStartDate(new \DateTime($start));
		$absence->setEndDate(new \DateTime($end));
		if ($days !== null) {
			$absence->setDays($days);
		}
		if ($startFrac !== null) {
			$absence->setStartDayFraction($startFrac);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, array $p = []) => $p !== [] ? vsprintf($s, $p) : $s
		);

		return $m->invoke($svc, $absence, $l10n);
	}

	public function testSealedHalfDayOnFullWeightGetsLabel(): void
	{
		$this->assertSame('Half day', $this->label(0.5, 0.5, '2026-08-10', '2026-08-10'));
	}

	public function testSealedHalfDayOnHalfWeightKeepsLabel(): void
	{
		// Debit 0.25 (half of a weight-0.5 day) — literal days≈0.5 misses it.
		$this->assertSame('Half day', $this->label(0.25, 0.5, '2026-08-10', '2026-08-10'));
	}

	public function testFullDayOnHalfWeightIsNotLabelled(): void
	{
		// Stored 0.5 with full coverage (fraction 1.0) — literal test lied.
		$this->assertNull($this->label(0.5, 1.0, '2026-08-10', '2026-08-10'));
	}

	public function testUnsealedLegacyHalfKeepsLiteralFallback(): void
	{
		$this->assertSame('Half day', $this->label(0.5, null, '2026-08-10', '2026-08-10'));
	}

	public function testBoundaryHalfOnRangeIsNotLabelled(): void
	{
		// Half-day is a single-day label; a 4.5 range with a stored start
		// half keeps its bare debit, not a "Half day" claim.
		$this->assertNull($this->label(4.5, 0.5, '2026-08-10', '2026-08-14'));
	}

	public function testFullDayIsNotLabelled(): void
	{
		$this->assertNull($this->label(1.0, null, '2026-08-10', '2026-08-10'));
	}
}
