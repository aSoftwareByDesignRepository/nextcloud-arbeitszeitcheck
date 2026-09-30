<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCA\ArbeitszeitCheck\Support\VacationYearWindow;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class VacationYearWindowResolverTest extends TestCase
{
	private function resolver(string $mode, ?\DateTimeImmutable $hire = null): VacationYearWindowResolver
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($mode);
		$employment = $this->createMock(UserEmploymentSettingsService::class);
		$employment->method('getEmploymentStart')->willReturn($hire);
		return new VacationYearWindowResolver($config, $employment);
	}

	public function testCalendarDefaultWindow(): void
	{
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_CALENDAR);
		$w = $r->resolveForUser('u1', new \DateTimeImmutable('2026-08-04'));
		$this->assertSame(VacationYearWindow::MODE_CALENDAR, $w->mode);
		$this->assertSame(2026, $w->balanceYearKey);
		$this->assertSame('2026-01-01', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2026-12-31', $w->lastInclusiveDay()->format('Y-m-d'));
		$this->assertSame('2027-01-01', $w->endExclusive->format('Y-m-d'));
		$this->assertFalse($w->missingEmploymentStart);
	}

	public function testAnniversaryBanssWindowAc101(): void
	{
		$hire = new \DateTimeImmutable('2026-07-01');
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $hire);
		$w = $r->resolveForUser('u1', new \DateTimeImmutable('2026-08-04'));
		$this->assertSame(VacationYearWindow::MODE_ANNIVERSARY, $w->mode);
		$this->assertSame(2026, $w->balanceYearKey);
		$this->assertSame('2026-07-01', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2027-06-30', $w->lastInclusiveDay()->format('Y-m-d'));
		$this->assertSame('2027-07-01', $w->endExclusive->format('Y-m-d'));
		$this->assertSame('2026-07-01 – 2027-06-30', $w->label);
	}

	public function testAnniversaryNextWindowAfterBoundary(): void
	{
		$hire = new \DateTimeImmutable('2026-07-01');
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $hire);
		$w = $r->resolveForUser('u1', new \DateTimeImmutable('2027-07-01'));
		$this->assertSame('2027-07-01', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2028-06-30', $w->lastInclusiveDay()->format('Y-m-d'));
	}

	public function testAnniversaryMissingStartFailsClosed(): void
	{
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_ANNIVERSARY, null);
		$w = $r->resolveForUser('u1', new \DateTimeImmutable('2026-08-04'));
		$this->assertTrue($w->missingEmploymentStart);
		$this->assertSame(VacationYearWindow::MODE_ANNIVERSARY, $w->mode);
	}

	public function testFeb29ClampsInNonLeapYear(): void
	{
		$hire = new \DateTimeImmutable('2020-02-29');
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $hire);
		$first = $r->anniversaryOnOrAfter($hire, 1);
		$this->assertSame('2021-02-28', $first->format('Y-m-d'));
		$w = $r->resolveAnniversaryWindow($hire, new \DateTimeImmutable('2021-03-01'));
		$this->assertSame('2021-02-28', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2022-02-28', $w->endExclusive->format('Y-m-d'));
	}

	public function testNormalizeModeUnknownFallsBackToCalendar(): void
	{
		$this->assertSame(
			Constants::VACATION_YEAR_MODE_CALENDAR,
			VacationYearWindowResolver::normalizeMode('nope')
		);
	}

	public function testWindowsOverlappingRangeSpansAnniversaryBoundary(): void
	{
		$hire = new \DateTimeImmutable('2026-07-01');
		$r = $this->resolver(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $hire);
		$windows = $r->windowsOverlappingRange(
			'u1',
			new \DateTimeImmutable('2027-06-28'),
			new \DateTimeImmutable('2027-07-05')
		);
		$this->assertCount(2, $windows);
		$this->assertSame('2026-07-01', $windows[0]->startInclusive->format('Y-m-d'));
		$this->assertSame('2027-07-01', $windows[1]->startInclusive->format('Y-m-d'));
	}

	public function testResolveAnniversaryBalanceYear(): void
	{
		$resolver = $this->resolver('anniversary');
		$hire = new \DateTimeImmutable('2024-03-15');
		// balance year 2025 = second anniversary year (n=1)
		$w = $resolver->resolveAnniversaryBalanceYear($hire, 2025);
		$this->assertNotNull($w);
		$this->assertSame('2025-03-15', $w->startInclusive->format('Y-m-d'));
		$this->assertSame('2026-03-15', $w->endExclusive->format('Y-m-d'));
		$this->assertFalse($w->missingEmploymentStart);

		// out-of-range balance years -> null
		$this->assertNull($resolver->resolveAnniversaryBalanceYear($hire, 2023));
		$this->assertNull($resolver->resolveAnniversaryBalanceYear($hire, 2145));
	}

	public function testResolveAnniversaryForUserBalanceYearSentinels(): void
	{
		// missing employment start -> calendar-shaped sentinel flagged as missing
		$resolverNoHire = $this->resolver('anniversary', null);
		$w = $resolverNoHire->resolveAnniversaryForUserBalanceYear('alice', 2026);
		$this->assertTrue($w->missingEmploymentStart);
		$this->assertSame('2026-01-01', $w->startInclusive->format('Y-m-d'));

		// known hire -> real anniversary window
		$resolver = $this->resolver('anniversary', new \DateTimeImmutable('2024-03-15'));
		$w2 = $resolver->resolveAnniversaryForUserBalanceYear('alice', 2025);
		$this->assertFalse($w2->missingEmploymentStart);
		$this->assertSame('2025-03-15', $w2->startInclusive->format('Y-m-d'));

		// out-of-range balance year -> mid-year probe fallback window
		$w3 = $resolver->resolveAnniversaryForUserBalanceYear('alice', 2300);
		$this->assertFalse($w3->missingEmploymentStart);
		$this->assertNotNull($w3);
	}
}