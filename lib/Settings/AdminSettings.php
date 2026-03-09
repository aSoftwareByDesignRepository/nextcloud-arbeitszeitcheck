<?php

declare(strict_types=1);

/**
 * Admin settings for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * AdminSettings
 */
class AdminSettings implements ISettings {

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$parameters = [
			// Add admin settings here
		];

		return new TemplateResponse('arbeitszeitcheck', 'admin-settings', $parameters);
	}

	/**
	 * @return string
	 */
	public function getSection(): string {
		return 'arbeitszeitcheck';
	}

	/**
	 * @return int
	 */
	public function getPriority(): int {
		return 50;
	}
}