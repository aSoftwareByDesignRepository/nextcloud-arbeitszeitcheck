<?php

declare(strict_types=1);

/**
 * HalfDayTypePolicy (AZC-HALF-DAY-NON-VACATION): fail-closed config parse,
 * curated eligibility, save normalization and admin options.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Support\HalfDayTypePolicy;
use PHPUnit\Framework\TestCase;

class HalfDayTypePolicyTest extends TestCase
{
	public function testEnabledTypesFromRawDefaultsToVacation(): void
	{
		$this->assertSame(['vacation'], HalfDayTypePolicy::enabledTypesFromRaw(null));
		$this->assertSame(['vacation'], HalfDayTypePolicy::enabledTypesFromRaw(''));
		$this->assertSame(['vacation'], HalfDayTypePolicy::enabledTypesFromRaw('   '));
	}

	/**
	 * Corrupted config must fail closed to the legacy default — never
	 * silently lose the working vacation control.
	 */
	public function testEnabledTypesFromRawFailsClosedOnGarbage(): void
	{
		foreach ([
			'not-json',
			'{invalid',
			'{"a":1}',
			'"vacation"',
			'42',
			'["sick_leave", 5]',
			'["sick_leave", "BAD ID!"]',
			'["sick_leave", ""]',
			'["sick_leave", "  "]',
		] as $raw) {
			$this->assertSame(['vacation'], HalfDayTypePolicy::enabledTypesFromRaw($raw), "raw=$raw");
		}
	}

	public function testEnabledTypesFromRawHonoursEmptyList(): void
	{
		// A deliberate [] switches half-day off entirely (vacation too).
		$this->assertSame([], HalfDayTypePolicy::enabledTypesFromRaw('[]'));
	}

	public function testEnabledTypesFromRawParsesAndDedupes(): void
	{
		$this->assertSame(
			['vacation', 'sick_leave', 'custom_extra'],
			HalfDayTypePolicy::enabledTypesFromRaw('["vacation","sick_leave","sick_leave"," custom_extra "]')
		);
	}

	public function testIsEligibleTypeCuratesBuiltins(): void
	{
		foreach ([
			Absence::TYPE_VACATION,
			Absence::TYPE_SICK_LEAVE,
			Absence::TYPE_CHILD_SICK,
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
			Absence::TYPE_UNPAID_LEAVE,
		] as $type) {
			$this->assertTrue(HalfDayTypePolicy::isEligibleType($type), $type);
		}
	}

	public function testIsEligibleTypeNeverArmsFullDayOnlyTypes(): void
	{
		$this->assertFalse(HalfDayTypePolicy::isEligibleType(Absence::TYPE_OVERTIME_COMP));
		$this->assertFalse(HalfDayTypePolicy::isEligibleType(Absence::TYPE_PARENTAL_LEAVE));
		$this->assertFalse(HalfDayTypePolicy::isEligibleType('made_up_type'));
	}

	public function testIsEnabledForTypeRequiresBothGates(): void
	{
		$enabled = ['vacation', 'sick_leave', 'overtime_comp'];
		$this->assertTrue(HalfDayTypePolicy::isEnabledForType('sick_leave', $enabled));
		$this->assertTrue(HalfDayTypePolicy::isEnabledForType('vacation', $enabled));
		$this->assertFalse(HalfDayTypePolicy::isEnabledForType('personal_leave', $enabled));
		// in the allowlist but never eligible (full-day-only contract)
		$this->assertFalse(HalfDayTypePolicy::isEnabledForType('overtime_comp', $enabled));
		$this->assertFalse(HalfDayTypePolicy::isEnabledForType('sick_leave', []));
	}

	public function testNormalizeSaveValueDedupesAndFilters(): void
	{
		$this->assertSame(
			'["vacation","sick_leave"]',
			HalfDayTypePolicy::normalizeSaveValue(['vacation', 'sick_leave', 'sick_leave', 'BAD!', '', 5])
		);
		$this->assertSame(
			'["vacation","sick_leave"]',
			HalfDayTypePolicy::normalizeSaveValue('["vacation","sick_leave"]')
		);
		$this->assertSame('[]', HalfDayTypePolicy::normalizeSaveValue('not-json'));
		$this->assertSame('[]', HalfDayTypePolicy::normalizeSaveValue(null));
		$this->assertSame('[]', HalfDayTypePolicy::normalizeSaveValue(42));
	}

	public function testNormalizeSaveValueKeepsUnknownIds(): void
	{
		// Deactivated custom ids stay — reactivation must not lose the flag.
		$this->assertSame(
			'["custom_archived"]',
			HalfDayTypePolicy::normalizeSaveValue(['custom_archived'])
		);
	}

	public function testCreditEffectTypesFiltersBySollEffect(): void
	{
		$enabled = ['vacation', 'sick_leave', 'unpaid_leave', 'home_office'];
		$credit = HalfDayTypePolicy::creditEffectTypes($enabled);
		$this->assertContains('vacation', $credit);
		$this->assertContains('sick_leave', $credit);
		// unpaid leave / home office do not credit planned hours
		$this->assertNotContains('unpaid_leave', $credit);
		$this->assertNotContains('home_office', $credit);
	}
}
