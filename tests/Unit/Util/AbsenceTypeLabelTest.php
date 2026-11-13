<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Util;

use OCA\ArbeitszeitCheck\Util\AbsenceTypeLabel;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class AbsenceTypeLabelTest extends TestCase
{
	public function testFormatWorkingDaysWholeNumberUsesPlural(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count): string {
			return $count === 1 ? '1 working day' : $count . ' working days';
		});

		$this->assertSame('3 working days', AbsenceTypeLabel::formatWorkingDays($l10n, 3.0));
		$this->assertSame('1 working day', AbsenceTypeLabel::formatWorkingDays($l10n, 1.0));
	}

	public function testFormatWorkingDaysHalfDayUsesDecimalLabel(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturn('unused');

		$this->assertSame('0.5 working days', AbsenceTypeLabel::formatWorkingDays($l10n, 0.5));
		$this->assertSame('0.25 working days', AbsenceTypeLabel::formatWorkingDays($l10n, 0.25));
		$this->assertSame('4.5 working days', AbsenceTypeLabel::formatWorkingDays($l10n, 4.5));
	}

	public function testFormatWorkingDaysZeroUsesExplicitLabel(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturn('no working days');
		$l10n->method('n')->willReturn('unused');

		$this->assertSame('no working days', AbsenceTypeLabel::formatWorkingDays($l10n, 0.0));
	}

	public function testGetReturnsLocalizedVacationLabel(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text === 'Vacation' ? 'Urlaub' : $text);

		$this->assertSame('Urlaub', AbsenceTypeLabel::get($l10n, 'vacation'));
	}
}
