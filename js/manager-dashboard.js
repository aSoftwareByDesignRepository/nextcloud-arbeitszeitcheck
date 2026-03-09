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
    const l10n = window.ArbeitszeitCheck?.l10n || {};

    let refreshInterval = null;

    function t(key, fallback) {
        return (l10n[key] !== undefined ? l10n[key] : (typeof window.t === 'function' ? window.t('arbeitszeitcheck', fallback || key) : fallback || key));
    }

    function escapeHtml(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function init() {
        setupTabs();
        loadPendingApprovals();
        loadPendingTimeEntryCorrections();
        loadTeamCompliance();
        setupAutoRefresh();
    }

    // ===== TABS =====
    function setupTabs() {
        const tabAbsences = document.getElementById('tab-absences');
        const tabTimeEntries = document.getElementById('tab-time-entries');
        const panelAbsences = document.getElementById('pending-absences-panel');
        const panelTimeEntries = document.getElementById('pending-time-entries-panel');
        if (!tabAbsences || !tabTimeEntries || !panelAbsences || !panelTimeEntries) return;

        tabAbsences.addEventListener('click', function() {
            tabAbsences.classList.add('pending-approvals-tab--active');
            tabAbsences.setAttribute('aria-selected', 'true');
            tabTimeEntries.classList.remove('pending-approvals-tab--active');
            tabTimeEntries.setAttribute('aria-selected', 'false');
            panelAbsences.classList.remove('pending-approvals-panel--hidden');
            panelAbsences.setAttribute('aria-hidden', 'false');
            panelTimeEntries.classList.add('pending-approvals-panel--hidden');
            panelTimeEntries.setAttribute('aria-hidden', 'true');
        });
        tabTimeEntries.addEventListener('click', function() {
            tabTimeEntries.classList.add('pending-approvals-tab--active');
            tabTimeEntries.setAttribute('aria-selected', 'true');
            tabAbsences.classList.remove('pending-approvals-tab--active');
            tabAbsences.setAttribute('aria-selected', 'false');
            panelTimeEntries.classList.remove('pending-approvals-panel--hidden');
            panelTimeEntries.setAttribute('aria-hidden', 'false');
            panelAbsences.classList.add('pending-approvals-panel--hidden');
            panelAbsences.setAttribute('aria-hidden', 'true');
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
        const s = item.summary || {};
        const typeLabel = s.type || item.type || 'absence';
        const start = s.start_date || s.startDate || '';
        const end = s.end_date || s.endDate || '';
        const days = s.days != null ? s.days : '';
        const id = item.id;
        const displayName = escapeHtml(item.displayName || item.userId || '');
        const typeDisplay = escapeHtml(typeLabel);
        return (
            '<div class="pending-approval-card pending-approval-card--absence" data-absence-id="' + escapeHtml(String(id)) + '" role="article">' +
            '  <div class="pending-approval-card__body">' +
            '    <p class="pending-approval-card__title"><strong>' + displayName + '</strong> – ' + typeDisplay + '</p>' +
            '    <p class="pending-approval-card__meta">' + escapeHtml(start) + ' – ' + escapeHtml(end) + (days ? ' (' + escapeHtml(String(days)) + ' ' + t('days', 'days') + ')' : '') + '</p>' +
            '    <div class="pending-approval-card__actions">' +
            '      <button type="button" class="btn btn--primary btn-approve-absence" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Approve', 'Approve') + ' ' + displayName + '">' + t('Approve', 'Approve') + '</button>' +
            '      <button type="button" class="btn btn--secondary btn-reject-absence" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Reject', 'Reject') + ' ' + displayName + '">' + t('Reject', 'Reject') + '</button>' +
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
        const url = '/apps/arbeitszeitcheck/api/manager/absences/' + absenceId + '/approve';
        Utils.ajax(url, {
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
            '  <button type="button" class="btn btn--secondary btn-reject-modal-cancel">' + t('Cancel', 'Cancel') + '</button>',
            '  <button type="button" class="btn btn--primary btn-reject-modal-confirm">' + t('Confirm rejection', 'Confirm rejection') + '</button>',
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
        const url = '/apps/arbeitszeitcheck/api/manager/absences/' + absenceId + '/reject';
        Utils.ajax(url, {
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
                    emptyEl.textContent = t('No pending time entry corrections.', 'No pending time entry corrections.');
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
                emptyEl.textContent = t('Error loading pending time entry corrections.', 'Error loading pending time entry corrections.');
            }
        });
    }

    function renderTimeEntryCard(item) {
        const s = item.summary || {};
        const id = item.id;
        const displayName = escapeHtml(item.displayName || item.userId || '');
        const date = s.date || '';
        const startTime = s.startTime || '';
        const endTime = s.endTime || '';
        const durationHours = s.durationHours != null ? s.durationHours : '';
        const justification = s.justification ? escapeHtml(String(s.justification).substring(0, 200)) : '';
        const orig = s.original || {};
        const prop = s.proposed || {};
        const origStr = orig.date || orig.startTime || orig.endTime ? [orig.date, orig.startTime || orig.start, orig.endTime || orig.end].filter(Boolean).join(' ') : '';
        const propStr = prop.date || prop.startTime || prop.start || prop.endTime || prop.end ? [prop.date, prop.startTime || prop.start, prop.endTime || prop.end].filter(Boolean).join(' ') : '';

        return (
            '<div class="pending-approval-card pending-approval-card--time-entry" data-time-entry-id="' + escapeHtml(String(id)) + '" role="article">' +
            '  <div class="pending-approval-card__body">' +
            '    <p class="pending-approval-card__title"><strong>' + displayName + '</strong> – ' + escapeHtml(t('Time entry correction', 'Time entry correction')) + '</p>' +
            '    <p class="pending-approval-card__meta">' + escapeHtml(date) + ' ' + escapeHtml(startTime) + ' – ' + escapeHtml(endTime) + (durationHours ? ' (' + escapeHtml(String(durationHours)) + 'h)' : '') + '</p>' +
            (justification ? '<p class="pending-approval-card__reason"><em>' + justification + (String(s.justification).length > 200 ? '…' : '') + '</em></p>' : '') +
            (origStr || propStr ? '<p class="pending-approval-card__meta pending-approval-card__meta--small">' + (origStr ? t('Original:', 'Original:') + ' ' + escapeHtml(origStr) : '') + (propStr ? ' → ' + t('Proposed:', 'Proposed:') + ' ' + escapeHtml(propStr) : '') + '</p>' : '') +
            '    <div class="pending-approval-card__actions">' +
            '      <button type="button" class="btn btn--primary btn-approve-time-entry" data-time-entry-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Approve', 'Approve') + ' ' + displayName + '">' + t('Approve', 'Approve') + '</button>' +
            '      <button type="button" class="btn btn--secondary btn-reject-time-entry" data-time-entry-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Reject', 'Reject') + ' ' + displayName + '">' + t('Reject', 'Reject') + '</button>' +
            '    </div>' +
            '  </div>' +
            '</div>'
        );
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
        const url = '/apps/arbeitszeitcheck/api/manager/time-entries/' + timeEntryId + '/approve-correction';
        Utils.ajax(url, {
            method: 'POST',
            data: {},
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Time entry correction approved successfully', 'Time entry correction approved successfully'));
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                    loadTeamCompliance();
                } else {
                    Messaging.showError(data.error || t('Failed to approve.', 'Failed to approve.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to approve time entry correction.', 'Failed to approve time entry correction.'));
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
            '  <button type="button" class="btn btn--secondary btn-reject-modal-cancel">' + t('Cancel', 'Cancel') + '</button>',
            '  <button type="button" class="btn btn--primary btn-reject-modal-confirm">' + t('Confirm rejection', 'Confirm rejection') + '</button>',
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
        const url = '/apps/arbeitszeitcheck/api/manager/time-entries/' + timeEntryId + '/reject-correction';
        Utils.ajax(url, {
            method: 'POST',
            data: { reason: reason || '' },
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Time entry correction rejected', 'Time entry correction rejected'));
                    removeTimeEntryCard(timeEntryId);
                    loadPendingTimeEntryCorrections();
                } else {
                    Messaging.showError(data.error || t('Failed to reject.', 'Failed to reject.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to reject time entry correction.', 'Failed to reject time entry correction.'));
            }
        });
    }

    function removeTimeEntryCard(timeEntryId) {
        const card = document.querySelector('.pending-approval-card[data-time-entry-id="' + timeEntryId + '"]');
        if (card) card.remove();
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

    function renderTeamComplianceSummary(c) {
        const total = c.totalMembers || 0;
        const compliant = c.compliantMembers || 0;
        const withWarnings = c.membersWithWarnings || 0;
        const withViolations = c.membersWithViolations || 0;
        const totalViolations = c.totalViolations || 0;
        const hasIssues = withViolations > 0 || withWarnings > 0 || totalViolations > 0;

        return (
            '<div class="team-compliance-grid">' +
            '  <div class="team-compliance-stat team-compliance-stat--ok">' +
            '    <span class="team-compliance-stat__value">' + escapeHtml(String(compliant)) + '</span>' +
            '    <span class="team-compliance-stat__label">' + t('Compliant', 'Compliant') + '</span>' +
            '  </div>' +
            '  <div class="team-compliance-stat team-compliance-stat--warning' + (withWarnings > 0 ? ' team-compliance-stat--has-issues' : '') + '">' +
            '    <span class="team-compliance-stat__value">' + escapeHtml(String(withWarnings)) + '</span>' +
            '    <span class="team-compliance-stat__label">' + t('Warnings', 'Warnings') + '</span>' +
            '  </div>' +
            '  <div class="team-compliance-stat team-compliance-stat--error' + (withViolations > 0 ? ' team-compliance-stat--has-issues' : '') + '">' +
            '    <span class="team-compliance-stat__value">' + escapeHtml(String(withViolations)) + '</span>' +
            '    <span class="team-compliance-stat__label">' + t('Critical Violations', 'Critical Violations') + '</span>' +
            '  </div>' +
            (totalViolations > 0 ? '<div class="team-compliance-stat team-compliance-stat--info">' +
            '    <span class="team-compliance-stat__value">' + escapeHtml(String(totalViolations)) + '</span>' +
            '    <span class="team-compliance-stat__label">' + t('Total Violations', 'Total Violations') + '</span>' +
            '  </div>' : '') +
            '</div>' +
            (hasIssues ? '<p class="team-compliance-note">' + escapeHtml(t('Some team members have compliance issues. Check the Compliance section for details.', 'Some team members have compliance issues. Check the Compliance section for details.')) + '</p>' : (total > 0 ? '<p class="team-compliance-note team-compliance-note--success">' + escapeHtml(t('All team members are compliant.', 'All team members are compliant.')) + '</p>' : '<p class="team-compliance-note">' + escapeHtml(t('No team members.', 'No team members.')) + '</p>'))
        );
    }

    // ===== AUTO REFRESH =====
    function setupAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(function() {
            loadPendingApprovals();
            loadPendingTimeEntryCorrections();
            loadTeamCompliance();
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
