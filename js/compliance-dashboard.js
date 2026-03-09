/**
 * Compliance Dashboard JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const _Utils = window.ArbeitszeitCheckUtils || {};
    const _Messaging = window.ArbeitszeitCheckMessaging || {};

    let refreshInterval = null;

    /**
     * Initialize dashboard
     */
    function init() {
        setupAutoRefresh();
    }

    /**
     * Setup auto-refresh
     */
    function setupAutoRefresh() {
        // Clear any existing interval
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
        
        // Refresh every 5 minutes
        refreshInterval = setInterval(refreshComplianceStatus, 5 * 60 * 1000);
    }

    /**
     * Refresh compliance status
     * 
     * Note: Compliance status is managed server-side and updated automatically.
     * This function can be extended to implement client-side refresh if needed.
     */
    function refreshComplianceStatus() {
        // Compliance status is managed server-side and updated automatically
        // Client-side refresh can be implemented here if needed via API endpoint
    }

    /**
     * Cleanup on page unload
     */
    function cleanup() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', cleanup);

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
