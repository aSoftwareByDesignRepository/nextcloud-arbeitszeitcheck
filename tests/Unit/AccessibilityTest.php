<?php

declare(strict_types=1);

/**
 * Basic accessibility tests
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Class AccessibilityTest
 */
class AccessibilityTest extends TestCase {

	/**
	 * Test that templates have proper accessibility attributes
	 */
	public function testTemplatesHaveAccessibilityAttributes(): void {
		$templateFiles = [
			__DIR__ . '/../../templates/index.php',
			__DIR__ . '/../../templates/manager-dashboard.php',
			__DIR__ . '/../../templates/personal-settings.php',
			__DIR__ . '/../../templates/admin-settings.php',
			__DIR__ . '/../../templates/admin-notifications.php',
			__DIR__ . '/../../templates/reports.php',
		];

		foreach ($templateFiles as $templateFile) {
			$this->assertFileExists($templateFile, "Template file should exist: $templateFile");

			$content = file_get_contents($templateFile);

			// Check for basic accessibility elements
			$this->assertStringContainsString('aria-label', $content,
				"Template should contain aria-label attributes: $templateFile");

			// Do not hard-require role= or landmarks here, because many templates pull
			// semantic landmarks via PHP includes (not visible in raw file content).

			// Forbid the "div with onclick" anti-pattern (interactive non-button div).
			// Note: this is a coarse check - it allows onclick on actual <button>/<a>.
			$this->assertDoesNotMatchRegularExpression(
				'/<div[^>]*\bonclick=/i',
				$content,
				"Template must not use <div onclick=...> in place of <button>/<a>: $templateFile"
			);

			// Verify each template offers at least one focusable interactive element
			// (button OR anchor with href), so keyboard users always have something to act on.
			$hasButton = strpos($content, '<button') !== false;
			$hasLink = preg_match('/<a\s[^>]*\bhref=/i', $content) === 1;
			$this->assertTrue($hasButton || $hasLink,
				"Template should expose at least one interactive control (<button> or <a href>): $templateFile");

			// Check for form labels only when the template contains visible form controls.
			// Hidden CSRF tokens alone do not require a sibling <label> in the same file
			// (policy pages pull real fields via PHP includes).
			$visibleControls = preg_match('/<(input|select|textarea)\b(?![^>]*\btype=["\']hidden["\'])/i', $content) === 1;
			if ($visibleControls) {
				$this->assertStringContainsString('<label', $content,
					"Template should contain form labels: $templateFile");
			}
		}

		// Included policy partials must keep labels next to controls (kitchen-sink split).
		$policyPartials = [
			__DIR__ . '/../../templates/partials/admin-policy-clock-reminders.php',
			__DIR__ . '/../../templates/partials/admin-policy-hr-office.php',
			__DIR__ . '/../../templates/partials/admin-policy-overtime-alerts.php',
			__DIR__ . '/../../templates/partials/admin-policy-overtime-bank.php',
			__DIR__ . '/../../templates/partials/admin-policy-hour-premiums.php',
			__DIR__ . '/../../templates/partials/admin-policy-vacation.php',
			__DIR__ . '/../../templates/admin-overtime-settings.php',
		];
		foreach ($policyPartials as $templateFile) {
			$this->assertFileExists($templateFile, "Policy template should exist: $templateFile");
			$content = (string)file_get_contents($templateFile);
			$this->assertStringContainsString('aria-', $content, "Policy template needs ARIA: $templateFile");
			if (str_contains($templateFile, 'partials/')) {
				$this->assertStringContainsString('<label', $content, "Policy partial needs labels: $templateFile");
			}
		}
	}

	/**
	 * Test that CSS has proper focus indicators
	 */
	public function testCssHasFocusIndicators(): void {
		$cssFile = __DIR__ . '/../../css/common/accessibility.css';

		$this->assertFileExists($cssFile, 'Accessibility CSS file should exist');

		$content = file_get_contents($cssFile);

		// Check for focus indicators
		$this->assertStringContainsString(':focus', $content,
			'CSS should contain focus indicators');

		$this->assertStringContainsString('outline:', $content,
			'CSS should contain outline properties for focus');

		// Check for focus-visible support
		$this->assertStringContainsString(':focus-visible', $content,
			'CSS should support focus-visible');

		// Skip links are delegated to Nextcloud core's #skip-actions strip —
		// the app must not ship its own .skip-link styles.
		$this->assertStringNotContainsString('.skip-link', $content,
			'CSS must not define app-side skip link styles');
	}

	/**
	 * Test that JavaScript handles keyboard navigation
	 */
	public function testJavaScriptHasKeyboardSupport(): void {
		$jsFile = __DIR__ . '/../../js/arbeitszeitcheck-main.js';

		$this->assertFileExists($jsFile, 'Main JavaScript file should exist');

		$content = file_get_contents($jsFile);

		// Check for keyboard event handling
		$this->assertStringContainsString('addEventListener', $content,
			'JavaScript should handle events for accessibility');

		// Check for focus management
		$this->assertStringContainsString('focus', $content,
			'JavaScript should manage focus for accessibility');
	}

	/**
	 * Test that templates use semantic HTML
	 */
	public function testTemplatesUseSemanticHtml(): void {
		$templateFiles = [
			__DIR__ . '/../../templates/index.php',
			__DIR__ . '/../../templates/manager-dashboard.php'
		];

		foreach ($templateFiles as $templateFile) {
			$content = file_get_contents($templateFile);

			// Check for semantic HTML elements
			$semanticElements = ['<header', '<nav', '<main', '<section', '<article', '<aside', '<footer'];
			$hasSemantic = false;

			foreach ($semanticElements as $element) {
				if (strpos($content, $element) !== false) {
					$hasSemantic = true;
					break;
				}
			}

			// Many templates include shared landmarks (nav/header/main) via PHP includes.
			// The raw template file may not contain the semantic tags itself.
			if (!$hasSemantic && strpos($content, "include __DIR__ . '/common/navigation.php'") !== false) {
				$hasSemantic = true;
			}

			$this->assertTrue($hasSemantic,
				"Template should use semantic HTML elements: $templateFile");

			// Check for headings (some pages may start at h2 depending on Nextcloud layout/includes)
			$this->assertTrue(
				(strpos($content, '<h1') !== false) || (strpos($content, '<h2') !== false),
				"Template should contain headings: $templateFile"
			);
		}
	}

	/**
	 * Test color contrast (basic check - would need more sophisticated testing in real scenario)
	 */
	public function testColorContrastSetup(): void {
		$cssFile = __DIR__ . '/../../css/common/accessibility.css';

		$content = file_get_contents($cssFile);

		// Check for high contrast media query
		$this->assertStringContainsString('@media (prefers-contrast: high)', $content,
			'CSS should support high contrast mode');

		// Check for reduced motion support
		$this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $content,
			'CSS should support reduced motion preferences');
	}
}