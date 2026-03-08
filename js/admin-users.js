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

        // Edit user buttons
        const editButtons = Utils.$$('[data-action="edit-user"]');
        editButtons.forEach(btn => {
            Utils.on(btn, 'click', handleEditUser);
        });
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
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Loading…') : 'Loading…') + '</td></tr>';

        const url = '/apps/arbeitszeitcheck/api/admin/users' + (search ? '?search=' + encodeURIComponent(search) : '');
        
        Utils.ajax(url, {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.users) {
                    renderUsers(data.users);
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading users') : 'Error loading users') + '</td></tr>';
                }
            },
            onError: function(_error) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'Error loading users') : 'Error loading users') + '</td></tr>';
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
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">' + (window.t ? window.t('arbeitszeitcheck', 'No users found') : 'No users found') + '</td></tr>';
            return;
        }

        tbody.innerHTML = users.map(user => `
            <tr data-user-id="${Utils.escapeHtml(user.userId)}">
                <td>${Utils.escapeHtml(user.displayName)}</td>
                <td>${Utils.escapeHtml(user.email || '-')}</td>
                <td>
                    ${user.workingTimeModel 
                        ? Utils.escapeHtml(user.workingTimeModel.name) 
                        : `<span class="text-muted">${(window.t && window.t('arbeitszeitcheck', 'Not assigned')) || window.ArbeitszeitCheck?.l10n?.notAssigned || 'Not assigned'}</span>`}
                </td>
                <td>
                    <span class="badge badge--${user.enabled ? 'success' : 'error'}">
                        ${user.enabled 
                        ? (window.t && window.t('arbeitszeitcheck', 'enabled')) || window.ArbeitszeitCheck?.l10n?.enabled || 'Enabled'
                        : (window.t && window.t('arbeitszeitcheck', 'disabled')) || window.ArbeitszeitCheck?.l10n?.disabled || 'Disabled'}
                    </span>
                </td>
                <td>
                    <button type="button" class="button small" 
                        data-action="edit-user" 
                        data-user-id="${Utils.escapeHtml(user.userId)}">
                        ${(window.t && window.t('arbeitszeitcheck', 'Edit')) || window.ArbeitszeitCheck?.l10n?.edit || 'Edit'}
                    </button>
                </td>
            </tr>
        `).join('');

        // Rebind edit buttons
        const editButtons = Utils.$$('[data-action="edit-user"]');
        editButtons.forEach(btn => {
            Utils.on(btn, 'click', handleEditUser);
        });
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

        // Load available working time models
        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models', {
            method: 'GET',
            onSuccess: function(response) {
                if (response.success && Array.isArray(response.models)) {
                    showEditUserModalWithModels(user, response.models);
                } else {
                    const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to load working time models')) || window.ArbeitszeitCheck?.l10n?.failedToLoadWorkingTimeModels || 'Failed to load working time models';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                Messaging.showError('Failed to load working time models');
            }
        });
    }

    /**
     * Show edit user modal with working time models loaded
     */
    function showEditUserModalWithModels(user, models) {
        const title = (window.ArbeitszeitCheck?.l10n?.editUser || 'Edit User') + ': ' + (user.displayName || user.userId);
        const saveLabel = window.ArbeitszeitCheck?.l10n?.save || 'Save';
        const cancelLabel = window.ArbeitszeitCheck?.l10n?.cancel || 'Cancel';
        const modelLabel = window.ArbeitszeitCheck?.l10n?.workingTimeModel || 'Working Time Model';
        const vacationDaysLabel = window.ArbeitszeitCheck?.l10n?.vacationDaysPerYear || 'Vacation Days Per Year';
        const startDateLabel = window.ArbeitszeitCheck?.l10n?.startDate || 'Start Date';
        const endDateLabel = window.ArbeitszeitCheck?.l10n?.endDate || (window.t && window.t('arbeitszeitcheck', 'End Date (Optional)')) || 'End Date (Optional)';
        const noModelLabel = window.ArbeitszeitCheck?.l10n?.noModel || 'No Model Assigned';

        // Build model options
        let modelOptions = `<option value="">${noModelLabel}</option>`;
        models.forEach(model => {
            const selected = user.workingTimeModel && user.workingTimeModel.id === model.id ? 'selected' : '';
            modelOptions += `<option value="${model.id}" ${selected}>${Utils.escapeHtml(model.name)}</option>`;
        });

        const formContent = `
            <form id="edit-user-form" class="form">
                <input type="hidden" id="user-id" name="userId" value="${Utils.escapeHtml(user.userId)}">
                <div class="form-group">
                    <label for="user-model" class="form-label">${modelLabel}</label>
                    <select id="user-model" name="workingTimeModelId" class="form-select">
                        ${modelOptions}
                    </select>
                    <p class="form-help">Select a work schedule to assign to this employee</p>
                </div>
                <div class="form-group">
                    <label for="user-vacation-days" class="form-label">${vacationDaysLabel}</label>
                    <input type="number" id="user-vacation-days" name="vacationDaysPerYear" class="form-input" 
                           min="0" max="365" value="${user.vacationDaysPerYear || 25}">
                    <p class="form-help">${window.ArbeitszeitCheck?.l10n?.vacationDaysHelp || 'Number of vacation days per year (standard in Germany: 25 days)'}</p>
                </div>
                <div class="form-group">
                    <label for="user-start-date" class="form-label">${startDateLabel}</label>
                    <input type="text" id="user-start-date" name="startDate" class="form-input datepicker-input" 
                           placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10"
                           value="${(user.workingTimeModelStartDate && convertISOToEuropean(user.workingTimeModelStartDate)) || ''}">
                </div>
                <div class="form-group">
                    <label for="user-end-date" class="form-label">${endDateLabel}</label>
                    <input type="text" id="user-end-date" name="endDate" class="form-input datepicker-input" 
                           placeholder="dd.mm.yyyy" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" maxlength="10"
                           value="${(user.workingTimeModelEndDate && convertISOToEuropean(user.workingTimeModelEndDate)) || ''}">
                    <p class="form-help">Leave empty if the assignment has no end date</p>
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
                const modalEl = document.getElementById('edit-user-modal');
                if (modalEl && modalEl.parentNode) {
                    modalEl.parentNode.remove();
                }
            }
        });

        Components.openModal('edit-user-modal');

        // Init datepickers on dynamically added inputs
        const dp = window.ArbeitszeitCheckDatepicker;
        if (dp && dp.initializeDatepicker) {
            const startEl = document.getElementById('user-start-date');
            const endEl = document.getElementById('user-end-date');
            if (startEl) dp.initializeDatepicker(startEl, {});
            if (endEl) dp.initializeDatepicker(endEl, {});
        }

        // Handle form submission
        const form = document.getElementById('edit-user-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleUpdateUser(form, user.userId);
            });
        }

        // Handle cancel button
        const cancelBtn = modal.querySelector('[data-action="close-modal"]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                Components.closeModal(modal);
            });
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
            endDate: toISO(formData.get('endDate') || '') || null
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
