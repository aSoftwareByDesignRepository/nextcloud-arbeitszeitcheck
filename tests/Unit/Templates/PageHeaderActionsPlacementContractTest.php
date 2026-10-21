<?php

declare(strict_types=1);

/**
 * Header controls are part of the first HTML byte.
 * A later script must not be what first places them.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class PageHeaderActionsPlacementContractTest extends TestCase
{
	public function testHeaderSlotPrintsCapturedActionsAndIsNotALiveRegion(): void
	{
		$shell = $this->src('templates/common/page-start.php');
		$slot = strpos($shell, '<div id="azc-page-actions" class="azc-page-header__actions">');
		$this->assertNotFalse($slot);
		$slotEnd = strpos($shell, '</div>', $slot);
		$this->assertNotFalse($slotEnd);
		$markup = substr($shell, $slot, $slotEnd - $slot);
		$this->assertStringNotContainsString('aria-live', $markup);
		$this->assertStringContainsString('is_string($pageHeaderActions)', $markup);
		$this->assertStringContainsString('print_unescaped($pageHeaderActions)', $markup);
	}

	public function testPagesCaptureActionsBeforeTheShellInclude(): void
	{
		foreach ([
			'templates/absences.php' => 'btn-request-absence',
			'templates/time-entries.php' => 'btn-add-entry',
			'templates/calendar.php' => 'btn-today',
			'templates/timeline.php' => 'btn-refresh-timeline',
		] as $path => $buttonId) {
			$src = $this->src($path);
			$capture = strpos($src, '$pageHeaderActions');
			$include = strpos($src, "include __DIR__ . '/common/page-start.php'");
			$button = strpos($src, 'id="' . $buttonId . '"');
			$this->assertNotFalse($capture, $path);
			$this->assertNotFalse($include, $path);
			$this->assertNotFalse($button, $path);
			$this->assertLessThan($include, $capture, $path . ' must capture actions before the shell');
			$this->assertLessThan($include, $button, $path . ' must render the control into the capture');
			$this->assertStringContainsString("preg_replace('/>\\s+</', '><', \$captured)", $src);
			$this->assertStringNotContainsString('azc-page-actions-source', $src);
			$this->assertStringNotContainsString('class="header-actions', $src);
		}
	}

	public function testActionColumnKeepsItsWidthAndAnEmptySlotTakesNoSpace(): void
	{
		$css = $this->src('css/app.css');
		$this->assertStringContainsString('grid-template-columns: 56px minmax(0, 1fr) auto;', $css);
		$this->assertStringContainsString('grid-template-columns: 48px minmax(0, 1fr);', $css);
		$this->assertMatchesRegularExpression(
			'/\.azc-page-header__actions:empty\s*\{\s*display:\s*none;\s*\}/',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.azc-page-header__actions > \.view-toggle\s*\{[^}]*flex:\s*0 0 auto;[^}]*white-space:\s*nowrap;/s',
			$css
		);
	}

	private function src(string $relative): string
	{
		$path = dirname(__DIR__, 3) . '/' . $relative;
		$this->assertFileExists($path);
		$src = file_get_contents($path);
		$this->assertIsString($src);

		return $src;
	}
}
