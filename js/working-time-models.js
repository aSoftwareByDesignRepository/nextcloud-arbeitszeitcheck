/**
 * Working Time Models JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Components = window.ArbeitszeitCheckComponents || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    /**
     * Initialize models page
     */
    function init() {
        bindEvents();
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const createBtn = Utils.$('#create-model');
        if (createBtn) {
            Utils.on(createBtn, 'click', showCreateModal);
        }

        const editButtons = Utils.$$('[data-action="edit-model"]');
        editButtons.forEach(btn => {
            Utils.on(btn, 'click', handleEditModel);
        });

        const deleteButtons = Utils.$$('[data-action="delete-model"]');
        deleteButtons.forEach(btn => {
            Utils.on(btn, 'click', handleDeleteModel);
        });
    }

    /**
     * Show create model modal
     */
    function showCreateModal() {
        const t = (s) => (window.t ? window.t('arbeitszeitcheck', s) : s);
        const title = window.ArbeitszeitCheck?.l10n?.createModel || t('Create Working Time Model');
        const createLabel = window.ArbeitszeitCheck?.l10n?.create || t('Create');
        const cancelLabel = window.ArbeitszeitCheck?.l10n?.cancel || t('Cancel');
        const nameLabel = window.ArbeitszeitCheck?.l10n?.name || t('Name');
        const descriptionLabel = window.ArbeitszeitCheck?.l10n?.description || t('Description');
        const typeLabel = window.ArbeitszeitCheck?.l10n?.type || t('Type');
        const weeklyHoursLabel = window.ArbeitszeitCheck?.l10n?.weeklyHours || t('Weekly Hours');
        const dailyHoursLabel = window.ArbeitszeitCheck?.l10n?.dailyHours || t('Daily Hours');
        const isDefaultLabel = window.ArbeitszeitCheck?.l10n?.isDefault || t('Set as Default');
        
        const formContent = `
            <form id="create-model-form" class="form">
                <div class="form-group">
                    <label for="model-name" class="form-label">${nameLabel} <span class="form-required">*</span></label>
                    <input type="text" id="model-name" name="name" class="form-input" required 
                           placeholder="${nameLabel}" aria-describedby="model-name-help">
                    <p id="model-name-help" class="form-help">${window.ArbeitszeitCheck?.l10n?.modelNameHelp || 'Enter a name for this work schedule (e.g., "Full-Time", "Part-Time")'}</p>
                </div>
                <div class="form-group">
                    <label for="model-description" class="form-label">${descriptionLabel}</label>
                    <textarea id="model-description" name="description" class="form-textarea" rows="3"
                              placeholder="${descriptionLabel}"></textarea>
                </div>
                <div class="form-group">
                    <label for="model-type" class="form-label">${typeLabel}</label>
                    <select id="model-type" name="type" class="form-select">
                        <option value="full_time">${(window.t ? window.t('arbeitszeitcheck', 'Full-Time') : 'Full-Time')}</option>
                        <option value="part_time">${(window.t ? window.t('arbeitszeitcheck', 'Part-Time') : 'Part-Time')}</option>
                        <option value="flexible">${(window.t ? window.t('arbeitszeitcheck', 'Flexible') : 'Flexible')}</option>
                        <option value="trust_based">${(window.t ? window.t('arbeitszeitcheck', 'Trust-Based') : 'Trust-Based')}</option>
                        <option value="shift_work">${(window.t ? window.t('arbeitszeitcheck', 'Shift Work') : 'Shift Work')}</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="model-weekly-hours" class="form-label">${weeklyHoursLabel} <span class="form-required">*</span></label>
                    <input type="number" id="model-weekly-hours" name="weeklyHours" class="form-input" 
                           min="0" max="168" step="0.5" value="40" required>
                </div>
                <div class="form-group">
                    <label for="model-daily-hours" class="form-label">${dailyHoursLabel} <span class="form-required">*</span></label>
                    <input type="number" id="model-daily-hours" name="dailyHours" class="form-input" 
                           min="0" max="24" step="0.5" value="8" required>
                </div>
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="model-is-default" name="isDefault" value="1">
                        <label for="model-is-default">${isDefaultLabel}</label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">${createLabel}</button>
                    <button type="button" class="btn btn--secondary" data-action="close-modal">${cancelLabel}</button>
                </div>
            </form>
        `;

        const modal = Components.createModal({
            id: 'create-model-modal',
            title: title,
            content: formContent,
            size: 'md',
            closable: true,
            onClose: function() {
                const modalEl = document.getElementById('create-model-modal');
                if (modalEl && modalEl.parentNode) {
                    modalEl.parentNode.remove();
                }
            }
        });

        Components.openModal('create-model-modal');

        // Handle form submission
        const form = document.getElementById('create-model-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleCreateModel(form);
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
     * Handle edit model
     */
    function handleEditModel(e) {
        const modelId = e.target.dataset.modelId;
        if (!modelId) return;

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models/' + modelId, {
            method: 'GET',
            onSuccess: function(data) {
                if (data.success && data.model) {
                    showEditModal(data.model);
                } else {
                    const errorMsg = (window.ArbeitszeitCheck?.l10n?.failedToLoadModel || (window.t && window.t('arbeitszeitcheck', 'Failed to load model'))) || 'Failed to load model';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                const errorMsg = (window.ArbeitszeitCheck?.l10n?.failedToLoadModel || (window.t && window.t('arbeitszeitcheck', 'Failed to load model'))) || 'Failed to load model';
                Messaging.showError(errorMsg);
            }
        });
    }

    /**
     * Show edit modal
     */
    function showEditModal(model) {
        if (!model || !model.id) {
            const errorMsg = (window.ArbeitszeitCheck?.l10n?.invalidModelData || window.t && window.t('arbeitszeitcheck', 'Invalid model data')) || 'Invalid model data';
            Messaging.showError(errorMsg);
            return;
        }

        const title = window.ArbeitszeitCheck?.l10n?.editModel || 'Edit Working Time Model';
        const saveLabel = window.ArbeitszeitCheck?.l10n?.save || 'Save';
        const cancelLabel = window.ArbeitszeitCheck?.l10n?.cancel || 'Cancel';
        const nameLabel = window.ArbeitszeitCheck?.l10n?.name || 'Name';
        const descriptionLabel = window.ArbeitszeitCheck?.l10n?.description || 'Description';
        const typeLabel = window.ArbeitszeitCheck?.l10n?.type || 'Type';
        const weeklyHoursLabel = window.ArbeitszeitCheck?.l10n?.weeklyHours || 'Weekly Hours';
        const dailyHoursLabel = window.ArbeitszeitCheck?.l10n?.dailyHours || 'Daily Hours';
        const isDefaultLabel = window.ArbeitszeitCheck?.l10n?.isDefault || 'Set as Default';
        
        const formContent = `
            <form id="edit-model-form" class="form">
                <input type="hidden" id="model-id" name="id" value="${model.id}">
                <div class="form-group">
                    <label for="edit-model-name" class="form-label">${nameLabel} <span class="form-required">*</span></label>
                    <input type="text" id="edit-model-name" name="name" class="form-input" required 
                           value="${Utils.escapeHtml(model.name || '')}" placeholder="${nameLabel}">
                </div>
                <div class="form-group">
                    <label for="edit-model-description" class="form-label">${descriptionLabel}</label>
                    <textarea id="edit-model-description" name="description" class="form-textarea" rows="3"
                              placeholder="${descriptionLabel}">${Utils.escapeHtml(model.description || '')}</textarea>
                </div>
                <div class="form-group">
                    <label for="edit-model-type" class="form-label">${typeLabel}</label>
                    <select id="edit-model-type" name="type" class="form-select">
                        <option value="full_time" ${model.type === 'full_time' ? 'selected' : ''}>${window.ArbeitszeitCheck?.l10n?.fullTime || (window.t && window.t('arbeitszeitcheck', 'Full-Time')) || 'Full-Time'}</option>
                        <option value="part_time" ${model.type === 'part_time' ? 'selected' : ''}>${window.ArbeitszeitCheck?.l10n?.partTime || (window.t && window.t('arbeitszeitcheck', 'Part-Time')) || 'Part-Time'}</option>
                        <option value="flexible" ${model.type === 'flexible' ? 'selected' : ''}>${window.ArbeitszeitCheck?.l10n?.flexible || (window.t && window.t('arbeitszeitcheck', 'Flexible')) || 'Flexible'}</option>
                        <option value="trust_based" ${model.type === 'trust_based' ? 'selected' : ''}>${window.ArbeitszeitCheck?.l10n?.trustBased || (window.t && window.t('arbeitszeitcheck', 'Trust-Based')) || 'Trust-Based'}</option>
                        <option value="shift_work" ${model.type === 'shift_work' ? 'selected' : ''}>${window.ArbeitszeitCheck?.l10n?.shiftWork || (window.t && window.t('arbeitszeitcheck', 'Shift Work')) || 'Shift Work'}</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-model-weekly-hours" class="form-label">${weeklyHoursLabel} <span class="form-required">*</span></label>
                    <input type="number" id="edit-model-weekly-hours" name="weeklyHours" class="form-input" 
                           min="0" max="168" step="0.5" value="${model.weeklyHours || 40}" required>
                </div>
                <div class="form-group">
                    <label for="edit-model-daily-hours" class="form-label">${dailyHoursLabel} <span class="form-required">*</span></label>
                    <input type="number" id="edit-model-daily-hours" name="dailyHours" class="form-input" 
                           min="0" max="24" step="0.5" value="${model.dailyHours || 8}" required>
                </div>
                <div class="form-group">
                    <div class="form-checkbox">
                        <input type="checkbox" id="edit-model-is-default" name="isDefault" value="1" ${model.isDefault ? 'checked' : ''}>
                        <label for="edit-model-is-default">${isDefaultLabel}</label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn--secondary" data-action="close-modal">${cancelLabel}</button>
                    <button type="submit" class="btn btn--primary">${saveLabel}</button>
                </div>
            </form>
        `;

        const modal = Components.createModal({
            id: 'edit-model-modal',
            title: title,
            content: formContent,
            size: 'md',
            closable: true,
            onClose: function() {
                const modalEl = document.getElementById('edit-model-modal');
                if (modalEl && modalEl.parentNode) {
                    modalEl.parentNode.remove();
                }
            }
        });

        Components.openModal('edit-model-modal');

        // Handle form submission
        const form = document.getElementById('edit-model-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                handleUpdateModel(form, model.id);
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
     * Handle create model form submission
     */
    function handleCreateModel(form) {
        const formData = new FormData(form);
        const data = {
            name: formData.get('name'),
            description: formData.get('description') || null,
            type: formData.get('type') || 'full_time',
            weeklyHours: parseFloat(formData.get('weeklyHours')) || 40,
            dailyHours: parseFloat(formData.get('dailyHours')) || 8,
            isDefault: formData.get('isDefault') === '1'
        };

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models', {
            method: 'POST',
            data: data,
            onSuccess: function(response) {
                if (response.success) {
                    const successMsg = window.ArbeitszeitCheck?.l10n?.modelCreated || (window.t && window.t('arbeitszeitcheck', 'Model created successfully')) || 'Model created successfully';
                    Messaging.showSuccess(successMsg);
                    Components.closeModal(document.getElementById('create-model-modal'));
                    // Reload page to show new model
                    setTimeout(() => location.reload(), 1000);
                } else {
                    const errorMsg = response.error || (window.ArbeitszeitCheck?.l10n?.failedToCreateModel || (window.t && window.t('arbeitszeitcheck', 'Failed to create model'))) || 'Failed to create model';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                const errorMsg = window.ArbeitszeitCheck?.l10n?.failedToCreateModel || (window.t && window.t('arbeitszeitcheck', 'Failed to create model')) || 'Failed to create model';
                Messaging.showError(errorMsg);
            }
        });
    }

    /**
     * Handle update model form submission
     */
    function handleUpdateModel(form, modelId) {
        const formData = new FormData(form);
        const data = {
            name: formData.get('name'),
            description: formData.get('description') || null,
            type: formData.get('type') || 'full_time',
            weeklyHours: parseFloat(formData.get('weeklyHours')) || 40,
            dailyHours: parseFloat(formData.get('dailyHours')) || 8,
            isDefault: formData.get('isDefault') === '1'
        };

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models/' + modelId, {
            method: 'PUT',
            data: data,
            onSuccess: function(response) {
                if (response.success) {
                    const successMsg = window.ArbeitszeitCheck?.l10n?.modelUpdated || 
                                        (window.t && window.t('arbeitszeitcheck', 'Model updated successfully')) || 
                                        'Model updated successfully';
                    Messaging.showSuccess(successMsg);
                    Components.closeModal(document.getElementById('edit-model-modal'));
                    // Reload page to show updated model
                    setTimeout(() => location.reload(), 1000);
                } else {
                    const errorMsg = response.error || (window.ArbeitszeitCheck?.l10n?.failedToUpdateModel || (window.t && window.t('arbeitszeitcheck', 'Failed to update model'))) || 'Failed to update model';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                const errorMsg = window.ArbeitszeitCheck?.l10n?.failedToUpdateModel || (window.t && window.t('arbeitszeitcheck', 'Failed to update model')) || 'Failed to update model';
                Messaging.showError(errorMsg);
            }
        });
    }

    /**
     * Handle delete model with helpful confirmation
     */
    function handleDeleteModel(e) {
        const modelId = e.target.dataset.modelId;
        if (!modelId) return;

        const modelName = e.target.closest('tr')?.querySelector('td:first-child')?.textContent?.trim() || 'this work schedule';
        const confirmMessage = window.ArbeitszeitCheck?.l10n?.confirmDeleteModel || 
            `Are you sure you want to delete "${modelName}"?\n\nThis will permanently remove this work schedule. If any employees are using this schedule, you should assign them to a different schedule first.\n\nThis action cannot be undone.`;

        if (!confirm(confirmMessage)) {
            return;
        }

        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models/' + modelId, {
            method: 'DELETE',
            onSuccess: function(data) {
                if (data.success) {
                    const successMsg = window.ArbeitszeitCheck?.l10n?.modelDeleted || (window.t && window.t('arbeitszeitcheck', 'Working time model deleted successfully')) || 'Working time model deleted successfully';
                    Messaging.showSuccess(successMsg);
                    // Reload page or remove row
                    location.reload();
                } else {
                    const errorMsg = data.error || (window.ArbeitszeitCheck?.l10n?.failedToDeleteModel || (window.t && window.t('arbeitszeitcheck', 'Failed to delete model'))) || 'Failed to delete model';
                    Messaging.showError(errorMsg);
                }
            },
            onError: function(_error) {
                const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to delete model')) || window.ArbeitszeitCheck?.l10n?.failedToDeleteModel || 'Failed to delete model';
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
