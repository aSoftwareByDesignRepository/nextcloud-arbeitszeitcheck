<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Reports export chooser: radiogroup of single-action options (no nested buttons).
 */
class ReportsExportChooserLayoutTest extends TestCase
{
	public function testExportChooserUsesRadiogroupButtonsWithStableHooks(): void
	{
		$template = (string)file_get_contents(__DIR__ . '/../../../templates/reports.php');

		$this->assertStringContainsString('role="radiogroup"', $template);
		$this->assertStringContainsString('role="radio"', $template);
		$this->assertStringContainsString('class="report-type-card btn-select-report"', $template);
		$this->assertStringContainsString('data-report="monthly"', $template);
		$this->assertStringContainsString('data-report="absence"', $template);
		$this->assertStringContainsString('data-report="compliance"', $template);
		$this->assertStringContainsString('premiumSurchargesEnabled', $template);
		$this->assertStringContainsString('data-report="premium"', $template);
		$this->assertStringContainsString('IconCatalog::render', $template);
		$this->assertStringContainsString('reports-section__title', $template);
		$this->assertStringContainsString('aria-labelledby="report-type-heading"', $template);

		/* Old nested button + letter abbreviation pattern must stay gone. */
		$this->assertStringNotContainsString('report-type-icon__abbr', $template);
		$this->assertStringNotContainsString("role=\"listitem\"", $template);
		$this->assertDoesNotMatchRegularExpression(
			'/<article[^>]*class="[^"]*report-type-card/',
			$template
		);
	}

	public function testExportChooserCssIsFullWidthOptionLayout(): void
	{
		$css = (string)file_get_contents(__DIR__ . '/../../../css/reports.css');
		$this->assertStringContainsString('.report-type-card', $css);
		$this->assertStringContainsString('color-primary-element-text', $css);
		$this->assertStringContainsString('[aria-checked=\'true\']', $css);
		$this->assertStringNotContainsString('report-type-icon__abbr', $css);
		$this->assertStringNotContainsString('report-type-card__icon--compliance', $css);
		$this->assertStringNotContainsString('min-height: 14rem', $css);
	}
}
