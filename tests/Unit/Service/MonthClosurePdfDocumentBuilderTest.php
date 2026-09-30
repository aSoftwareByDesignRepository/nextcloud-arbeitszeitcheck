<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCA\ArbeitszeitCheck\Service\MonthClosurePdfDocumentBuilder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class MonthClosurePdfDocumentBuilderTest extends TestCase
{
	public function testPdfContainsMetadataNoEmbeddedJsonDump(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$l->method('t')->willReturnCallback(static function (string $text, array $parameters = []) {
			if ($text === 'month_closure_pdf_title') {
				return 'TITLE ' . ($parameters[0] ?? '');
			}
			if ($text === 'month_closure_pdf_holidays_detail') {
				return 'HOL ' . ($parameters[0] ?? '') . ' ' . ($parameters[1] ?? '');
			}
			if ($text === 'month_closure_pdf_footer') {
				return 'P' . ($parameters[0] ?? '') . '/' . ($parameters[1] ?? '') . ' ' . ($parameters[2] ?? '') . ' ' . ($parameters[3] ?? '');
			}
			if ($text === 'month_closure_pdf_integrity_note') {
				return 'INTEGRITY NOTE BODY';
			}

			return 'L';
		});

		$snap = [
			'schema' => 'arbeitszeitcheck.month_closure.v1',
			'year' => 2026,
			'month' => 3,
			'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
			'report' => [
				'total_hours' => 1.0,
				'total_break_hours' => 0.0,
				'working_days' => 1,
				'total_overtime' => 0.0,
				'violations_count' => 0,
				'holiday_summary' => ['holiday_days' => 0, 'holiday_work_hours' => 0.0],
			],
			'time_entries' => [],
			'absences' => [],
		];
		$row = new MonthClosure();
		$row->setSnapshotHash(str_repeat('b', 64));
		$row->setPrevSnapshotHash(null);
		$row->setVersion(1);
		$row->setFinalizedAt(new \DateTime('2026-04-01 10:00:00', new \DateTimeZone('UTC')));
		$row->setFinalizedBy('testuser');

		$pdf = MonthClosurePdfDocumentBuilder::build($snap, $row, 'Test User', 'user1', $l, 'Test User (testuser)');

		$this->assertStringStartsWith("%PDF-1.4\n", $pdf);
		$this->assertStringContainsString('TITLE 2026-03', $pdf);
		$this->assertStringContainsString('2026-04-01T10:00:00Z', $pdf);
		$this->assertStringContainsString('bbbbbbbbbbbbbbbb', $pdf);
		$this->assertStringContainsString('/Lang (en-US)', $pdf);
		$this->assertStringContainsString('INTEGRITY NOTE BODY', $pdf);
		$this->assertStringNotContainsString('/BaseFont /Courier', $pdf);
		$this->assertStringNotContainsString('"time_entries"', $pdf);
		$this->assertStringNotContainsString('"schema"', $pdf);
		$this->assertMatchesRegularExpression('/P1\/\d+/', $pdf);
	}

	public function testPdfIncludesOvertimeBankSectionWhenPresent(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$l->method('t')->willReturnCallback(static function (string $text) {
			if ($text === 'month_closure_pdf_section_overtime_bank') {
				return 'OT BANK SECTION';
			}
			if ($text === 'month_closure_pdf_title') {
				return 'TITLE';
			}
			if ($text === 'month_closure_pdf_footer') {
				return 'FOOTER';
			}
			if ($text === 'month_closure_pdf_integrity_note') {
				return 'INTEGRITY';
			}

			return 'X';
		});

		$snap = [
			'schema' => 'arbeitszeitcheck.month_closure.v1',
			'year' => 2026,
			'month' => 3,
			'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
			'report' => ['total_hours' => 1.0, 'violations_count' => 0],
			'overtime_bank' => [
				'enabled' => true,
				'bank_max_hours' => 100.0,
				'raw_balance_eom' => 110.0,
				'effective_balance_eom' => 110.0,
				'payout_eligible_eom' => 10.0,
				'banked_hours_eom' => 100.0,
				'payout_record' => null,
			],
			'time_entries' => [],
			'absences' => [],
		];
		$row = new MonthClosure();
		$row->setSnapshotHash(str_repeat('a', 64));
		$row->setVersion(1);

		$pdf = MonthClosurePdfDocumentBuilder::build($snap, $row, 'User', 'u1', $l, '');

		$this->assertStringContainsString('OT BANK SECTION', $pdf);
	}

	public function testPdfIncludesPremiumSectionFromFrozenSnapshot(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$l->method('t')->willReturnCallback(static function (string $text) {
			return match ($text) {
				'month_closure_pdf_section_premium' => 'PREMIUM SECTION',
				'month_closure_pdf_title' => 'TITLE',
				'month_closure_pdf_footer' => 'FOOTER',
				'month_closure_pdf_integrity_note' => 'INTEGRITY',
				'month_closure_pdf_col_premium_category' => 'CAT',
				'month_closure_pdf_col_premium_hours' => 'HRS',
				'month_closure_pdf_col_premium_rate' => 'RATE',
				'month_closure_pdf_col_premium_valued' => 'VAL',
				'month_closure_pdf_premium_orthogonal_note' => 'ORTHO',
				default => 'X',
			};
		});

		$snap = [
			'schema' => 'arbeitszeitcheck.month_closure.v1',
			'year' => 2026,
			'month' => 3,
			'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
			'report' => ['total_hours' => 1.0, 'violations_count' => 0],
			'premium' => [
				'enabled' => true,
				'policy_version' => 7,
				'summary' => [
					'total_classified_hours' => 5.5,
					'total_valued_hours' => 8.25,
					'buckets' => [
						[
							'id' => 'sunday',
							'label' => 'SundayPremium',
							'hours' => 5.5,
							'rate' => 1.0,
							'valued_hours' => 5.5,
						],
					],
				],
			],
			'time_entries' => [],
			'absences' => [],
		];
		$row = new MonthClosure();
		$row->setSnapshotHash(str_repeat('b', 64));
		$row->setVersion(1);

		$pdf = MonthClosurePdfDocumentBuilder::build($snap, $row, 'User', 'u1', $l, '');

		$this->assertStringContainsString('PREMIUM SECTION', $pdf);
		$this->assertStringContainsString('SundayPremium', $pdf);
		$this->assertStringContainsString('ORTHO', $pdf);
	}

	public function testPdfOmitsPremiumSectionWhenDisabledInSnapshot(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$l->method('t')->willReturnCallback(static function (string $text) {
			if ($text === 'month_closure_pdf_section_premium') {
				return 'PREMIUM SECTION';
			}
			if ($text === 'month_closure_pdf_title') {
				return 'TITLE';
			}
			if ($text === 'month_closure_pdf_footer') {
				return 'FOOTER';
			}
			if ($text === 'month_closure_pdf_integrity_note') {
				return 'INTEGRITY';
			}

			return 'X';
		});

		$snap = [
			'schema' => 'arbeitszeitcheck.month_closure.v1',
			'year' => 2026,
			'month' => 3,
			'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
			'report' => ['total_hours' => 1.0, 'violations_count' => 0],
			'premium' => ['enabled' => false, 'summary' => ['buckets' => []]],
			'time_entries' => [],
			'absences' => [],
		];
		$row = new MonthClosure();
		$row->setSnapshotHash(str_repeat('c', 64));
		$row->setVersion(1);

		$pdf = MonthClosurePdfDocumentBuilder::build($snap, $row, 'User', 'u1', $l, '');
		$this->assertStringNotContainsString('PREMIUM SECTION', $pdf);
	}

	/**
	 * Feeds the renderer every label/branch arm: all entry statuses, both
	 * manual/auto kinds, break payloads (array, JSON string, malformed),
	 * invalid timestamps, and enough rows to force a continuation page.
	 */
	public function testPdfRendersAllEntryAndAbsenceLabelBranches(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('de');
		$l->method('t')->willReturnCallback(static function (string $text, array $parameters = []) {
			return 'T[' . $text . ']';
		});

		$statuses = ['completed', 'active', 'break', 'paused', 'pending_approval', 'rejected', '', 'weird_status'];
		$entries = [];
		foreach ($statuses as $i => $st) {
			$entries[] = [
				'start' => sprintf('2026-03-%02dT08:00:00+01:00', $i + 1),
				'end' => sprintf('2026-03-%02dT16:30:00+01:00', $i + 1),
				'status' => $st,
				'is_manual' => $i % 2 === 0,
				'breaks' => [['start' => '2026-03-01T12:00:00+01:00', 'end' => '2026-03-01T12:30:00+01:00'], 'not-an-array'],
				'description' => str_repeat('LongWord ', 40) . ' ' . str_repeat('X', 300),
			];
		}
		// break payload variants: JSON string, invalid JSON, empty
		$entries[] = [
			'start' => '2026-03-10T08:00:00+01:00',
			'end' => '2026-03-10T16:00:00+01:00',
			'status' => 'completed',
			'is_manual' => false,
			'breaks' => '[{"start":"2026-03-10T12:00:00+01:00","end":"2026-03-10T12:45:00+01:00"}]',
			'description' => '',
		];
		$entries[] = [
			'start' => 'not-a-date',
			'end' => '',
			'status' => 'completed',
			'is_manual' => false,
			'breaks' => '{invalid json',
			'description' => '',
		];
		// non-array entries are skipped by the loop
		$entries[] = 'garbage-row';
		// enough filler rows to force a continuation page (newPage(false))
		for ($i = 0; $i < 60; $i++) {
			$entries[] = [
				'start' => '2026-03-15T08:00:00+01:00',
				'end' => '2026-03-15T16:00:00+01:00',
				'status' => 'completed',
				'is_manual' => false,
				'breaks' => null,
				'description' => 'row ' . $i,
			];
		}

		$absences = [];
		foreach (['vacation', 'sick_leave', 'personal_leave', 'parental_leave', 'special_leave', 'unpaid_leave', 'home_office', 'business_trip', 'exotic'] as $type) {
			foreach (['pending', 'substitute_pending', 'substitute_declined', 'approved', 'rejected', 'cancelled', 'unknown_status'] as $st) {
				$absences[] = [
					'type' => $type,
					'start_date' => '2026-03-05',
					'end_date' => '2026-03-06',
					'days' => 2.0,
					'status' => $st,
				];
			}
		}
		$absences[] = 'garbage-row';

		$snap = [
			'schema' => 'arbeitszeitcheck.month_closure.v1',
			'year' => 2026,
			'month' => 3,
			'period' => ['start' => '2026-03-01', 'end' => '2026-03-31'],
			'report' => ['total_hours' => 8.0, 'violations_count' => 0],
			'time_entries' => $entries,
			'absences' => $absences,
		];
		$row = new MonthClosure();
		$row->setSnapshotHash(str_repeat('d', 64));
		$row->setPrevSnapshotHash(str_repeat('e', 64));
		$row->setVersion(2);

		$pdf = MonthClosurePdfDocumentBuilder::build($snap, $row, 'User', 'u1', $l, 'Boss');

		$this->assertStringStartsWith('%PDF-1.4', $pdf);
		$this->assertStringContainsString('/Lang (de-DE)', $pdf);
		// multiple pages -> footer page counter references >1 page
		$this->assertMatchesRegularExpression('/\/Type \/Pages/', $pdf);
		// continuation header emitted on pages after the first
		$this->assertStringContainsString('T[month_closure_pdf_continuation]', $pdf);
		// localized labels ran through labelTimeEntryStatus ('Completed' etc.)
		$this->assertStringContainsString('T[Completed]', $pdf);
		$this->assertStringContainsString('T[Vacation]', $pdf);
		$this->assertStringContainsString('T[Substitute pending]', $pdf);
		$this->assertStringContainsString('T[Cancelled]', $pdf);
	}
}
