<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\Dashboard\WidgetStatusCopy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class WidgetStatusCopyTest extends TestCase {
	private WidgetStatusCopy $copy;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $s, array $p = []): string => $p === [] ? $s : (string)vsprintf($s, $p)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => $count === 1 ? $singular : str_replace('%n', (string)$count, $plural)
		);
		$this->copy = new WidgetStatusCopy($l10n);
	}

	public function testPersonSubtitleOmitsRedundantLabels(): void {
		$subtitle = $this->copy->personSubtitle('clocked_out', 0.0);
		$this->assertSame('Clocked Out · 0 h', $subtitle);
		$this->assertStringNotContainsString('Status:', $subtitle);
		$this->assertStringNotContainsString('Today:', $subtitle);
	}

	public function testWorkingHeadlineIsScannable(): void {
		$this->assertSame('12 of 48 working', $this->copy->workingHeadline(12, 48));
	}

	public function testSummarySubtitleHidesZeroNoise(): void {
		$subtitle = $this->copy->summarySubtitle(
			['break' => 0, 'paused' => 0, 'clocked_out' => 500],
			['total_absent' => 0, 'vacation' => 0, 'sick' => 0, 'other_absent' => 0]
		);
		$this->assertSame('500 out', $subtitle);
		$this->assertStringNotContainsString('Total:', $subtitle);
		$this->assertStringNotContainsString('Working:', $subtitle);
		$this->assertStringNotContainsString('Vacation:', $subtitle);
	}

	public function testSummarySubtitleIncludesAbsenceDetailWhenPresent(): void {
		$subtitle = $this->copy->summarySubtitle(
			['break' => 1, 'paused' => 0, 'clocked_out' => 2],
			['total_absent' => 3, 'vacation' => 2, 'sick' => 1, 'other_absent' => 0]
		);
		$this->assertSame('1 on break · 2 out · 3 away (2 vacation, 1 sick)', $subtitle);
	}

	public function testSummarySubtitleWhenEveryoneIdle(): void {
		$this->assertSame(
			'Nobody clocked in yet',
			$this->copy->summarySubtitle(
				['break' => 0, 'paused' => 0, 'clocked_out' => 0],
				['total_absent' => 0]
			)
		);
	}

	public function testTruncationNoteIsShortAndActionable(): void {
		$note = $this->copy->truncationNote(500, 2628);
		$this->assertSame('Showing counts for the first 500 of 2628 people.', $note);
		$this->assertStringNotContainsString('directory', strtolower($note));
		$this->assertStringNotContainsString('Open Employees', $note);
	}

	public function testSortPeopleByStatusPutsWorkingFirst(): void {
		$sorted = $this->copy->sortPeopleByStatus([
			['userId' => 'z', 'displayName' => 'Zed', 'status' => 'clocked_out'],
			['userId' => 'a', 'displayName' => 'Ann', 'status' => 'active'],
			['userId' => 'b', 'displayName' => 'Bea', 'status' => 'break'],
		]);
		$this->assertSame(['a', 'b', 'z'], array_column($sorted, 'userId'));
	}

	public function testFormatHoursDropsUselessDecimals(): void {
		$this->assertSame('0', $this->copy->formatHours(0.0));
		$this->assertSame('3.5', $this->copy->formatHours(3.5));
		$this->assertSame('3.25', $this->copy->formatHours(3.25));
	}
}
