/**
 * Compliance Violations JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function () {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    /**
     * Initialize violations page
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
            Utils.on(applyBtn, 'click', loadViolations);
        }
    }

    /**
     * Load violations with filters
     */
    function loadViolations() {
        const dp = window.ArbeitszeitCheckDatepicker;
        const toISO = dp ? dp.convertEuropeanToISO : function (s) { return s; };
        const startDate = toISO(Utils.$('#start-date')?.value || '');
        const endDate = toISO(Utils.$('#end-date')?.value || '');
        const severity = Utils.$('#severity-filter')?.value || '';

        const params = new URLSearchParams();
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        if (severity) params.append('severity', severity);

        const tbody = Utils.$('#violations-tbody');
        if (tbody) {
            const loadingMsg = (window.t && window.t('arbeitszeitcheck', 'Loading...')) || 'Loading...';
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(loadingMsg) + '</td></tr>';
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/compliance/violations?' + params.toString(), {
            method: 'GET',
            onSuccess: function (data) {
                if (data.success && data.violations) {
                    renderViolations(data.violations);
                } else {
                    const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Error loading violations')) || 'Error loading violations';
                    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                }
            },
            onError: function (_error) {
                const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Error loading violations')) || 'Error loading violations';
                if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                if (Messaging && Messaging.showError) {
                    const failMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to load violations. Please try again.')) || 'Failed to load violations. Please try again.';
                    Messaging.showError(failMsg);
                }
            }
        });
    }

    /**
     * Render violations table
     */
    function renderViolations(violations) {
        const tbody = Utils.$('#violations-tbody');
        if (!tbody) return;

        if (violations.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'No violations found') : 'No violations found') + '</td></tr>';
            return;
        }

        const resolvedLabel = (window.t && window.t('arbeitszeitcheck', 'Resolved')) || 'Resolved';
        const unresolvedLabel = (window.t && window.t('arbeitszeitcheck', 'Unresolved')) || 'Unresolved';

        tbody.innerHTML = violations.map(v => `
            <tr>
                <td>${Utils.escapeHtml(v.type)}</td>
                <td>
                    <span class="badge badge--${v.severity === 'high' ? 'error' : (v.severity === 'medium' ? 'warning' : 'primary')}">
                        ${Utils.escapeHtml(v.severity)}
                    </span>
                </td>
                <td>${v.date || '-'}</td>
                <td>${Utils.escapeHtml(v.description || '-')}</td>
                <td>
                    <span class="badge badge--${v.resolved ? 'success' : 'error'}">
                        ${v.resolved ? resolvedLabel : unresolvedLabel}
                    </span>
                </td>
            </tr>
        `).join('');
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
