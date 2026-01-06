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

        // Allow Google Fonts (used by Nextcloud themes)
        // These are commonly used by Nextcloud core and themes
        // IMPORTANT: Call addAllowedFontDomain() which initializes the array if null
        $csp->addAllowedFontDomain('https://fonts.gstatic.com');
        $csp->addAllowedFontDomain('fonts.gstatic.com');
        $csp->addAllowedStyleDomain('https://fonts.googleapis.com');
        $csp->addAllowedStyleDomain('fonts.googleapis.com');

        // NOTE: Nextcloud Core uses eval() in some minified scripts (e.g., baseline-browser-mapping).
        // To avoid CSP violations, we allow unsafe-eval, but only because Nextcloud Core requires it.
        // Our app code does NOT use eval() - this is purely for Nextcloud Core compatibility.
        $csp->allowEvalScript(true);

        $event->addPolicy($csp);
    }
}
