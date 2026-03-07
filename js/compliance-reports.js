/**
 * Compliance Reports JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const _Utils = window.ArbeitszeitCheckUtils || {};
    const _Messaging = window.ArbeitszeitCheckMessaging || {};

    /**
     * Initialize reports page
     */
    function init() {
        // Reports page is mostly static, but could add export functionality here
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
