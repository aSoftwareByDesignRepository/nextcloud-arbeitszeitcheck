<?php

declare(strict_types=1);

/**
 * Personal settings for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * PersonalSettings
 */
class PersonalSettings implements ISettings {

	/** @var IUserSession */
	private $userSession;

	public function __construct(IUserSession $userSession) {
		$this->userSession = $userSession;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		$userId = $user ? $user->getUID() : '';

		$parameters = [
			'user_id' => $userId,
			// Add user-specific settings here
		];

		return new TemplateResponse('arbeitszeitcheck', 'personal-settings', $parameters);
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