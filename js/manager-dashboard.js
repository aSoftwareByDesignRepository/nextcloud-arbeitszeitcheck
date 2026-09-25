/**
 * Manager Dashboard JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};
    const Components = window.ArbeitszeitCheckComponents || {};

    function buildApiUrl(path) {
        if (Utils.buildAppUrl) {
            return Utils.buildAppUrl(path);
        }
        if (Utils.resolveUrl) {
            return Utils.resolveUrl(path);
        }
        return path;
    }

    let refreshInterval = null;

    /** Always read current l10n (inline template may run after this file; other scripts may replace the object). */
    function t(key, fallback) {
        const bundle = window.ArbeitszeitCheck?.l10n || {};
        const v = bundle[key];
        if (v !== undefined && v !== '') {
            return v;
        }
        return (typeof window.t === 'function' ? window.t('arbeitszeitcheck', fallback || key) : fallback || key);
    }

    /** Plural-aware translate (Nextcloud window.n replaces %n). */
    function tn(singular, plural, count) {
        const n = typeof count === 'number' ? count : parseInt(String(count), 10) || 0;
        if (typeof window.n === 'function') {
            return window.n('arbeitszeitcheck', singular, plural, n);
        }
        return n === 1 ? singular : String(plural).replace(/%n/g, String(n));
    }

    function escapeHtml(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatDateForDisplay(dateString) {
        if (!dateString) return '';
        const api = window.ArbeitszeitCheckTime;
        if (api) {
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
                const parsed = api.parseYmd(dateString);
                return parsed ? api.formatDate(parsed) : dateString;
            }
            return api.formatDate(dateString) || dateString;
        }
        if (Utils.formatDate) {
            return Utils.formatDate(dateString, 'DD.MM.YYYY') || dateString;
        }
        return dateString;
    }

    function parseSummary(summary) {
        if (summary == null) {
            return {};
        }
        if (typeof summary === 'object' && !Array.isArray(summary)) {
            return summary;
        }
        if (typeof summary === 'string') {
            try {
                const o = JSON.parse(summary);
                return (typeof o === 'object' && o !== null && !Array.isArray(o)) ? o : {};
            } catch (e) {
                return {};
            }
        }
        return {};
    }

    /**
     * Maps API absence type codes → Nextcloud l10n message IDs (English source strings).
     * Never render map values directly — always pass through t() so locale packs apply.
     * Keep in sync with AbsenceTypeLabel + manager-dashboard.php injected l10n keys.
     */
    var ABSENCE_TYPE_TO_L10N_KEY = {
        vacation: 'Vacation',
        sick_leave: 'Sick leave',
        personal_leave: 'Personal leave',
        parental_leave: 'Parental leave',
        special_leave: 'Special leave',
        unpaid_leave: 'Unpaid leave',
        home_office: 'Home office',
        business_trip: 'Business trip'
    };

    /** Normalize codes from API (hyphens, stray unicode) so the map lookup succeeds. */
    function normalizeAbsenceTypeCode(raw) {
        if (raw == null) {
            return '';
        }
        let s = String(raw).trim().replace(/[\u200B-\u200D\uFEFF]/g, '');
        s = s.replace(/-/g, '_').toLowerCase();
        return s;
    }

    function absenceTypeDisplay(raw) {
        const code = normalizeAbsenceTypeCode(raw);
        if (code === '' || code === 'absence') {
            return t('Absence', 'Absence');
        }
        const msgKey = ABSENCE_TYPE_TO_L10N_KEY[code];
        if (msgKey) {
            return t(msgKey, msgKey);
        }
        return String(raw).trim();
    }

    function absenceTitleTypeDisplay(s, typeCode) {
        // Prefer server-localized typeLabel from pending-approvals API (same IL10N as UI language).
        const serverLabel = s.typeLabel != null ? String(s.typeLabel).trim() : '';
        if (serverLabel !== '' && !/^(vacation|sick_leave|personal_leave|parental_leave|special_leave|unpaid_leave|home_office|business_trip)$/i.test(serverLabel)) {
            return serverLabel;
        }
        return absenceTypeDisplay(typeCode);
    }

    function init() {
        setupTabs();
        loadPendingApprovals();
        loadPendingTimeEntryCorrections();
        loadTeamCompliance();
        loadTeamOvertimeAlerts();
        setupAutoRefresh();
    }

    // ===== TABS =====
    function selectPendingTab(which) {
        const tabAbsences = document.getElementById('tab-absences');
        const tabTimeEntries = document.getElementById('tab-time-entries');
        const panelAbsences = document.getElementById('pending-absences-panel');
        const panelTimeEntries = document.getElementById('pending-time-entries-panel');
        if (!tabAbsences || !tabTimeEntries || !panelAbsences || !panelTimeEntries) return;

        const showTimeEntries = which === 'time-entries';
        tabTimeEntries.classList.toggle('pending-approvals-tab--active', showTimeEntries);
        tabTimeEntries.setAttribute('aria-selected', showTimeEntries ? 'true' : 'false');
        tabAbsences.classList.toggle('pending-approvals-tab--active', !showTimeEntries);
        tabAbsences.setAttribute('aria-selected', showTimeEntries ? 'false' : 'true');
        panelTimeEntries.classList.toggle('pending-approvals-panel--hidden', !showTimeEntries);
        panelTimeEntries.setAttribute('aria-hidden', showTimeEntries ? 'false' : 'true');
        panelAbsences.classList.toggle('pending-approvals-panel--hidden', showTimeEntries);
        panelAbsences.setAttribute('aria-hidden', showTimeEntries ? 'true' : 'false');

        const focusTarget = showTimeEntries ? panelTimeEntries : panelAbsences;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            if (!focusTarget.hasAttribute('tabindex')) {
                focusTarget.setAttribute('tabindex', '-1');
            }
            try {
                focusTarget.focus({ preventScroll: false });
            } catch (e) {
                focusTarget.focus();
            }
        }
    }

    function setupTabs() {
        const tabAbsences = document.getElementById('tab-absences');
        const tabTimeEntries = document.getElementById('tab-time-entries');
        if (!tabAbsences || !tabTimeEntries) return;

        tabAbsences.addEventListener('click', function() {
            selectPendingTab('absences');
        });
        tabTimeEntries.addEventListener('click', function() {
            selectPendingTab('time-entries');
        });

        document.querySelectorAll('[data-manager-pending-tab]').forEach(function(tile) {
            tile.addEventListener('click', function() {
                const which = tile.getAttribute('data-manager-pending-tab');
                if (which === 'time-entries' || which === 'absences') {
                    selectPendingTab(which);
                }
            });
        });
    }

    // ===== PENDING APPROVALS: ABSENCES =====
    function loadPendingApprovals() {
        const loadingEl = document.getElementById('pending-approvals-loading');
        const itemsEl = document.getElementById('pending-approvals-items');
        const emptyEl = document.getElementById('pending-approvals-empty');
        if (!loadingEl || !itemsEl || !emptyEl) return;

        loadingEl.setAttribute('aria-hidden', 'false');
        itemsEl.setAttribute('aria-hidden', 'true');
        emptyEl.classList.add('visually-hidden');

        Utils.ajax('/apps/arbeitszeitcheck/api/manager/pending-approvals?type=absence', {
            method: 'GET',
            onSuccess: function(data) {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                const list = (data && data.pendingApprovals) ? data.pendingApprovals : [];
                const absences = list.filter(function(item) { return item.type === 'absence'; });
                if (absences.length === 0) {
                    emptyEl.classList.remove('visually-hidden');
                    emptyEl.textContent = t('No pending absence requests.', 'No pending absence requests.');
                    itemsEl.innerHTML = '';
                } else {
                    emptyEl.classList.add('visually-hidden');
                    itemsEl.setAttribute('aria-hidden', 'false');
                    itemsEl.innerHTML = absences.map(function(item) { return renderAbsenceCard(item); }).join('');
                    bindAbsenceButtons();
                }
            },
            onError: function() {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                itemsEl.setAttribute('aria-hidden', 'true');
                emptyEl.classList.remove('visually-hidden');
                emptyEl.textContent = t('Error loading pending approvals.', 'Error loading pending approvals.');
            }
        });
    }

    function renderAbsenceCard(item) {
        const s = parseSummary(item.summary);
        // Prefer absence kind from summary; API uses camelCase (type). item.type is always "absence" for the record — do not use it as the kind.
        const typeCode = s.type != null && s.type !== ''
            ? s.type
            : (s.absence_type || s.absenceType || 'absence');
        const start = formatDateForDisplay(s.start_date || s.startDate || '');
        const end = formatDateForDisplay(s.end_date || s.endDate || '');
        const days = s.days != null ? s.days : '';
        const durationHours = s.durationHours != null ? s.durationHours : (s.duration_hours != null ? s.duration_hours : null);
        const id = item.id;
        const displayName = escapeHtml(item.displayName || item.userId || '');
        const typeDisplay = escapeHtml(absenceTitleTypeDisplay(s, typeCode));
        let durationMeta = '';
        const hoursMode = ((window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationUnit) || 'days') === 'hours';
        const hoursPerDay = Number((window.ArbeitszeitCheck && window.ArbeitszeitCheck.vacationHoursPerDay) || 8) || 8;
        if (durationHours != null && Number(durationHours) > 0) {
            durationMeta = ' (' + escapeHtml(String(Number(durationHours).toFixed(1))) + ' ' + t('hours', 'hours') + ')';
        } else if (hoursMode && days !== '' && Number(days) > 0) {
            const converted = Math.round(Number(days) * hoursPerDay * 100) / 100;
            durationMeta = ' (' + escapeHtml(String(converted.toFixed(1))) + ' ' + t('hours', 'hours') + ')';
        } else if (days) {
            durationMeta = ' (' + escapeHtml(String(days)) + ' ' + t('days', 'days') + ')';
        }
        return (
            '<div class="pending-approval-card pending-approval-card--absence" data-absence-id="' + escapeHtml(String(id)) + '" role="article">' +
            '  <div class="pending-approval-card__body">' +
            '    <p class="pending-approval-card__title"><strong>' + displayName + '</strong> – ' + typeDisplay + '</p>' +
            '    <p class="pending-approval-card__meta">' + escapeHtml(start) + ' – ' + escapeHtml(end) + durationMeta + '</p>' +
            '    <div class="pending-approval-card__actions">' +
            '      <button type="button" class="azc-btn azc-btn--primary btn-approve-absence" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Approve', 'Approve') + ' ' + displayName + '">' + t('Approve', 'Approve') + '</button>' +
            '      <button type="button" class="azc-btn azc-btn--secondary btn-reject-absence" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Reject', 'Reject') + ' ' + displayName + '">' + t('Reject', 'Reject') + '</button>' +
            '    </div>' +
            '  </div>' +
            '</div>'
        );
    }

    function bindAbsenceButtons() {
        document.querySelectorAll('.btn-approve-absence').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-absence-id');
                if (id) approveAbsence(parseInt(id, 10));
            });
        });
        document.querySelectorAll('.btn-reject-absence').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-absence-id');
                if (id) showRejectAbsenceModal(parseInt(id, 10));
            });
        });
    }

    function approveAbsence(absenceId) {
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/manager/absences/' + absenceId + '/approve'), {
            method: 'POST',
            data: {},
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Absence approved.', 'Absence approved.'));
                    removeAbsenceCard(absenceId);
                    loadPendingApprovals();
                } else {
                    Messaging.showError(data.error || t('Failed to approve.', 'Failed to approve.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to approve absence.', 'Failed to approve absence.'));
            }
        });
    }

    function showRejectAbsenceModal(absenceId) {
        const modalId = 'reject-absence-modal-' + absenceId;
        if (document.getElementById(modalId)) {
            Components.openModal(modalId);
            return;
        }
        const textareaId = 'reject-absence-reason-' + absenceId;
        const content = [
            '<p class="reject-modal-desc" id="reject-absence-desc-' + absenceId + '">' + escapeHtml(t('Optional reason for rejection (leave empty for none):', 'Optional reason for rejection (leave empty for none):')) + '</p>',
            '<label for="' + textareaId + '" class="visually-hidden">' + escapeHtml(t('Reason for rejection (optional)', 'Reason for rejection (optional)')) + '</label>',
            '<textarea id="' + textareaId + '" rows="3" class="reject-modal-textarea" placeholder="' + escapeHtml(t('Enter reason for rejection...', 'Enter reason for rejection...')) + '" maxlength="1000" aria-describedby="reject-absence-desc-' + absenceId + '"></textarea>',
            '<div class="reject-modal-actions">',
            '  <button type="button" class="azc-btn azc-btn--secondary btn-reject-modal-cancel">' + t('Cancel', 'Cancel') + '</button>',
            '  <button type="button" class="azc-btn azc-btn--primary btn-reject-modal-confirm">' + t('Confirm rejection', 'Confirm rejection') + '</button>',
            '</div>'
        ].join('');
        const modal = Components.createModal({
            id: modalId,
            title: t('Reject Request', 'Reject Request'),
            content: content,
            size: 'md'
        });
        const textarea = modal.querySelector('#' + textareaId);
        const cancelBtn = modal.querySelector('.btn-reject-modal-cancel');
        const confirmBtn = modal.querySelector('.btn-reject-modal-confirm');
        cancelBtn.addEventListener('click', function() { Components.closeModal(modal); });
        confirmBtn.addEventListener('click', function() {
            const comment = textarea ? textarea.value.trim() : '';
            Components.closeModal(modal);
            rejectAbsence(absenceId, comment);
        });
        Components.openModal(modalId);
        if (textarea) setTimeout(function() { textarea.focus(); }, 100);
    }

    function rejectAbsence(absenceId, comment) {
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/manager/absences/' + absenceId + '/reject'), {
            method: 'POST',
            data: { comment: comment || '' },
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Absence rejected.', 'Absence rejected.'));
                    removeAbsenceCard(absenceId);
                    loadPendingApprovals();
                } else {
                    Messaging.showError(data.error || t('Failed to reject.', 'Failed to reject.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to reject absence.', 'Failed to reject absence.'));
            }
        });
    }

    function removeAbsenceCard(absenceId) {
        const card = document.querySelector('.pending-approval-card[data-absence-id="' + absenceId + '"]');
        if (card) card.remove();
    }

    // ===== PENDING TIME ENTRY CORRECTIONS =====
    function loadPendingTimeEntryCorrections() {
        const loadingEl = document.getElementById('pending-time-entries-loading');
        const itemsEl = document.getElementById('pending-time-entries-items');
        const emptyEl = document.getElementById('pending-time-entries-empty');
        if (!loadingEl || !itemsEl || !emptyEl) return;

        loadingEl.setAttribute('aria-hidden', 'false');
        loadingEl.closest('.pending-approvals-panel').classList.contains('pending-approvals-panel--hidden') || loadingEl.removeAttribute('aria-hidden');
        itemsEl.setAttribute('aria-hidden', 'true');
        emptyEl.classList.add('visually-hidden');

        Utils.ajax('/apps/arbeitszeitcheck/api/manager/pending-approvals?type=time_entry', {
            method: 'GET',
            onSuccess: function(data) {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                const list = (data && data.pendingApprovals) ? data.pendingApprovals : [];
                const timeEntries = list.filter(function(item) { return item.type === 'time_entry'; });
                if (timeEntries.length === 0) {
                    emptyEl.classList.remove('visually-hidden');
                    emptyEl.textContent = t('No pending time entries.', 'No pending time entries.');
                    itemsEl.innerHTML = '';
                } else {
                    emptyEl.classList.add('visually-hidden');
                    itemsEl.setAttribute('aria-hidden', 'false');
                    itemsEl.innerHTML = timeEntries.map(function(item) { return renderTimeEntryCard(item); }).join('');
                    bindTimeEntryButtons();
                }
            },
            onError: function() {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                itemsEl.setAttribute('aria-hidden', 'true');
                emptyEl.classList.remove('visually-hidden');
                emptyEl.textContent = t('Error loading pending time entries.', 'Error loading pending time entries.');
            }
        });
    }

    function formatCorrectionTime(iso) {
        if (!iso) {
            return '–';
        }
        const api = window.ArbeitszeitCheckTime;
        if (api) {
            const d = api.formatDate(iso);
            const tm = api.formatTime(iso);
            return d && tm ? d + ' ' + tm : (tm || d || iso);
        }
        return String(iso).slice(0, 16).replace('T', ' ');
    }

    function formatCorrectionBreaks(breaks) {
        if (!Array.isArray(breaks) || breaks.length === 0) {
            return '–';
        }
        return breaks.map(function(b) {
            return formatCorrectionTime(b.start || b.startTime) + ' – ' + formatCorrectionTime(b.end || b.endTime);
        }).join('; ');
    }

    function buildCorrectionDiffRow(label, origVal, propVal) {
        return (
            '<div class="manager-correction-diff__row">' +
            '<span class="manager-correction-diff__field">' + escapeHtml(label) + '</span>' +
            '<span class="manager-correction-diff__orig">' + escapeHtml(origVal) + '</span>' +
            '<span class="manager-correction-diff__prop">' + escapeHtml(propVal) + '</span>' +
            '</div>'
        );
    }

    function renderTimeEntryCard(item) {
        const Card = window.ArbeitszeitCheckManagerPendingTimeEntry;
        if (!Card || typeof Card.renderTimeEntryApprovalCardHtml !== 'function') {
            return '';
        }
        return Card.renderTimeEntryApprovalCardHtml(item, {
            escapeHtml: escapeHtml,
            t: t,
            formatDateForDisplay: formatDateForDisplay,
            formatCorrectionTime: formatCorrectionTime,
            formatCorrectionBreaks: formatCorrectionBreaks,
            buildCorrectionDiffRow: buildCorrectionDiffRow,
        });
    }

    function bindTimeEntryButtons() {
        document.querySelectorAll('.btn-approve-time-entry').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-time-entry-id');
                if (id) approveTimeEntry(parseInt(id, 10));
            });
        });
        document.querySelectorAll('.btn-reject-time-entry').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-time-entry-id');
                if (id) showRejectTimeEntryModal(parseInt(id, 10));
            });
        });
    }

    function approveTimeEntry(timeEntryId) {
        const card = document.querySelector('.pending-approval-card[data-time-entry-id="' + timeEntryId + '"]');
        const isManualCreate = card && card.getAttribute('data-request-type') === 'manual_create';
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/manager/time-entries/' + timeEntryId + '/approve-correction'), {
            method: 'POST',
            data: {},
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(isManualCreate
                        ? t('Manual time entry approved.', 'Manual time entry approved.')
                        : t('Time entry correction approved successfully', 'Time entry correction approved successfully'));
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                    loadTeamCompliance();
                } else {
                    Messaging.showError(data.error || t('Failed to approve.', 'Failed to approve.'));
                }
            },
            onError: function(error) {
                if (error && (error.status === 409 || (error.data && error.data.error_code === 'already_decided'))) {
                    Messaging.showError(
                        (error.data && error.data.error)
                            || t('This time entry was already decided by another manager.', 'This time entry was already decided by another manager.')
                    );
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                    return;
                }
                Messaging.showError(isManualCreate
                    ? t('Failed to approve manual time entry.', 'Failed to approve manual time entry.')
                    : t('Failed to approve time entry correction.', 'Failed to approve time entry correction.'));
            }
        });
    }

    function showRejectTimeEntryModal(timeEntryId) {
        const modalId = 'reject-time-entry-modal-' + timeEntryId;
        if (document.getElementById(modalId)) {
            Components.openModal(modalId);
            return;
        }
        const textareaId = 'reject-time-entry-reason-' + timeEntryId;
        const content = [
            '<p class="reject-modal-desc" id="reject-time-entry-desc-' + timeEntryId + '">' + escapeHtml(t('Optional reason for rejection (leave empty for none):', 'Optional reason for rejection (leave empty for none):')) + '</p>',
            '<label for="' + textareaId + '" class="visually-hidden">' + escapeHtml(t('Reason for rejection (optional)', 'Reason for rejection (optional)')) + '</label>',
            '<textarea id="' + textareaId + '" rows="3" class="reject-modal-textarea" placeholder="' + escapeHtml(t('Enter reason for rejection...', 'Enter reason for rejection...')) + '" maxlength="1000" aria-describedby="reject-time-entry-desc-' + timeEntryId + '"></textarea>',
            '<div class="reject-modal-actions">',
            '  <button type="button" class="azc-btn azc-btn--secondary btn-reject-modal-cancel">' + t('Cancel', 'Cancel') + '</button>',
            '  <button type="button" class="azc-btn azc-btn--primary btn-reject-modal-confirm">' + t('Confirm rejection', 'Confirm rejection') + '</button>',
            '</div>'
        ].join('');
        const modal = Components.createModal({
            id: modalId,
            title: t('Reject Request', 'Reject Request'),
            content: content,
            size: 'md'
        });
        const textarea = modal.querySelector('#' + textareaId);
        const cancelBtn = modal.querySelector('.btn-reject-modal-cancel');
        const confirmBtn = modal.querySelector('.btn-reject-modal-confirm');
        cancelBtn.addEventListener('click', function() { Components.closeModal(modal); });
        confirmBtn.addEventListener('click', function() {
            const reason = textarea ? textarea.value.trim() : '';
            Components.closeModal(modal);
            rejectTimeEntry(timeEntryId, reason);
        });
        Components.openModal(modalId);
        if (textarea) setTimeout(function() { textarea.focus(); }, 100);
    }

    function rejectTimeEntry(timeEntryId, reason) {
        const card = document.querySelector('.pending-approval-card[data-time-entry-id="' + timeEntryId + '"]');
        const isManualCreate = card && card.getAttribute('data-request-type') === 'manual_create';
        Utils.ajax(buildApiUrl('/apps/arbeitszeitcheck/api/manager/time-entries/' + timeEntryId + '/reject-correction'), {
            method: 'POST',
            data: { reason: reason || '' },
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(isManualCreate
                        ? t('Manual time entry rejected.', 'Manual time entry rejected.')
                        : t('Time entry correction rejected', 'Time entry correction rejected'));
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                } else {
                    Messaging.showError(data.error || t('Failed to reject.', 'Failed to reject.'));
                }
            },
            onError: function(error) {
                if (error && (error.status === 409 || (error.data && error.data.error_code === 'already_decided'))) {
                    Messaging.showError(
                        (error.data && error.data.error)
                            || t('This time entry was already decided by another manager.', 'This time entry was already decided by another manager.')
                    );
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                    return;
                }
                Messaging.showError(isManualCreate
                    ? t('Failed to reject manual time entry.', 'Failed to reject manual time entry.')
                    : t('Failed to reject time entry correction.', 'Failed to reject time entry correction.'));
            }
        });
    }

    function removeTimeEntryCard(timeEntryId) {
        const card = document.querySelector('.pending-approval-card[data-time-entry-id="' + timeEntryId + '"]');
        if (card) card.remove();
    }

    // ===== TEAM OVERTIME ALERTS =====
    function trafficLightLabel(state) {
        const map = {
            green: t('Green — balance in target range', 'Green — balance in target range'),
            yellow_over: t('Yellow — overtime threshold', 'Yellow — overtime threshold'),
            red_over: t('Red — high overtime', 'Red — high overtime'),
            yellow_under: t('Yellow — undertime threshold', 'Yellow — undertime threshold'),
            red_under: t('Red — high undertime', 'Red — high undertime'),
        };
        return map[state] || state;
    }

    function loadTeamOvertimeAlerts() {
        const loadingEl = document.getElementById('team-overtime-loading');
        const summaryEl = document.getElementById('team-overtime-summary');
        if (!loadingEl || !summaryEl) {
            return;
        }

        loadingEl.classList.remove('visually-hidden');
        loadingEl.setAttribute('aria-hidden', 'false');
        summaryEl.classList.add('visually-hidden');
        summaryEl.setAttribute('aria-hidden', 'true');

        Utils.ajax('/apps/arbeitszeitcheck/api/manager/team-overtime-alerts', {
            method: 'GET',
            onSuccess: function (data) {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                summaryEl.classList.remove('visually-hidden');
                summaryEl.setAttribute('aria-hidden', 'false');

                if (!data || !data.success) {
                    summaryEl.innerHTML = '<p class="team-overtime-empty">' + escapeHtml(t('Unable to load overtime alerts.', 'Unable to load overtime alerts.')) + '</p>';
                    return;
                }

                const members = data.members || [];
                if (members.length === 0) {
                    const features = data.features || {};
                    if (!features.traffic_light_enabled && !features.bank_enabled) {
                        summaryEl.innerHTML = '<p class="team-overtime-empty">' + escapeHtml(t('Overtime alerts are disabled by the administrator.', 'Overtime alerts are disabled by the administrator.')) + '</p>';
                    } else {
                        summaryEl.innerHTML = '<p class="team-overtime-empty team-overtime-empty--ok">' + escapeHtml(t('No team members need overtime attention right now.', 'No team members need overtime attention right now.')) + '</p>';
                    }
                    return;
                }

                const rows = members.map(function (m) {
                    const parts = [];
                    if (m.traffic_light_enabled && m.traffic_light_needs_attention) {
                        parts.push(escapeHtml(trafficLightLabel(m.traffic_light_state)));
                    }
                    if (m.bank_enabled && m.payout_eligible_hours >= 0.01) {
                        parts.push(escapeHtml(t('Payout eligible: %s h', 'Payout eligible: %s h').replace('%s', String(m.payout_eligible_hours))));
                    } else if (m.bank_enabled && m.bank_state === 'bank_yellow') {
                        parts.push(escapeHtml(t('Bank nearly full', 'Bank nearly full')));
                    } else if (m.bank_enabled && (m.bank_state === 'bank_red' || m.bank_state === 'payout_eligible')) {
                        parts.push(escapeHtml(t('Bank at cap', 'Bank at cap')));
                    }
                    const balance = typeof m.balance === 'number' ? m.balance.toFixed(2) : '0';
                    return '<li class="team-overtime-list__item">'
                        + '<span class="team-overtime-list__name">' + escapeHtml(m.displayName || m.userId) + '</span>'
                        + '<span class="team-overtime-list__meta">' + escapeHtml(balance) + ' h · ' + parts.join(' · ') + '</span>'
                        + '</li>';
                }).join('');

                const countMsg = tn(
                    '1 employee needs attention',
                    '%n employees need attention',
                    members.length
                );
                summaryEl.innerHTML = '<p class="team-overtime-count" role="status">'
                    + escapeHtml(countMsg)
                    + '</p><ul class="team-overtime-list">' + rows + '</ul>';
            },
            onError: function () {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                summaryEl.classList.remove('visually-hidden');
                summaryEl.setAttribute('aria-hidden', 'false');
                summaryEl.innerHTML = '<p class="team-overtime-empty">' + escapeHtml(t('Error loading overtime alerts.', 'Error loading overtime alerts.')) + '</p>';
            },
        });
    }

    // ===== TEAM COMPLIANCE =====
    function loadTeamCompliance() {
        const loadingEl = document.getElementById('team-compliance-loading');
        const summaryEl = document.getElementById('team-compliance-summary');
        if (!loadingEl || !summaryEl) return;

        loadingEl.setAttribute('aria-hidden', 'false');
        summaryEl.classList.add('visually-hidden');
        summaryEl.setAttribute('aria-hidden', 'true');

        Utils.ajax('/apps/arbeitszeitcheck/api/manager/team-compliance', {
            method: 'GET',
            onSuccess: function(data) {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                if (data.success && data.compliance) {
                    summaryEl.classList.remove('visually-hidden');
                    summaryEl.setAttribute('aria-hidden', 'false');
                    summaryEl.innerHTML = renderTeamComplianceSummary(data.compliance);
                } else {
                    summaryEl.innerHTML = '<p class="team-compliance-empty">' + escapeHtml(t('Unable to load compliance data.', 'Unable to load compliance data.')) + '</p>';
                    summaryEl.classList.remove('visually-hidden');
                }
            },
            onError: function() {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                summaryEl.innerHTML = '<p class="team-compliance-empty">' + escapeHtml(t('Error loading team compliance.', 'Error loading team compliance.')) + '</p>';
                summaryEl.classList.remove('visually-hidden');
            }
        });
    }

    let lastComplianceMembers = [];

    function complianceStatCard(bucket, value, label, tileVariant) {
        const clickable = value > 0;
        const variant = tileVariant ? (' azc-stat-tile--' + tileVariant) : '';
        return (
            '<button type="button" class="azc-stat-tile' + variant + (clickable ? ' team-compliance-stat--clickable' : '') + '"'
            + ' data-compliance-bucket="' + escapeHtml(bucket) + '"'
            + (clickable ? '' : ' disabled')
            + ' aria-label="' + escapeHtml(label + ': ' + value) + '">'
            + '<span class="azc-stat-tile__label">' + escapeHtml(label) + '</span>'
            + '<span class="azc-stat-tile__value">' + escapeHtml(String(value)) + '</span>'
            + '</button>'
        );
    }

    function bindComplianceStatClicks() {
        document.querySelectorAll('.team-compliance-stat--clickable[data-compliance-bucket]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const bucket = btn.getAttribute('data-compliance-bucket');
                openComplianceMemberList(bucket);
            });
        });
    }

    function openComplianceMemberList(bucket) {
        const Components = window.ArbeitszeitCheckComponents;
        if (!Components || !Components.createModal) {
            return;
        }
        const modalId = 'team-compliance-member-list';
        const existing = document.getElementById(modalId);
        if (existing) {
            existing.remove();
        }
        const members = lastComplianceMembers.filter((m) => {
            if (bucket === 'warning') {
                return m.bucket === 'warning';
            }
            if (bucket === 'error') {
                return m.bucket === 'critical';
            }
            if (bucket === 'ok') {
                return m.bucket === 'compliant';
            }
            if (bucket === 'info') {
                return (m.violationCount || 0) > 0;
            }
            return false;
        });
        const titleMap = {
            warning: t('Team members with warnings', 'Team members with warnings'),
            error: t('Team members with critical violations', 'Team members with critical violations'),
            ok: t('Compliant team members', 'Compliant team members'),
            info: t('Team members with violations', 'Team members with violations'),
        };
        let rows = '';
        if (!members.length) {
            rows = '<p class="team-compliance-empty">' + escapeHtml(t('No team members in this category.', 'No team members in this category.')) + '</p>';
        } else {
            rows = '<ul class="team-compliance-member-list">' + members.map((m) => {
                const violationsLabel = t('Violations: %s', 'Violations: %s').replace('%s', String(m.violationCount || 0));
                return '<li class="team-compliance-member-list__item">'
                    + '<span class="team-compliance-member-list__name">' + escapeHtml(m.displayName || m.userId) + '</span>'
                    + '<span class="team-compliance-member-list__meta">' + escapeHtml(violationsLabel) + '</span>'
                    + '<a class="azc-btn azc-btn--sm azc-btn--secondary" href="' + escapeHtml(m.violationsUrl) + '">' + escapeHtml(t('View violations', 'View violations')) + '</a>'
                    + '</li>';
            }).join('') + '</ul>';
        }
        Components.createModal({
            id: modalId,
            title: titleMap[bucket] || t('Team compliance', 'Team compliance'),
            content: '<p class="form-help">' + escapeHtml(t('Open violations for a team member to review details.', 'Open violations for a team member to review details.')) + '</p>' + rows,
            size: 'md',
        });
        Components.openModal(modalId);
    }

    function renderTeamComplianceSummary(c) {
        const total = c.totalMembers || 0;
        const compliant = c.compliantMembers || 0;
        const withWarnings = c.membersWithWarnings || 0;
        const withViolations = c.membersWithViolations || 0;
        const totalViolations = c.totalViolations || 0;
        const hasIssues = withViolations > 0 || withWarnings > 0 || totalViolations > 0;
        lastComplianceMembers = Array.isArray(c.members) ? c.members : [];

        const html = (
            '<div class="azc-stat-strip team-compliance-grid" role="group" aria-label="' + escapeHtml(t('Team compliance summary', 'Team compliance summary')) + '">'
            + complianceStatCard('ok', compliant, t('Compliant', 'Compliant'), 'success')
            + complianceStatCard('warning', withWarnings, t('Warnings', 'Warnings'), 'warning')
            + complianceStatCard('error', withViolations, t('Critical Violations', 'Critical Violations'), 'danger')
            + (totalViolations > 0 ? complianceStatCard('info', totalViolations, t('Total Violations', 'Total Violations'), 'primary') : '')
            + '</div>'
            + (hasIssues
                ? '<p class="team-compliance-note">' + escapeHtml(t('Click a number to see affected team members.', 'Click a number to see affected team members.')) + '</p>'
                : (total > 0
                    ? '<p class="team-compliance-note team-compliance-note--success">' + escapeHtml(t('All team members are compliant.', 'All team members are compliant.')) + '</p>'
                    : '<p class="team-compliance-note">' + escapeHtml(t('No team members.', 'No team members.')) + '</p>'))
        );
        setTimeout(bindComplianceStatClicks, 0);
        return html;
    }

    // ===== AUTO REFRESH =====
    function setupAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(function() {
            loadPendingApprovals();
            loadPendingTimeEntryCorrections();
            loadTeamCompliance();
            loadTeamOvertimeAlerts();
        }, 5 * 60 * 1000);
    }

    function cleanup() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    window.addEventListener('beforeunload', cleanup);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
