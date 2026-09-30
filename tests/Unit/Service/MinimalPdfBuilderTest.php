<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\MinimalPdfBuilder;
use PHPUnit\Framework\TestCase;

class MinimalPdfBuilderTest extends TestCase
{
	public function testEscapePdfTextPreservesGermanUmlauts(): void
	{
		$s = 'Kurzübersicht Überstunden Verstöße Größe';
		$esc = MinimalPdfBuilder::escapePdfText($s);
		$back = @iconv('Windows-1252', 'UTF-8', $esc);
		$this->assertIsString($back);
		$this->assertSame($s, $back);
	}

	public function testEscapePdfTextEscapesParenthesesAndBackslash(): void
	{
		$esc = MinimalPdfBuilder::escapePdfText('a(b)\\c');
		$this->assertStringContainsString('\\(', $esc);
		$this->assertStringContainsString('\\)', $esc);
		$this->assertStringContainsString('\\\\', $esc);
	}

	public function testEscapePdfTextReplacesUnmappableWithQuestionMark(): void
	{
		$esc = MinimalPdfBuilder::escapePdfText("ASCII \u{1F600}");
		$this->assertStringContainsString('?', $esc);
	}

	public function testBuildPdfDeclaresWinAnsiEncodingForHelvetica(): void
	{
		$pdf = MinimalPdfBuilder::build('Titel', ['Zeile äöüß']);
		$this->assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
	}

	public function testCodepointFallbackMapsCp1252Extensions(): void
	{
		// last-resort mapper used when mbstring+iconv are unavailable
		$m = new \ReflectionMethod(MinimalPdfBuilder::class, 'utf8ToWindows1252ByCodepoint');
		$m->setAccessible(true);

		$this->assertSame("\x80", $m->invoke(null, "\u{20AC}"));           // Euro
		$this->assertSame("\x91\x92", $m->invoke(null, "\u{2018}\u{2019}")); // quotes
		$this->assertSame("\x99", $m->invoke(null, "\u{2122}"));           // TM
		$this->assertSame("\x9F", $m->invoke(null, "\u{0178}"));           // Ydiaeresis
		$this->assertSame('?', $m->invoke(null, "\u{4E2D}"));               // unmappable CJK
		$this->assertSame('?', $m->invoke(null, "\u{0007}"));               // control char
		$this->assertSame('a', $m->invoke(null, 'a'));                       // ASCII passthrough
		$this->assertSame("\xE4", $m->invoke(null, "\u{00E4}"));           // umlaut via Latin-1 range
	}
}