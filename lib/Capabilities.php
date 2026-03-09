<?php

declare(strict_types=1);

/**
 * Capabilities class for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck;

use OCP\Capabilities\ICapability;

/**
 * Class Capabilities
 */
class Capabilities implements ICapability {
	/**
	 * @return array
	 */
	public function getCapabilities(): array {
		return [
			'arbeitszeitcheck' => [
				'version' => '1.0.0',
				'features' => [
					'time-tracking',
					'compliance-monitoring',
					'absence-management',
					'reporting',
					'gdpr-compliance',
					'arbzg-compliance',
					'accessibility-wcag-aaa',
					'projectcheck-integration'
				],
				'compliance' => [
					'german-labor-law' => true,
					'gdpr' => true,
					'audit-logging' => true,
					'data-retention' => true
				],
				'accessibility' => [
					'wcag-level' => 'AAA',
					'screen-reader' => true,
					'keyboard-navigation' => true,
					'high-contrast' => true
				]
			]
		];
	}
}