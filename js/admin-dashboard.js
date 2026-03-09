/**
 * Admin Dashboard JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    let refreshInterval = null;

    /**
     * Initialize dashboard
     */
    function init() {
        bindEvents();
        setupAutoRefresh();
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        // Refresh statistics button if exists
        const refreshBtn = Utils.$('#refresh-statistics');
        if (refreshBtn) {
            Utils.on(refreshBtn, 'click', refreshStatistics);
        }
    }

    /**
     * Setup auto-refresh for statistics
     */
    function setupAutoRefresh() {
        // Clear any existing interval
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
        
        // Refresh every 5 minutes
        refreshInterval = setInterval(refreshStatistics, 5 * 60 * 1000);
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

    /**
     * Refresh statistics via AJAX
     */
    function refreshStatistics() {
        Utils.ajax('/apps/arbeitszeitcheck/api/admin/statistics', {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.statistics) {
                    updateStatisticsDisplay(data.statistics);
                }
            },
            onError: function(_error) {
                if (Messaging && Messaging.showError) {
                    Messaging.showError('Failed to refresh statistics. Please try again.');
                }
            }
        });
    }

    /**
     * Update statistics display
     */
    function updateStatisticsDisplay(stats) {
        const totalUsersEl = Utils.$('.stat-card:nth-child(1) .stat-number');
        const activeTodayEl = Utils.$('.stat-card:nth-child(2) .stat-number');
        const violationsEl = Utils.$('.stat-card:nth-child(3) .stat-number');

        if (totalUsersEl) totalUsersEl.textContent = stats.total_users || 0;
        if (activeTodayEl) activeTodayEl.textContent = stats.active_users_today || 0;
        if (violationsEl) violationsEl.textContent = stats.unresolved_violations || 0;
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
