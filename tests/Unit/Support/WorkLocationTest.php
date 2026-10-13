<?php

declare(strict_types=1);

/**
 * Contract tests for {@see WorkLocation} — the single allowlist boundary
 * for per-entry work-location marking (GH #43).
 *
 * Every trust boundary (web form, API, kiosk, mobile replay, manager
 * correction, admin default) runs caller input through normalize(); these
 * tests pin down accept/reject semantics so a later "be liberal" edit cannot
 * silently widen the wire contract.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\WorkLocation;
use PHPUnit\Framework\TestCase;

class WorkLocationTest extends TestCase
{
	public function testAllowlistIsExactlyOfficeHomeExternal(): void
	{
		$this->assertSame(['office', 'home', 'external'], WorkLocation::ALL);
		$this->assertSame('office', WorkLocation::FALLBACK);
		$this->assertTrue(in_array(WorkLocation::FALLBACK, WorkLocation::ALL, true));
	}

	public function testIsValidAcceptsCanonicalValues(): void
	{
		foreach (WorkLocation::ALL as $value) {
			$this->assertTrue(WorkLocation::isValid($value), $value);
		}
	}

	public function testIsValidRejectsNonCanonicalStrings(): void
	{
		foreach (['Office', 'HOME', ' remote ', 'home_office', 'extern', '', '0'] as $bad) {
			$this->assertFalse(WorkLocation::isValid($bad), var_export($bad, true));
		}
	}

	public function testNormalizeReturnsNullForAbsentValues(): void
	{
		$this->assertNull(WorkLocation::normalize(null));
		$this->assertNull(WorkLocation::normalize(''));
		$this->assertNull(WorkLocation::normalize('   '));
	}

	public function testNormalizeTrimsAndLowercases(): void
	{
		$this->assertSame('home', WorkLocation::normalize(' Home '));
		$this->assertSame('external', WorkLocation::normalize('EXTERNAL'));
		$this->assertSame('office', WorkLocation::normalize("\tOffice\n"));
	}

	public function testNormalizeThrowsOnInvalidNonEmptyValues(): void
	{
		foreach (['remote', 'home_office', 'extern', '0', 'null'] as $raw) {
			try {
				WorkLocation::normalize($raw);
				$this->fail("normalize({$raw}) did not throw");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testNormalizeThrowsOnNonStringTypes(): void
	{
		foreach ([0, 1, 1.5, true, false, ['home'], new \stdClass()] as $raw) {
			try {
				WorkLocation::normalize($raw);
				$this->fail('normalize did not throw for ' . get_debug_type($raw));
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testLabelKeyReturnsCanonicalSuffixOrNull(): void
	{
		$this->assertSame('home', WorkLocation::labelKey('home'));
		$this->assertSame('external', WorkLocation::labelKey('external'));
		$this->assertSame('office', WorkLocation::labelKey('office'));
		$this->assertNull(WorkLocation::labelKey(null));
		$this->assertNull(WorkLocation::labelKey('bogus'));
		$this->assertNull(WorkLocation::labelKey(''));
	}
}
