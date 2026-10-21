<?php

declare(strict_types=1);

/**
 * Focus is one ring, on the control. A second outline on the label group,
 * the card, or the page section is a regression.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

class FocusOnControlContractTest extends TestCase {
	public function testContainersDoNotDrawASecondFocusRing(): void {
		$root = dirname(__DIR__, 3);
		$banned = [
			'css/common/accessibility.css' => [
				'.form-group:focus-within',
				'.card:focus-within',
			],
			'css/common/app-layout.css' => [
				'.section:focus-within',
			],
			'css/common/timepicker.css' => [
				'.azc-timepicker__control:focus-within',
			],
			'css/admin-vacation-layers.css' => [
				'.layer-form__fieldset:focus-within',
			],
		];

		foreach ($banned as $relative => $needles) {
			$css = file_get_contents($root . '/' . $relative);
			$this->assertIsString($css, $relative);
			foreach ($needles as $needle) {
				$this->assertStringNotContainsString($needle, $css, $relative . ' still outlines ' . $needle);
			}
		}
	}

	public function testTheFocusedControlKeepsItsOwnRing(): void {
		$root = dirname(__DIR__, 3);
		$inputs = file_get_contents($root . '/css/common/components.css');
		$picker = file_get_contents($root . '/css/common/timepicker.css');
		$this->assertIsString($inputs);
		$this->assertIsString($picker);
		$this->assertStringContainsString('.form-group input:focus', $inputs);
		$this->assertStringContainsString('box-shadow: 0 0 0 3px', $inputs);
		$this->assertStringContainsString('.azc-timepicker__section:focus', $picker);
		$this->assertStringContainsString('outline: 2px solid var(--color-primary-element)', $picker);
	}
}
