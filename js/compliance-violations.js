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

    function cvT(msg) {
        const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.complianceViolationsL10n;
        if (map && Object.prototype.hasOwnProperty.call(map, msg) && map[msg] !== undefined && map[msg] !== '') {
            return map[msg];
        }
        return (typeof window.t === 'function' ? window.t('arbeitszeitcheck', msg) : msg);
    }

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
            const loadingMsg = cvT('Loading...');
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(loadingMsg) + '</td></tr>';
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/compliance/violations?' + params.toString(), {
            method: 'GET',
            onSuccess: function (data) {
                if (data.success && data.violations) {
                    renderViolations(data.violations);
                } else {
                    const errorMsg = cvT('Error loading violations');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                }
            },
            onError: function (_error) {
                const errorMsg = cvT('Error loading violations');
                if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                if (Messaging && Messaging.showError) {
                    const failMsg = cvT('Failed to load violations. Please try again.');
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
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + cvT('No violations found') + '</td></tr>';
            return;
        }

        const resolvedLabel = cvT('Resolved');
        const unresolvedLabel = cvT('Unresolved');

        const typeLabels = {
            missing_break: cvT('Missing break'),
            excessive_working_hours: cvT('Excessive working hours'),
            insufficient_rest_period: cvT('Insufficient rest period'),
            daily_hours_limit_exceeded: cvT('Daily hours limit exceeded'),
            weekly_hours_limit_exceeded: cvT('Weekly hours limit exceeded'),
            night_work: cvT('Night work'),
            sunday_work: cvT('Sunday work'),
            holiday_work: cvT('Holiday work'),
        };

        const severityLabels = {
            error: cvT('High'),
            warning: cvT('Medium'),
            info: cvT('Low'),
        };

        tbody.innerHTML = violations.map(v => `
            <tr>
                <td>${Utils.escapeHtml(typeLabels[v.type] || v.type)}</td>
                <td>
                    <span class="badge badge--${v.severity === 'error' ? 'error' : (v.severity === 'warning' ? 'warning' : 'primary')}">
                        ${Utils.escapeHtml(severityLabels[v.severity] || v.severity)}
                    </span>
                </td>
                <td>${Utils.escapeHtml(v.date || '-')}</td>
                <td>${Utils.escapeHtml(v.description || '-')}</td>
                <td>
                    <span class="badge badge--${v.resolved ? 'success' : 'error'}">
                        ${Utils.escapeHtml(v.resolved ? resolvedLabel : unresolvedLabel)}
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
