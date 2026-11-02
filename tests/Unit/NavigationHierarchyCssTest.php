<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: sidebar accordion hierarchy stays modest (no mid-word child column)
 * and interaction chrome does not shift indent (no hover translate / full-bleed slabs).
 */
final class NavigationHierarchyCssTest extends TestCase {
	private function css(): string {
		return (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
	}

	public function testChildNestIsModestAndBulletsDisabled(): void {
		$css = $this->css();
		self::assertStringContainsString('--azc-nav-child-nest: 0.5rem;', $css);
		self::assertStringNotContainsString('--azc-nav-child-nest: 1.25rem;', $css);
		self::assertStringContainsString('--azc-nav-rail-left:', $css);
		self::assertStringContainsString('.nav-submenu::before', $css);
		self::assertStringContainsString('.nav-submenu > li > a::before', $css);
		self::assertMatchesRegularExpression(
			'/\.nav-submenu > li > a::before \{\s*content:\s*none;/s',
			$css
		);
		self::assertStringContainsString('nav-parent-chevron', $css);
		self::assertStringContainsString(
			'.nav-parent-toggle[aria-expanded="true"] .nav-parent-chevron',
			$css
		);
		self::assertStringContainsString('transform: rotate(225deg);', $css);
		self::assertStringContainsString('cubic-bezier(0.22, 1, 0.36, 1)', $css);
	}

	public function testInteractionChromeIsInsetRoundedWithoutHoverShift(): void {
		$css = $this->css();
		self::assertStringContainsString('--azc-nav-item-inset:', $css);
		self::assertStringContainsString('--azc-nav-item-radius:', $css);
		self::assertStringNotContainsString('translateX(', $css);
		self::assertStringNotContainsString('border-radius: 0;', $css);
		self::assertStringContainsString('var(--color-primary-element-text)', $css);
		self::assertStringContainsString('var(--color-primary-element)', $css);
		// Open accordion must not reuse active-page chrome.
		self::assertMatchesRegularExpression(
			'/\.nav-item-has-children\.is-open\s*>\s*button\.nav-parent-toggle\s*\{[^}]*background-color:\s*transparent;/s',
			$css
		);
		// Active page uses solid primary fill (not inset bar + light bg).
		self::assertStringNotContainsString('inset 4px 0 0', $css);
		self::assertStringContainsString('color-primary-element-hover', $css);
		// Accordion buttons must fill the same inset row as the links.
		self::assertStringContainsString(
			'width: calc(100% - (2 * var(--azc-nav-item-inset, 0.5rem)));',
			$css
		);
		self::assertStringContainsString('margin-inline-start: auto;', $css);
		// Submenu hover/active surface clears the tree rail via ::after.
		self::assertStringContainsString('.nav-submenu > li > a::after', $css);
		self::assertStringContainsString('azc-nav-rail-left) - var(--azc-nav-item-inset)', $css);
	}

	public function testNavigationTemplateExposesChevronsOnAccordions(): void {
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		self::assertSame(2, substr_count($src, 'class="nav-parent-chevron"'));
		self::assertSame(2, substr_count($src, 'app-navigation-entry-button'));
		self::assertStringContainsString('aria-controls="admin-subnav"', $src);
		self::assertStringContainsString('aria-controls="manager-subnav"', $src);
	}
}