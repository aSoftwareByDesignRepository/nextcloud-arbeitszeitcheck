<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Wide tables must not keep a sticky actions column that covers the row
 * and forces horizontal scrolling. Buttons stay in the tab order.
 */
class RowActionsRevealContractTest extends TestCase
{
	public function testDesktopActionsColumnCollapsesAndFloatsOnTheRow(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/page-patterns.css');
		$marker = strpos($css, 'Wide tables: the actions column');
		$this->assertNotFalse($marker);
		$layout = $this->mediaBody($css, '@media (min-width: 769px) {', $marker);

		$this->assertStringContainsString(':is(#app-content.azc-app, .azc-dialog)', $layout);
		$this->assertStringContainsString(':is(.table, .azc-table)', $layout);
		$this->assertStringContainsString('th.azc-table-actions-col', $layout);
		$this->assertMatchesRegularExpression('/th\.azc-table-actions-col,\s*[^\{]*\{\s*width:\s*0;/s', $layout);
		$this->assertStringContainsString('td.actions-cell', $layout);
		$this->assertStringContainsString('position: sticky;', $layout);
		$this->assertStringContainsString('inset-inline-end: 0;', $layout);
		$this->assertStringContainsString('width: 0;', $layout);
		$this->assertStringContainsString('background: transparent;', $layout);
		$this->assertStringContainsString('position: absolute;', $layout);
		$this->assertStringContainsString('top: 0;', $layout);
		$this->assertStringContainsString('bottom: 0;', $layout);
		$this->assertStringContainsString('flex-direction: row;', $layout);
		$this->assertStringContainsString('flex-wrap: nowrap;', $layout);
		$this->assertStringContainsString('overflow: hidden;', $layout);
		$this->assertStringNotContainsString('flex-wrap: wrap', $layout);
		$this->assertStringNotContainsString('translateY', $layout);
		$this->assertDoesNotMatchRegularExpression('/display:\s*none/', $layout);
	}

	public function testHoverRevealKeepsKeyboardFocusAndEscapeDismiss(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/page-patterns.css');
		$hover = $this->mediaBody($css, '@media (min-width: 769px) and (hover: hover)');

		$this->assertStringContainsString('opacity: 0;', $hover);
		$this->assertStringContainsString('pointer-events: none;', $hover);
		$this->assertStringContainsString('tr:hover td.actions-cell .azc-table-actions,', $hover);
		$this->assertStringContainsString('tr:focus-within td.actions-cell .azc-table-actions,', $hover);
		$this->assertMatchesRegularExpression('/tr:focus-within td\.actions-cell \.azc-table-actions\s*\{/', $hover);
		$this->assertStringContainsString('background-color: var(--color-background-hover, var(--azc-bg-card, var(--color-main-background)));', $hover);
		$this->assertStringContainsString('tr.azc-row-actions-dismissed td.actions-cell .azc-table-actions,', $hover);
		$this->assertStringContainsString('.azc-dialog', $hover);
		$this->assertDoesNotMatchRegularExpression('/display:\s*none/', $hover);
		$this->assertStringNotContainsString('visibility: hidden', $hover);
	}

	public function testEveryActionsCellUsesTheSharedGroup(): void
	{
		$root = dirname(__DIR__, 3);
		$missing = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if (!preg_match('/\.(js|php)$/', $path)) {
				continue;
			}
			if (str_contains($path, '/tests/') || str_contains($path, '/vendor/') || str_contains($path, '.test.')) {
				continue;
			}
			$text = (string)file_get_contents($path);
			if (!str_contains($text, 'actions-cell')) {
				continue;
			}
			if (!str_contains($text, 'azc-table-actions')) {
				$missing[] = substr($path, strlen($root) + 1);
			}
		}
		$this->assertSame([], $missing, 'actions cells must use .azc-table-actions so the hover row applies');
	}

	public function testTimeEntriesDoesNotPinAnOpaqueActionsColumn(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/time-entries.css');

		$this->assertStringNotContainsString('position: sticky', $css);
		$this->assertStringNotContainsString('box-shadow: -6px 0 8px', $css);
	}

	public function testEscapeDismissDoesNotStealModalOrDialogEscape(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/components.js');

		$this->assertStringContainsString('initRowActionReveal()', $js);
		$this->assertStringContainsString("event.key !== 'Escape' || event.defaultPrevented", $js);
		$this->assertStringContainsString("document.querySelector('dialog[open]')", $js);
		$this->assertStringContainsString('_getVisibleModalBackdrops().length > 0', $js);
		$this->assertStringContainsString("row.classList.contains('azc-row-actions-dismissed')", $js);
		$this->assertStringContainsString("!row.querySelector('.azc-table-actions')", $js);
		$this->assertStringContainsString("row.classList.add('azc-row-actions-dismissed')", $js);
		$this->assertStringContainsString("row.setAttribute('tabindex', '-1')", $js);
		$this->assertStringContainsString("addEventListener('pointerover'", $js);
		$this->assertStringContainsString("addEventListener('focusin'", $js);
		$this->assertStringContainsString("!target.closest('.azc-table-actions')", $js);
		$this->assertStringContainsString("row.classList.remove('azc-row-actions-dismissed')", $js);
	}

	private function mediaBody(string $css, string $needle, int $from = 0): string
	{
		$start = strpos($css, $needle, $from);
		$this->assertNotFalse($start, 'missing ' . $needle);
		$open = strpos($css, '{', $start);
		$this->assertNotFalse($open);
		$depth = 0;
		$length = strlen($css);
		for ($i = $open; $i < $length; $i++) {
			if ($css[$i] === '{') {
				$depth++;
			} elseif ($css[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr($css, $start, $i - $start + 1);
				}
			}
		}
		$this->fail('unclosed media query: ' . $needle);
	}
}
