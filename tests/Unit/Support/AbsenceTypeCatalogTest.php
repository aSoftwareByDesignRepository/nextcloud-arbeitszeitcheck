<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AbsenceTypeCatalogTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn ($text) => $text);
		return $l;
	}

	public function testOrderContainsEveryTypeExactlyOnce(): void
	{
		$order = AbsenceTypeCatalog::all();
		$this->assertSame(count($order), count(array_unique($order)));
		$this->assertContains(Absence::TYPE_CHILD_SICK, $order);
		$this->assertContains(Absence::TYPE_OVERTIME_COMP, $order);
		$this->assertContains(Absence::TYPE_VACATION, $order);
		$this->assertContains(Absence::TYPE_SICK_LEAVE, $order);
	}

	public function testConstantsAliasMatchesCatalogOrder(): void
	{
		// Constants::ABSENCE_TYPES is the BC alias — must never drift.
		$this->assertSame(AbsenceTypeCatalog::ORDER, Constants::ABSENCE_TYPES);
		$this->assertSame(AbsenceTypeCatalog::all(), Constants::ABSENCE_TYPES);
	}

	public function testNewTypesKeepExistingOrderPositions(): void
	{
		// child_sick follows sick_leave (family grouping); overtime_comp is
		// appended last so existing option order is untouched.
		$order = AbsenceTypeCatalog::all();
		$this->assertSame('sick_leave', $order[array_search('child_sick', $order, true) - 1]);
		$this->assertSame('overtime_comp', end($order));
	}

	public function testIsValid(): void
	{
		$this->assertTrue(AbsenceTypeCatalog::isValid('child_sick'));
		$this->assertTrue(AbsenceTypeCatalog::isValid('overtime_comp'));
		$this->assertTrue(AbsenceTypeCatalog::isValid('vacation'));
		$this->assertFalse(AbsenceTypeCatalog::isValid('sick')); // legacy alias is not a valid write value
		$this->assertFalse(AbsenceTypeCatalog::isValid('nonsense'));
		$this->assertFalse(AbsenceTypeCatalog::isValid(''));
	}

	public function testLabelsCoverAllTypes(): void
	{
		$labels = AbsenceTypeCatalog::labels($this->l10n());
		$this->assertSame(AbsenceTypeCatalog::ORDER, array_keys($labels));
		foreach ($labels as $type => $label) {
			$this->assertNotSame('', $label, "empty label for $type");
		}
	}

	public function testLabelFallbackIsRawType(): void
	{
		// Pinned legacy contract (SubstituteControllerTest): unknown/legacy
		// codes pass through unchanged so nothing silently collapses.
		$this->assertSame('exotic_type', AbsenceTypeCatalog::label($this->l10n(), 'exotic_type'));
		$this->assertSame('sick', AbsenceTypeCatalog::label($this->l10n(), 'sick'));
	}

	public function testLabelForUserResolvesLabelsAndFallsBackToRawType(): void
	{
		$labels = AbsenceTypeCatalog::labels($this->l10n());
		foreach (AbsenceTypeCatalog::all() as $type) {
			$this->assertSame(
				$labels[$type],
				AbsenceTypeCatalog::labelForUser($this->l10n(), $type),
				"labelForUser mismatch for $type",
			);
		}
		$this->assertSame('exotic_type', AbsenceTypeCatalog::labelForUser($this->l10n(), 'exotic_type'));
	}

	public function testMaxCalendarDays(): void
	{
		$this->assertSame(30, AbsenceTypeCatalog::maxCalendarDays(Absence::TYPE_OVERTIME_COMP));
		$this->assertSame(30, AbsenceTypeCatalog::maxCalendarDays(Absence::TYPE_VACATION));
		$this->assertSame(5, AbsenceTypeCatalog::maxCalendarDays(Absence::TYPE_PERSONAL_LEAVE));
		$this->assertSame(Constants::MAX_ABSENCE_DAYS, AbsenceTypeCatalog::maxCalendarDays(Absence::TYPE_CHILD_SICK));
		$this->assertSame(Constants::MAX_ABSENCE_DAYS, AbsenceTypeCatalog::maxCalendarDays('bogus'));
	}

	public function testSollEffectMatrixCoversEveryBuiltInType(): void
	{
		// Paid leave + sick family credit planned hours into Ist.
		foreach ([
			Absence::TYPE_VACATION,
			Absence::TYPE_SICK_LEAVE,
			Absence::TYPE_CHILD_SICK,
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
		] as $type) {
			$this->assertSame(
				AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST,
				AbsenceTypeCatalog::sollEffect($type),
				"$type must credit planned hours",
			);
		}

		// Contract suspensions remove the day's Soll — never paid hours.
		foreach ([
			Absence::TYPE_UNPAID_LEAVE,
			Absence::TYPE_PARENTAL_LEAVE,
		] as $type) {
			$this->assertSame(
				AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL,
				AbsenceTypeCatalog::sollEffect($type),
				"$type must reduce Soll",
			);
		}

		// Freizeitausgleich keeps its Soll gap (that gap IS the debit);
		// worked-away legacy types are filled by stamping, not credit.
		foreach ([
			Absence::TYPE_OVERTIME_COMP,
			Absence::TYPE_HOME_OFFICE,
			Absence::TYPE_BUSINESS_TRIP,
			'not_a_type',
		] as $type) {
			$this->assertSame(
				AbsenceTypeCatalog::SOLL_EFFECT_NONE,
				AbsenceTypeCatalog::sollEffect($type),
				"$type must not touch the balance",
			);
		}
	}

	public function testEveryBuiltInTypeHasAnExplicitSollEffect(): void
	{
		// The regression guard for the vacation-Minusstunden bug class: any
		// type added to ORDER without a matrix decision must throw here, not
		// silently degrade to NONE in production.
		foreach (AbsenceTypeCatalog::ORDER as $type) {
			$effect = AbsenceTypeCatalog::sollEffect($type);
			$this->assertContains(
				$effect,
				[
					AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST,
					AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL,
					AbsenceTypeCatalog::SOLL_EFFECT_NONE,
				],
				"$type lacks a valid Soll effect",
			);
		}
		$this->assertSame(
			count(AbsenceTypeCatalog::ORDER),
			count(array_unique(array_map(
				static fn (string $t): string => $t,
				AbsenceTypeCatalog::ORDER,
			))),
			'ORDER itself must stay duplicate-free',
		);
	}

	public function testIsSollCreditableIncludesPaidLeaveButNeverSuspensions(): void
	{
		foreach ([
			Absence::TYPE_VACATION,
			Absence::TYPE_SICK_LEAVE,
			Absence::TYPE_CHILD_SICK,
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
		] as $type) {
			$this->assertTrue(AbsenceTypeCatalog::isSollCreditable($type), "$type creditable");
		}
		foreach ([
			Absence::TYPE_UNPAID_LEAVE,
			Absence::TYPE_PARENTAL_LEAVE,
			Absence::TYPE_OVERTIME_COMP,
			Absence::TYPE_HOME_OFFICE,
			Absence::TYPE_BUSINESS_TRIP,
		] as $type) {
			$this->assertFalse(AbsenceTypeCatalog::isSollCreditable($type), "$type not creditable");
		}
	}

	public function testStatsOtherTypesExcludeVacationAndSickOnly(): void
	{
		$others = AbsenceTypeCatalog::statsOtherTypes();
		$this->assertNotContains(Absence::TYPE_VACATION, $others);
		$this->assertNotContains(Absence::TYPE_SICK_LEAVE, $others);
		$this->assertContains(Absence::TYPE_CHILD_SICK, $others);
		$this->assertContains(Absence::TYPE_OVERTIME_COMP, $others);
		$this->assertSame(count(AbsenceTypeCatalog::all()) - 2, count($others));
	}
}
