/**
 * Admin Users JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Components = window.ArbeitszeitCheckComponents || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    let searchTimeout = null;

    /**
     * Initialize users page
     */
    function init() {
        bindEvents();
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const searchInput = Utils.$('#user-search');
        if (searchInput) {
            Utils.on(searchInput, 'input', handleSearch);
        }

        const refreshBtn = Utils.$('#refresh-users');
        if (refreshBtn) {
            Utils.on(refreshBtn, 'click', loadUsers);
        }

        const editButtons = Utils.$$('[data-action="edit-user"]');
        editButtons.forEach(btn => { Utils.on(btn, 'click', handleEditUser); });
        const historyButtons = Utils.$$('[data-action="history-user"]');
        historyButtons.forEach(btn => { Utils.on(btn, 'click', handleHistoryUser); });
    }

    /**
     * Handle search input
     */
    function handleSearch(e) {
        const query = e.target.value.trim();

        // Debounce search
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadUsers(query);
        }, 300);
    }

    /**
     * Load users from API
     */
    function loadUsers(search = '') {
        const tbody = Utils.$('#users-tbody');
        if (!tbody) return;

        // Show loading
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Loading…') : 'Loading…') + '</td></tr>';

        const url = '/apps/arbeitszeitcheck/api/admin/users' + (search ? '?search=' + encodeURIComponent(search) : '');
        
        Utils.ajax(url, {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.users) {
                    renderUsers(data.users);
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading users') : 'Error loading users') + '</td></tr>';
                }
            },
            onError: function(_error) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading users') : 'Error loading users') + '</td></tr>';
                if (Messaging && Messaging.showError) {
                    Messaging.showError(window.t ? window.t('arbeitszeitcheck', 'Failed to load users. Please try again.') : 'Failed to load users. Please try again.');
                }
            }
        });
    }

    /**
     * Render users table
     */
    function renderUsers(users) {
        const tbody = Utils.$('#users-tbody');
        if (!tbody) return;

        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'No users found') : 'No users found') + '</td></tr>';
            return;
        }

        const formatDate = (iso) => {
            if (!iso) return '-';
            const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return m ? m[3] + '.' + m[2] + '.' + m[1] : iso;
        };
        const ongoingLabel = (window.t && window.t('arbeitszeitcheck', 'ongoing')) || window.ArbeitszeitCheck?.l10n?.ongoing || 'ongoing';

        tbody.innerHTML = users.map(user => {
            const vacation = user.vacationDaysPerYear != null ? String(user.vacationDaysPerYear) : '-';
            const start = user.workingTimeModelStartDate || null;
            const end = user.workingTimeModelEndDate || null;
            const validity = start ? (formatDate(start) + (end ? ' – ' + formatDate(end) : ' – ' + ongoingLabel)) : '-';
            return `
            <tr data-user-id="${Utils.escapeHtml(user.userId)}">
                <td>${Utils.escapeHtml(user.displayName)}</td>
                <td>${Utils.escapeHtml(user.email || '-')}</td>
                <td>
                    ${user.workingTimeModel 
                        ? Utils.escapeHtml(user.workingTimeModel.name) 
                        : `<span class="text-muted">${(window.t && window.t('arbeitszeitcheck', 'Not assigned')) || window.ArbeitszeitCheck?.l10n?.notAssigned || 'Not assigned'}</span>`}
                </td>
                <td>${Utils.escapeHtml(vacation)}</td>
                <td>${Utils.escapeHtml(validity)}</td>
                <td>
                    <span class="badge badge--${user.enabled ? 'success' : 'error'}">
                        ${user.enabled 
                        ? (window.t && window.t('arbeitszeitcheck', 'enabled')) || window.ArbeitszeitCheck?.l10n?.enabled || 'Enabled'
                        : (window.t && window.t('arbeitszeitcheck', 'disabled')) || window.ArbeitszeitCheck?.l10n?.disabled || 'Disabled'}
                    </span>
                </td>
                <td>
                    <div class="user-actions" role="group" aria-label="${(window.t && window.t('arbeitszeitcheck', 'Actions')) || 'Actions'}">
                        <button type="button" class="btn btn--sm btn--tertiary" 
                            data-action="history-user" 
                            data-user-id="${Utils.escapeHtml(user.userId)}"
                            data-user-name="${Utils.escapeHtml(user.displayName || user.userId)}">
                            ${(window.t && window.t('arbeitszeitcheck', 'History')) || window.ArbeitszeitCheck?.l10n?.history || 'History'}
                        </button>
                        <button type="button" class="btn btn--sm btn--secondary" 
                            data-action="edit-user" 
                            data-user-id="${Utils.escapeHtml(user.userId)}">
                            ${(window.t && window.t('arbeitszeitcheck', 'Edit')) || window.ArbeitszeitCheck?.l10n?.edit || 'Edit'}
                        </button>
                    </div>
                </td>
            </tr>
        `;
        }).join('');

        // Rebind edit buttons
        const editButtons = Utils.$$('[data-action="edit-user"]');
        editButtons.forEach(btn => {
            Utils.on(btn, 'click', handleEditUser);
        });
    }

    /**
     * Handle history user
     */
    function handleHistoryUser(e) {
        const btn = e.currentTarget;
        const userId = btn.dataset.userId;
        const userName = btn.dataset.userName || userId;
        if (!userId) return;
        showHistoryModal(userId, userName);
    }

    /**
     * Handle edit user
     */
    function handleEditUser(e) {
        const userId = e.target.dataset.userId;
        if (!userId) return;

        // Load user details and show modal
        Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId), {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.user) {
                    showEditUserModal(data.user);
                } else {
                    const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to load user details')) || window.ArbeitszeitCheck?.l10n?.failedToLoadUserDetails || 'Failed to load user details';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                Messaging.showError('Failed to load user details');
            }
        });
    }

    /**
     * Show edit user modal
     */
    function showEditUserModal(user) {
        if (!user || !user.userId) {
            const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Invalid user data')) || window.ArbeitszeitCheck?.l10n?.invalidUserData || 'Invalid user data';
            Messaging.showError(errorMsg);
            return;
        }
        const models = Array.isArray(user.availableWorkingTimeModels) ? user.availableWorkingTimeModels : [];
        showEditUserModalWithModels(user, models);
    }

    /**
     * Show history modal for a user
     */
    function showHistoryModal(userId, userName) {
        const t = (s) => window.ArbeitszeitCheck?.l10n?.[s] || s;
        const title = t('assignmentHistory') + ': ' + (userName || userId);
        const closeLabel = t('close') || 'Close';
        const loadingText = t('loading') + '…';

        const content = `
            <p class="history-modal__loading" id="history-modal-loading">${loadingText}</p>
            <div id="history-modal-content" class="history-modal__content" style="display:none;"></div>
        `;

        const modal = Components.createModal({
            id: 'history-modal',
            title: title,
            content: content,
            size: 'md',
            closable: true,
            onClose: function() {
                const el = document.getElementById('history-modal');
                if (el && el.parentNode) el.parentNode.remove();
            }
        });

        Components.openModal('history-modal');

        const closeBtn = modal.querySelector('[data-action="close-modal"]');
        if (!closeBtn && modal.querySelector('.modal-close')) {
            modal.querySelector('.modal-close').setAttribute('aria-label', closeLabel);
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId) + '/working-time-model/history', {
            method: 'GET',
            onSuccess: function(data) {
                const loadingEl = document.getElementById('history-modal-loading');
                const contentEl = document.getElementById('history-modal-content');
                if (!loadingEl || !contentEl) return;
                loadingEl.style.display = 'none';
                if (data.success && Array.isArray(data.history) && data.history.length > 0) {
                    const formatDate = (iso) => {
                        if (!iso) return '–';
                        const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
                        return m ? m[3] + '.' + m[2] + '.' + m[1] : iso;
                    };
                    const workScheduleHdr = Utils.escapeHtml(t('workSchedule'));
                    const vacationDaysHdr = Utils.escapeHtml(t('vacationDaysCol'));
                    const validFromHdr = Utils.escapeHtml(t('validFrom'));
                    const validToHdr = Utils.escapeHtml(t('validTo'));
                    const statusHdr = Utils.escapeHtml(t('status'));
                    const ongoingVal = Utils.escapeHtml(t('ongoing'));
                    const activeVal = Utils.escapeHtml(t('active'));
                    const endedVal = Utils.escapeHtml(t('ended'));
                    const rows = data.history.map(item => {
                        const model = Utils.escapeHtml(item.modelName);
                        const vacation = String(item.vacationDaysPerYear);
                        const from = formatDate(item.startDate);
                        const to = formatDate(item.endDate) || ongoingVal;
                        const status = item.isActive
                            ? '<span class="badge badge--success">' + activeVal + '</span>'
                            : '<span class="badge">' + endedVal + '</span>';
                        return '<tr><td>' + model + '</td><td>' + vacation + '</td><td>' + from + '</td><td>' + to + '</td><td>' + status + '</td></tr>';
                    }).join('');
                    contentEl.innerHTML = '<div class="table-responsive" role="region" aria-label="' + Utils.escapeHtml(t('assignmentHistory')) + '">' +
                        '<table class="table history-modal__table" role="table" aria-label="' + Utils.escapeHtml(t('assignmentHistory')) + '">' +
                        '<thead><tr>' +
                        '<th scope="col">' + workScheduleHdr + '</th>' +
                        '<th scope="col">' + vacationDaysHdr + '</th>' +
                        '<th scope="col">' + validFromHdr + '</th>' +
                        '<th scope="col">' + validToHdr + '</th>' +
                        '<th scope="col">' + statusHdr + '</th>' +
                        '</tr></thead><tbody>' + rows + '</tbody></table></div>';
                } else {
                    contentEl.innerHTML = '<p class="history-modal__empty">' + Utils.escapeHtml(t('noAssignmentHistory')) + '</p>';
                }
                contentEl.style.display = 'block';
            },
            onError: function() {
                const loadingEl = document.getElementById('history-modal-loading');
                const contentEl = document.getElementById('history-modal-content');
                if (!loadingEl || !contentEl) return;
                loadingEl.style.display = 'none';
                contentEl.innerHTML = '<p class="history-modal__empty">' + t('error') + '</p>';
                contentEl.style.display = 'block';
            }
        });
    }

    /**
     * Show edit user modal with working time models loaded
     */
    function showEditUserModalWithModels(user, models) {
        const t = (s) => window.ArbeitszeitCheck?.l10n?.[s] || s;
        const title = t('editUser') + ': ' + (user.displayName || user.userId);
        const saveLabel = t('save');
        const cancelLabel = t('cancel');
        const modelLabel = t('workingTimeModel');
        const vacationDaysLabel = t('vacationDaysPerYear');
        const startDateLabel = t('startDate');
        const endDateLabel = t('endDateOptional');
        const noModelLabel = t('noModel');
        const germanStateLabel = t('germanStateLabel');
        const germanStateHelp = t('germanStateHelp');
        const germanStateDefault = t('germanStateDefault');

        const vacation = user.vacationDaysPerYear ?? user.userWorkingTimeModel?.vacationDaysPerYear ?? 25;
        const startIso = user.workingTimeModelStartDate ?? user.userWorkingTimeModel?.startDate ?? null;
        const endIso = user.workingTimeModelEndDate ?? user.userWorkingTimeModel?.endDate ?? null;
        const startVal = (startIso && convertISOToEuropean(startIso)) || '';
        const endVal = (endIso && convertISOToEuropean(endIso)) || '';
        const currentState = user.germanState || '';

        let modelOptions = `<option value="">${noModelLabel}</option>`;
        models.forEach(model => {
            const selected = user.workingTimeModel && user.workingTimeModel.id === model.id ? 'selected' : '';
            modelOptions += `<option value="${model.id}" ${selected}>${Utils.escapeHtml(model.name)}</option>`;
        });

        const states = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.states) || [];
        let stateOptions = `<option value="">${Utils.escapeHtml(germanStateDefault)}</option>`;
        states.forEach(state => {
            const selected = currentState === state.code ? 'selected' : '';
            stateOptions += `<option value="${Utils.escapeHtml(state.code)}" ${selected}>${Utils.escapeHtml(state.label)}</option>`;
        });

        const formContent = `
            <form id="edit-user-form" class="form">
                <input type="hidden" id="user-id" name="userId" value="${Utils.escapeHtml(user.userId)}">
                <div class="form-group">
                    <label for="user-model" class="form-label">${modelLabel}</label>
                    <select id="user-model" name="workingTimeModelId" class="form-select" aria-describedby="user-model-help">
                        ${modelOptions}
                    </select>
                    <p id="user-model-help" class="form-help">${t('selectWorkScheduleHelp')}</p>
                </div>
                <div class="form-group">
                    <label for="user-german-state" class="form-label">${germanStateLabel}</label>
                    <select id="user-german-state" name="germanState" class="form-select" aria-describedby="user-german-state-help">
                        ${stateOptions}
                    </select>
                    <p id="user-german-state-help" class="form-help">${germanStateHelp}</p>
                </div>
                <div class="form-group">
                    <label for="user-vacation-days" class="form-label">${vacationDaysLabel}</label>
                    <input type="number" id="user-vacation-days" name="vacationDaysPerYear" class="form-input" min="0" max="365" value="${vacation}" aria-describedby="user-vacation-help">
                    <p id="user-vacation-help" class="form-help">${t('vacationDaysHelp')}</p>
                </div>
                <div class="form-group">
                    <label for="user-start-date" class="form-label">${startDateLabel}</label>
                    <input type="text" id="user-start-date" name="startDate" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${startVal}" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="user-end-date" class="form-label">${endDateLabel}</label>
                    <input type="text" id="user-end-date" name="endDate" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10" value="${endVal}" autocomplete="off">
                    <p class="form-help">${t('endDateHelp')}</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn--secondary" data-action="close-modal">${cancelLabel}</button>
                    <button type="submit" class="btn btn--primary">${saveLabel}</button>
                </div>
            </form>
        `;

        const modal = Components.createModal({
            id: 'edit-user-modal',
            title: title,
            content: formContent,
            size: 'md',
            closable: true,
            onClose: function() {
                const el = document.getElementById('edit-user-modal');
                if (el && el.parentNode) el.parentNode.remove();
            }
        });

        Components.openModal('edit-user-modal');

        const dp = window.ArbeitszeitCheckDatepicker;
        if (dp && dp.initializeDatepicker) {
            const startEl = document.getElementById('user-start-date');
            const endEl = document.getElementById('user-end-date');
            if (startEl) dp.initializeDatepicker(startEl, {});
            if (endEl) dp.initializeDatepicker(endEl, {});
        }

        const form = document.getElementById('edit-user-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleUpdateUser(form, user.userId);
            });
        }

        const cancelBtn = modal.querySelector('[data-action="close-modal"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() { Components.closeModal(modal); });
        }
    }

    /**
     * Handle update user form submission
     */
    function convertISOToEuropean(s) {
        if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        const p = s.split('-');
        return p[2] + '.' + p[1] + '.' + p[0];
    }

    function handleUpdateUser(form, userId) {
        const formData = new FormData(form);
        const dp = window.ArbeitszeitCheckDatepicker;
        const toISO = dp ? dp.convertEuropeanToISO : function(s) { return s; };
        const data = {
            workingTimeModelId: formData.get('workingTimeModelId') ? parseInt(formData.get('workingTimeModelId')) : null,
            vacationDaysPerYear: formData.get('vacationDaysPerYear') ? parseInt(formData.get('vacationDaysPerYear')) : null,
            startDate: toISO(formData.get('startDate') || '') || null,
            endDate: toISO(formData.get('endDate') || '') || null,
            germanState: (formData.get('germanState') || '').toString()
        };

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/users/' + encodeURIComponent(userId) + '/working-time-model', {
            method: 'PUT',
            data: data,
            onSuccess: function(response) {
                if (response.success) {
                    const successMsg = window.ArbeitszeitCheck?.l10n?.userUpdated || 'User updated successfully';
                    Messaging.showSuccess(successMsg);
                    Components.closeModal(document.getElementById('edit-user-modal'));
                    // Reload users list
                    loadUsers();
                } else {
                    const errorMsg = response.error || (window.t && window.t('arbeitszeitcheck', 'Failed to update user')) || window.ArbeitszeitCheck?.l10n?.failedToUpdateUser || 'Failed to update user';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to update user')) || window.ArbeitszeitCheck?.l10n?.failedToUpdateUser || 'Failed to update user';
                Messaging.showError(errorMsg);
            }
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
