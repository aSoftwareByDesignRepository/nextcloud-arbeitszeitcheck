<?php

declare(strict_types=1);

/**
 * CSP Listener for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Listener to register CSP policies globally
 * 
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CSPListener implements IEventListener
{
    public function handle(Event $event): void
    {
        if (!($event instanceof AddContentSecurityPolicyEvent)) {
            return;
        }

        // Use EmptyContentSecurityPolicy to avoid conflicts with default values
        // This ensures our additions are properly merged with the default policy
        $csp = new \OCP\AppFramework\Http\EmptyContentSecurityPolicy();

        // Allow Google Fonts (used by some Nextcloud themes).
        // These relax CSP only for font/style sources, not for scripts.
        // IMPORTANT: Call addAllowedFontDomain() which initializes the array if null.
        $csp->addAllowedFontDomain('https://fonts.gstatic.com');
        $csp->addAllowedFontDomain('fonts.gstatic.com');
        $csp->addAllowedStyleDomain('https://fonts.googleapis.com');
        $csp->addAllowedStyleDomain('fonts.googleapis.com');

        // IMPORTANT: We intentionally do NOT enable eval in scripts here (`unsafe-eval`).
        // Enabling eval would weaken CSP for every Nextcloud app page because this listener
        // is registered globally via AddContentSecurityPolicyEvent.
        // Nextcloud 31 core does not require eval for its own scripts, so we keep it disabled.

        $event->addPolicy($csp);
    }
}
