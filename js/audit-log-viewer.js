/**
 * Audit Log Viewer JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    /**
     * Initialize audit log viewer
     */
    function init() {
        bindEvents();
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const applyBtn = Utils.$('#apply-filters');
        if (applyBtn) {
            Utils.on(applyBtn, 'click', loadAuditLogs);
        }

        const exportBtn = Utils.$('#export-logs');
        if (exportBtn) {
            Utils.on(exportBtn, 'click', exportLogs);
        }
    }

    /**
     * Load audit logs with filters
     */
    function loadAuditLogs() {
        const dp = window.ArbeitszeitCheckDatepicker;
        const toISO = dp ? dp.convertEuropeanToISO : function (s) { return s; };
        const startDate = toISO(Utils.$('#start-date')?.value || '');
        const endDate = toISO(Utils.$('#end-date')?.value || '');
        const userId = Utils.$('#user-filter')?.value || '';
        const action = Utils.$('#action-filter')?.value || '';

        const params = new URLSearchParams();
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (userId) params.append('user_id', userId);
        if (action) params.append('action', action);

        const tbody = Utils.$('#audit-log-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Loading…') : 'Loading…') + '</td></tr>';
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/audit-logs?' + params.toString(), {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.logs) {
                    renderAuditLogs(data.logs);
                } else {
                    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading audit logs') : 'Error loading audit logs') + '</td></tr>';
                }
            },
            onError: function(_error) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading audit logs') : 'Error loading audit logs') + '</td></tr>';
                if (Messaging && Messaging.showError) {
                    Messaging.showError(window.t ? window.t('arbeitszeitcheck', 'Failed to load audit logs. Please try again.') : 'Failed to load audit logs. Please try again.');
                }
            }
        });
    }

    /**
     * Render audit logs table
     */
    function renderAuditLogs(logs) {
        const tbody = Utils.$('#audit-log-tbody');
        if (!tbody) return;

        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'No audit log entries found') : 'No audit log entries found') + '</td></tr>';
            return;
        }

        tbody.innerHTML = logs.map(log => {
            const created = log.created_at || log.createdAt || '-';
            const user = log.user_display_name || log.userDisplayName || log.user_id || log.userId;
            const performed = log.performed_by_display_name || log.performedByDisplayName || log.performed_by || log.performedBy || '-';
            const entity = log.entity_type || log.entityType;
            return `<tr>
                <td>${Utils.escapeHtml(String(created))}</td>
                <td>${Utils.escapeHtml(String(user))}</td>
                <td>${Utils.escapeHtml(String(log.action))}</td>
                <td>${Utils.escapeHtml(String(entity))}</td>
                <td>${Utils.escapeHtml(String(performed))}</td>
            </tr>`;
        }).join('');
    }

    /**
     * Export audit logs
     */
    function exportLogs() {
        const dp = window.ArbeitszeitCheckDatepicker;
        const toISO = dp ? dp.convertEuropeanToISO : function (s) { return s; };
        const startDate = toISO(Utils.$('#start-date')?.value || '');
        const endDate = toISO(Utils.$('#end-date')?.value || '');
        const userId = Utils.$('#user-filter')?.value || '';
        const action = Utils.$('#action-filter')?.value || '';

        const params = new URLSearchParams();
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (userId) params.append('user_id', userId);
        if (action) params.append('action', action);

        // Redirect to export endpoint
        params.append('format', 'csv');
        window.location.href = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/audit-logs/export?' + params.toString());
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
