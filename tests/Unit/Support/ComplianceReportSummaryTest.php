<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\ComplianceReportSummary;
use PHPUnit\Framework\TestCase;

final class ComplianceReportSummaryTest extends TestCase {
	public function testEmptyAndNonRowsStayAtZero(): void {
		$report = ComplianceReportSummary::fromRows([]);
		self::assertSame(0, $report['total_violations']);
		self::assertSame(0, $report['unresolved']);
		self::assertSame([], $report['by_type']);
		self::assertSame([], $report['by_severity']);

		$report = ComplianceReportSummary::fromRows([null, 'nope', 3]);
		self::assertSame(0, $report['total_violations']);
		self::assertSame(0, $report['unresolved']);
	}

	public function testCountsOpenAndClosedProblemsSeparately(): void {
		$report = ComplianceReportSummary::fromRows([
			['type' => 'sunday_work', 'severity' => 'warning', 'resolved' => false],
			['type' => 'missing_break', 'severity' => 'error', 'resolved' => true],
			['type' => 'missing_break', 'severity' => 'error', 'resolved' => 1],
			['type' => 'night_work', 'severity' => 'info', 'resolved' => 0],
			['type' => '', 'severity' => '', 'resolved' => false],
			['severity' => 'warning'],
		]);

		self::assertSame(6, $report['total_violations']);
		self::assertSame(4, $report['unresolved']);
		self::assertSame([
			'missing_break' => 2,
			'night_work' => 1,
			'sunday_work' => 1,
		], $report['by_type']);
		self::assertSame([
			'error' => 2,
			'info' => 1,
			'warning' => 2,
		], $report['by_severity']);
	}
}
