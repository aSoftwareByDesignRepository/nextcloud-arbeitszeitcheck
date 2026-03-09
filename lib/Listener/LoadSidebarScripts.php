<?php

declare(strict_types=1);

/**
 * LoadSidebarScripts listener for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * LoadSidebarScripts
 */
class LoadSidebarScripts implements IEventListener
{

	/**
	 * @inheritDoc
	 */
	public function handle(Event $event): void
	{
		// Load scripts and styles when the sidebar is loaded
		// Note: Cannot use Util::addScript for ES modules in NC32
		// Script must be loaded in template with type="module"
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
	}
}
