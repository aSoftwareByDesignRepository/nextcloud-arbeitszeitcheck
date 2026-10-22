<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Recent entries stay one card. The table does not draw a second frame,
 * and a table placed in a card body follows the same rule.
 */
class DashboardRecentTableContractTest extends TestCase
{
	public function testRecentEntriesKeepTheirColumnsInsideOneCard(): void
	{
		$dashboard = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/dashboard.php');
		$start = strpos($dashboard, 'azc-card azc-dashboard-recent');
		$this->assertNotFalse($start);
		$block = substr($dashboard, $start, 2500);

		$this->assertSame(1, substr_count($block, 'azc-card azc-dashboard-recent'));
		$this->assertStringContainsString('azc-table-wrap azc-dashboard-recent__table-wrap', $block);
		foreach (['Date', 'Start', 'End', 'Duration', 'Break', 'Status', 'Actions'] as $column) {
			$this->assertStringContainsString("\$l->t('$column')", $block);
		}
		$this->assertStringContainsString("\$l->t('Recent Entries')", $block);
		$this->assertStringContainsString("\$l->t('View All')", $block);
	}

	public function testRecentTableDoesNotDrawItsOwnFrame(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/dashboard.css');
		$wrap = $this->ruleBody($css, '.azc-dashboard-recent__table-wrap {');

		$this->assertStringContainsString('border: 0;', $wrap);
		$this->assertStringNotContainsString('border: 1px', $wrap);
		$this->assertStringContainsString('border-radius: 0;', $wrap);

		$narrow = $this->ruleBody($css, '.azc-dashboard-recent__table.azc-table--responsive tbody tr {');
		$this->assertStringNotContainsString('border-radius: var(', $narrow);
		$this->assertStringContainsString('border-bottom: 1px solid', $narrow);
	}

	public function testTablesInsideCardsAreNotASecondBox(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/page-patterns.css');
		$flush = $this->ruleBody($css, '.azc-card__body > .azc-table-wrap {');

		$this->assertStringContainsString('border: 0;', $flush);
		$this->assertStringContainsString('background: transparent;', $flush);
		$this->assertStringContainsString('box-shadow: none;', $flush);
		$this->assertStringContainsString('#app-content.azc-app .azc-card .azc-table-wrap', $css);
		$this->assertStringContainsString('#app-content.azc-app .section .table-container', $css);
		$this->assertStringContainsString('.azc-dialog .table-container', $css);
		$this->assertStringContainsString('.azc-card__body:has(> .azc-table-wrap:only-child)', $css);

		$narrow = $this->ruleBody($css, '#app-content.azc-app .table.azc-table--responsive tbody tr,');
		$this->assertStringContainsString('border-bottom: 1px solid', $narrow);
		$this->assertStringNotContainsString('border-radius: var(', $narrow);
	}

	private function ruleBody(string $css, string $needle): string
	{
		$at = strpos($css, $needle);
		$this->assertNotFalse($at, 'missing rule ' . $needle);
		$open = strpos($css, '{', $at);
		$close = strpos($css, '}', $open);
		$this->assertNotFalse($open);
		$this->assertNotFalse($close);

		return substr($css, $open, $close - $open);
	}
}
