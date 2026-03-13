/**
 * Substitution requests (Vertretungs-Freigabe) JavaScript
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

    function t(key, fallback) {
        return (l10n[key] !== undefined ? l10n[key] : (typeof window.t === 'function' ? window.t('arbeitszeitcheck', fallback || key) : fallback || key));
    }

    function escapeHtml(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function loadRequests() {
        const loadingEl = document.getElementById('substitution-requests-loading');
        const itemsEl = document.getElementById('substitution-requests-items');
        const emptyEl = document.getElementById('substitution-requests-empty');
        if (!loadingEl || !itemsEl || !emptyEl) return;

        loadingEl.classList.remove('visually-hidden');
        loadingEl.setAttribute('aria-hidden', 'false');
        loadingEl.setAttribute('aria-busy', 'true');
        itemsEl.setAttribute('aria-hidden', 'true');
        emptyEl.classList.add('visually-hidden');

        const apiList = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.substitutionApi && window.ArbeitszeitCheck.substitutionApi.list) || '/apps/arbeitszeitcheck/api/substitution-requests';
        Utils.ajax(apiList, {
            method: 'GET',
            onSuccess: function(data) {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                loadingEl.setAttribute('aria-busy', 'false');
                const list = (data && data.requests) ? data.requests : [];
                if (list.length === 0) {
                    emptyEl.classList.remove('visually-hidden');
                    itemsEl.innerHTML = '';
                    itemsEl.setAttribute('aria-hidden', 'true');
                } else {
                    emptyEl.classList.add('visually-hidden');
                    itemsEl.setAttribute('aria-hidden', 'false');
                    itemsEl.innerHTML = list.map(renderCard).join('');
                    bindButtons();
                }
            },
            onError: function() {
                loadingEl.classList.add('visually-hidden');
                loadingEl.setAttribute('aria-hidden', 'true');
                loadingEl.setAttribute('aria-busy', 'false');
                emptyEl.classList.remove('visually-hidden');
                emptyEl.querySelector('p').textContent = t('Error loading substitution requests.', 'Error loading substitution requests.');
                emptyEl.querySelector('.substitution-requests-empty__hint')?.remove();
            }
        });
    }

    function renderCard(req) {
        const id = req.id;
        const displayName = escapeHtml(req.displayName || req.userId || '');
        const typeLabel = escapeHtml(req.typeLabel || req.type || '');
        const start = req.startDate || '';
        const end = req.endDate || '';
        const days = req.days != null ? req.days : '';
        const dayLabel = (days === 1 || days === 1.0) ? t('day', 'day') : t('days', 'days');
        return (
            '<div class="substitution-request-card" data-absence-id="' + escapeHtml(String(id)) + '" role="article">' +
            '  <div class="substitution-request-card__body">' +
            '    <p class="substitution-request-card__title"><strong>' + displayName + '</strong> ' + t('asks you to cover', 'asks you to cover') + '</p>' +
            '    <p class="substitution-request-card__meta">' + typeLabel + ': ' + escapeHtml(start) + ' – ' + escapeHtml(end) + (days !== '' ? ' (' + escapeHtml(String(days)) + ' ' + dayLabel + ')' : '') + '</p>' +
            '    <div class="substitution-request-card__actions">' +
            '      <button type="button" class="btn btn--primary btn-approve-substitution" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Approve', 'Approve') + ' ' + displayName + '">' + t('Approve', 'Approve') + '</button>' +
            '      <button type="button" class="btn btn--secondary btn-decline-substitution" data-absence-id="' + escapeHtml(String(id)) + '" aria-label="' + t('Decline', 'Decline') + ' ' + displayName + '">' + t('Decline', 'Decline') + '</button>' +
            '    </div>' +
            '  </div>' +
            '</div>'
        );
    }

    function bindButtons() {
        document.querySelectorAll('.btn-approve-substitution').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-absence-id');
                if (id) approveRequest(parseInt(id, 10));
            });
        });
        document.querySelectorAll('.btn-decline-substitution').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-absence-id');
                if (id) showDeclineModal(parseInt(id, 10));
            });
        });
    }

    function approveRequest(absenceId) {
        const base = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.substitutionApi && window.ArbeitszeitCheck.substitutionApi.approve) || '/apps/arbeitszeitcheck/api/substitution-requests/' + absenceId + '/approve';
        const url = base.replace('__ID__', String(absenceId));
        Utils.ajax(url, {
            method: 'POST',
            data: {},
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Substitution request approved.', 'Substitution request approved.'));
                    removeCard(absenceId);
                    loadRequests();
                } else {
                    Messaging.showError(data.error || t('Failed to approve.', 'Failed to approve.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to approve substitution request.', 'Failed to approve substitution request.'));
            }
        });
    }

    function showDeclineModal(absenceId) {
        const modalId = 'decline-substitution-modal-' + absenceId;
        if (document.getElementById(modalId)) {
            Components.openModal(modalId);
            return;
        }
        const textareaId = 'decline-substitution-reason-' + absenceId;
        const content = [
            '<p class="reject-modal-desc" id="decline-substitution-desc-' + absenceId + '">' + escapeHtml(t('Optional reason for declining (leave empty for none):', 'Optional reason for declining (leave empty for none):')) + '</p>',
            '<label for="' + textareaId + '" class="visually-hidden">' + escapeHtml(t('Reason for declining (optional)', 'Reason for declining (optional)')) + '</label>',
            '<textarea id="' + textareaId + '" rows="3" class="reject-modal-textarea" placeholder="' + escapeHtml(t('Enter reason for declining...', 'Enter reason for declining...')) + '" maxlength="1000" aria-describedby="decline-substitution-desc-' + absenceId + '"></textarea>',
            '<div class="reject-modal-actions">',
            '  <button type="button" class="btn btn--secondary btn-reject-modal-cancel">' + t('Cancel', 'Cancel') + '</button>',
            '  <button type="button" class="btn btn--primary btn-reject-modal-confirm">' + t('Confirm decline', 'Confirm decline') + '</button>',
            '</div>'
        ].join('');
        const modal = Components.createModal({
            id: modalId,
            title: t('Decline substitution', 'Decline substitution'),
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
            declineRequest(absenceId, comment);
        });
        Components.openModal(modalId);
        if (textarea) setTimeout(function() { textarea.focus(); }, 100);
    }

    function declineRequest(absenceId, comment) {
        const base = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.substitutionApi && window.ArbeitszeitCheck.substitutionApi.decline) || '/apps/arbeitszeitcheck/api/substitution-requests/' + absenceId + '/decline';
        const url = base.replace('__ID__', String(absenceId));
        Utils.ajax(url, {
            method: 'POST',
            data: { comment: comment || '' },
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(t('Substitution request declined.', 'Substitution request declined.'));
                    removeCard(absenceId);
                    loadRequests();
                } else {
                    Messaging.showError(data.error || t('Failed to decline.', 'Failed to decline.'));
                }
            },
            onError: function() {
                Messaging.showError(t('Failed to decline substitution request.', 'Failed to decline substitution request.'));
            }
        });
    }

    function removeCard(absenceId) {
        const card = document.querySelector('.substitution-request-card[data-absence-id="' + absenceId + '"]');
        if (card) card.remove();
    }

    function init() {
        loadRequests();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
