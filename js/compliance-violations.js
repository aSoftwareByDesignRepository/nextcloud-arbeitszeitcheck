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
    let employeePicker = null;

    function cvT(msg) {
        const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.complianceViolationsL10n;
        if (map && Object.prototype.hasOwnProperty.call(map, msg) && map[msg] !== undefined && map[msg] !== '') {
            return map[msg];
        }
        return (typeof window.t === 'function' ? window.t('arbeitszeitcheck', msg) : msg);
    }

    function scopeCfg() {
        return (window.ArbeitszeitCheck && window.ArbeitszeitCheck.complianceViolationsScope) || {};
    }

    function canSeeScope() {
        return !!scopeCfg().canSeeScope;
    }

    function colCount() {
        return Number(scopeCfg().columns) || (canSeeScope() ? 6 : 5);
    }

    /**
     * Initialize violations page
     */
    function getFilterUserId() {
        const cfg = window.ArbeitszeitCheck || {};
        const id = cfg.complianceFilterUserId;
        return id && String(id).trim() !== '' ? String(id).trim() : '';
    }

    function init() {
        initEmployeePicker();
        bindEvents();
        // Reload only when a pre-filtered single-user view is active; the
        // server-rendered rows already match the default (scope or self).
        if (getFilterUserId()) {
            loadViolations();
        }
    }

    function initEmployeePicker() {
        const initPicker = window.ArbeitszeitCheck?.initManagerScopedEmployeePicker;
        if (!initPicker || !document.getElementById('violations-employee-filter-search')) {
            return;
        }
        employeePicker = initPicker({
            hiddenSelector: '#violations-employee-filter-id',
            searchSelector: '#violations-employee-filter-search',
            listSelector: '#violations-employee-filter-listbox',
            wrapSelector: '#violations-employee-filter-wrap',
            statusSelector: '#violations-employee-filter-status',
            clearButtonSelector: '#violations-employee-filter-clear',
            idPrefix: 'violations-employee-filter',
            allowAll: true,
            onChange: function () {
                // A picked employee narrows the view immediately — no separate
                // Apply step needed for the person filter.
                loadViolations();
            },
        });
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const applyBtn = Utils.$('#apply-filters');
        if (applyBtn) {
            Utils.on(applyBtn, 'click', loadViolations);
        }
        const scopeSelect = Utils.$('#scope-filter');
        if (scopeSelect) {
            scopeSelect.addEventListener('change', loadViolations);
        }
        document.querySelectorAll('[id^="btn-resolve-violation-"]').forEach((btn) => {
            btn.addEventListener('click', () => resolveViolation(btn.getAttribute('data-violation-id')));
        });
    }

    function selectedEmployeeId() {
        if (employeePicker && typeof employeePicker.getUserId === 'function') {
            const id = String(employeePicker.getUserId() || '').trim();
            if (id !== '') {
                return id;
            }
        }
        const hidden = document.getElementById('violations-employee-filter-id');
        return hidden ? String(hidden.value || '').trim() : '';
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

        // Selector precedence: a picked employee wins; otherwise the scope
        // select decides between the whole oversight scope and own-only.
        const pickedUserId = selectedEmployeeId() || getFilterUserId();
        const scopeSelect = Utils.$('#scope-filter');
        const scopeChoice = scopeSelect ? scopeSelect.value : '';
        if (pickedUserId) {
            params.append('userId', pickedUserId);
        } else if (canSeeScope() && scopeChoice === 'scope') {
            params.append('scope', String(scopeCfg().scopeValue || 'managed'));
        }

        const tbody = Utils.$('#violations-tbody');
        if (tbody) {
            const loadingMsg = cvT('Loading...');
            tbody.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center">' + Utils.escapeHtml(loadingMsg) + '</td></tr>';
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/compliance/violations?' + params.toString(), {
            method: 'GET',
            onSuccess: function (data) {
                if (data.success && data.violations) {
                    renderViolations(data.violations);
                } else {
                    const errorMsg = (data && data.error) ? data.error : cvT('Error loading violations');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                }
            },
            onError: function (error) {
                window.ArbeitszeitCheck?.handleManagerListApiError?.(error, {
                    picker: employeePicker,
                    searchSelector: '#violations-employee-filter-search',
                    clearButtonSelector: '#violations-employee-filter-clear',
                });
                const errorMsg = (error && error.error) ? error.error : cvT('Error loading violations');
                if (tbody) tbody.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center">' + Utils.escapeHtml(errorMsg) + '</td></tr>';
                if (Messaging && Messaging.showError) {
                    const failMsg = (error && error.error) ? error.error : cvT('Failed to load violations. Please try again.');
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
            tbody.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center">' + cvT('No violations found') + '</td></tr>';
            return;
        }

        const resolvedLabel = cvT('Resolved');
        const unresolvedLabel = cvT('Unresolved');
        const showEmployee = canSeeScope();

        const typeLabels = {
            missing_break: cvT('Missing break'),
            excessive_working_hours: cvT('Excessive working hours'),
            insufficient_rest_period: cvT('Insufficient rest period'),
            daily_hours_limit_exceeded: cvT('Daily hours limit exceeded'),
            weekly_hours_limit_exceeded: cvT('Weekly hours limit exceeded'),
            weekly_absolute_hours_exceeded: cvT('Absolute weekly hours maximum exceeded'),
            night_work: cvT('Night work'),
            sunday_work: cvT('Sunday work'),
            holiday_work: cvT('Holiday work'),
            missing_presence: cvT('Required presence not covered'),
        };

        const severityLabels = {
            error: cvT('High'),
            warning: cvT('Medium'),
            info: cvT('Low'),
        };

        tbody.innerHTML = violations.map(v => {
            const resolveBtn = (!v.resolved && v.can_resolve)
                ? `<button type="button" class="btn btn--sm btn--secondary" id="btn-resolve-violation-${Utils.escapeHtml(String(v.id))}" data-violation-id="${Utils.escapeHtml(String(v.id))}">${Utils.escapeHtml(cvT('Mark as fixed'))}</button>`
                : '';
            const td = (label, html) => Utils.responsiveTd
                ? Utils.responsiveTd(label, html)
                : `<td>${html}</td>`;
            const employeeCell = showEmployee
                ? td(cvT('Employee'), Utils.escapeHtml(v.displayName || v.userId || '-'))
                : '';
            return `
            <tr>
                ${employeeCell}
                ${td(cvT('Problem Type'), Utils.escapeHtml(typeLabels[v.type] || v.type))}
                ${td(cvT('How Serious'), `<span class="badge badge--${Utils.badgeVariantForComplianceSeverity ? Utils.badgeVariantForComplianceSeverity(v.severity) : (v.severity === 'error' ? 'error' : (v.severity === 'warning' ? 'warning' : 'primary'))}">
                        ${Utils.escapeHtml(severityLabels[v.severity] || v.severity)}
                    </span>`)}
                ${td(cvT('Date'), Utils.escapeHtml(v.date || '-'))}
                ${td(cvT('What Happened'), Utils.escapeHtml(v.description || '-'))}
                ${td(cvT('Fixed?'), `<span class="badge badge--${v.resolved ? 'success' : 'error'}">
                        ${Utils.escapeHtml(v.resolved ? resolvedLabel : unresolvedLabel)}
                    </span>
                    ${resolveBtn}`)}
            </tr>`;
        }).join('');

        tbody.querySelectorAll('[id^="btn-resolve-violation-"]').forEach((btn) => {
            btn.addEventListener('click', () => resolveViolation(btn.getAttribute('data-violation-id')));
        });
    }

    async function resolveViolation(id) {
        if (!id) return;
        const confirmed = await Utils.confirmDestructiveAction({
            title: cvT('Mark as fixed'),
            message: cvT('Confirm that this violation has been reviewed and corrected.'),
            confirmLabel: cvT('Mark as fixed'),
            variant: 'primary',
        });
        if (!confirmed) {
            return;
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/compliance/violations/' + encodeURIComponent(id) + '/resolve', {
            method: 'POST',
            onSuccess: function (data) {
                if (data.success) {
                    Messaging.showSuccess?.(cvT('Violation marked as fixed.'));
                    Messaging.announcePolite?.(cvT('Violation marked as fixed.'));
                    loadViolations();
                } else {
                    Messaging.showError?.(data.error || cvT('Could not update violation.'));
                }
            },
            onError: function () {
                Messaging.showError?.(cvT('Could not update violation.'));
            },
        });
    }

    // Initialize on DOM ready
    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckComplianceViolationsTestables = {
            resolveViolation: resolveViolation,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
