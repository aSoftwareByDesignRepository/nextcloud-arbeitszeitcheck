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
			__DIR__ . '/../../templates/admin-settings.php'
		];

		foreach ($templateFiles as $templateFile) {
			$this->assertFileExists($templateFile, "Template file should exist: $templateFile");

			$content = file_get_contents($templateFile);

			// Check for basic accessibility elements
			$this->assertStringContainsString('aria-label', $content,
				"Template should contain aria-label attributes: $templateFile");

			$this->assertStringContainsString('role=', $content,
				"Template should contain role attributes: $templateFile");

			// Check for proper button elements (not just divs with click handlers)
			$this->assertStringContainsString('<button', $content,
				"Template should use proper button elements: $templateFile");

			// Check for form labels
			$this->assertStringContainsString('<label', $content,
				"Template should contain form labels: $templateFile");
		}
	}

	/**
	 * Test that CSS has proper focus indicators
	 */
	public function testCssHasFocusIndicators(): void {
		$cssFile = __DIR__ . '/../../src/styles/main.css';

		$this->assertFileExists($cssFile, 'Main CSS file should exist');

		$content = file_get_contents($cssFile);

		// Check for focus indicators
		$this->assertStringContainsString(':focus', $content,
			'CSS should contain focus indicators');

		$this->assertStringContainsString('outline:', $content,
			'CSS should contain outline properties for focus');

		// Check for focus-visible support
		$this->assertStringContainsString(':focus-visible', $content,
			'CSS should support focus-visible');

		// Check for skip links
		$this->assertStringContainsString('.skip-link', $content,
			'CSS should contain skip link styles');
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

			$this->assertTrue($hasSemantic,
				"Template should use semantic HTML elements: $templateFile");

			// Check for heading hierarchy
			$this->assertStringContainsString('<h1', $content,
				"Template should have h1 element: $templateFile");

			$this->assertStringContainsString('<h2', $content,
				"Template should have h2 elements: $templateFile");
		}
	}

	/**
	 * Test color contrast (basic check - would need more sophisticated testing in real scenario)
	 */
	public function testColorContrastSetup(): void {
		$cssFile = __DIR__ . '/../../src/styles/main.css';

		$content = file_get_contents($cssFile);

		// Check for high contrast media query
		$this->assertStringContainsString('@media (prefers-contrast: high)', $content,
			'CSS should support high contrast mode');

		// Check for reduced motion support
		$this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $content,
			'CSS should support reduced motion preferences');
	}
}