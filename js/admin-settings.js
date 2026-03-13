/**
 * Admin Settings JavaScript for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Validation = window.ArbeitszeitCheckValidation || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    /**
     * Initialize settings page
     */
    function init() {
        bindEvents();
    }

    /**
     * Bind event listeners
     */
    function bindEvents() {
        const form = Utils.$('#admin-settings-form');
        if (form) {
            Utils.on(form, 'submit', handleFormSubmit);
        }

        // Real-time validation
        const numberInputs = Utils.$$('#admin-settings-form input[type="number"]');
        numberInputs.forEach(input => {
            Utils.on(input, 'blur', function() {
                validateField(this);
            });
        });
    }

    /**
     * Handle form submission
     */
    function handleFormSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const formData = Utils.serializeForm(form);

        // Normalize requireSubstituteTypes[] to requireSubstituteTypes array for backend
        // Always send (empty array when none checked) so unchecked state is persisted
        const raw = formData['requireSubstituteTypes[]'];
        formData.requireSubstituteTypes = raw === undefined
            ? []
            : (Array.isArray(raw) ? raw : [raw]);
        delete formData['requireSubstituteTypes[]'];

        // Convert checkboxes to boolean (unchecked = not in form; checked sends "on" or value e.g. "1")
        function isChecked(v) { return v === 'on' || v === '1' || v === 1 || v === true; }
        formData.autoComplianceCheck = isChecked(formData.autoComplianceCheck);
        formData.realtimeComplianceCheck = isChecked(formData.realtimeComplianceCheck);
        formData.complianceStrictMode = isChecked(formData.complianceStrictMode);
        formData.enableViolationNotifications = isChecked(formData.enableViolationNotifications);
        formData.sendIcalApprovedAbsences = isChecked(formData.sendIcalApprovedAbsences);
        formData.sendIcalToSubstitute = isChecked(formData.sendIcalToSubstitute);
        formData.sendIcalToManagers = isChecked(formData.sendIcalToManagers);
        formData.sendEmailSubstitutionRequest = isChecked(formData.sendEmailSubstitutionRequest);
        formData.sendEmailSubstituteApprovedToEmployee = isChecked(formData.sendEmailSubstituteApprovedToEmployee);
        formData.sendEmailSubstituteApprovedToManager = isChecked(formData.sendEmailSubstituteApprovedToManager);
        formData.statutoryAutoReseed = isChecked(formData.statutoryAutoReseed);

        // Convert numbers (use defaults on invalid/empty)
        const num = (v, def) => { const n = parseFloat(v); return (Number.isFinite(n) ? n : def); };
        const int = (v, def) => { const n = parseInt(String(v), 10); return (Number.isInteger(n) ? n : def); };
        formData.maxDailyHours = num(formData.maxDailyHours, 10);
        formData.minRestPeriod = num(formData.minRestPeriod, 11);
        formData.defaultWorkingHours = num(formData.defaultWorkingHours, 8);
        formData.retentionPeriod = int(formData.retentionPeriod, 2);

        // Validate
        if (!validateForm(formData)) {
            return;
        }

        // Submit (use server-generated URL for subpath compatibility)
        const apiUrl = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminSettingsApiUrl) || '/apps/arbeitszeitcheck/api/admin/settings';
        Utils.ajax(apiUrl, {
            method: 'POST',
            data: formData,
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(data.message || (window.t ? window.t('arbeitszeitcheck', 'Settings saved successfully') : 'Settings saved successfully'));
                } else {
                    Messaging.showError(data.error || (window.t ? window.t('arbeitszeitcheck', 'Failed to save settings') : 'Failed to save settings'));
                }
            },
            onError: function(_error) {
                Messaging.showError(window.t ? window.t('arbeitszeitcheck', 'An error occurred while saving settings') : 'An error occurred while saving settings');
            }
        });
    }

    /**
     * Validate form data
     */
    function validateForm(data) {
        if (data.maxDailyHours < 1 || data.maxDailyHours > 24) {
            const msg = (window.t && window.t('arbeitszeitcheck', 'Maximum daily hours must be between 1 and 24')) || 'Maximum daily hours must be between 1 and 24';
            Messaging.showError(msg);
            return false;
        }

        if (data.minRestPeriod < 1 || data.minRestPeriod > 24) {
            const msg = (window.t && window.t('arbeitszeitcheck', 'Minimum rest period must be between 1 and 24 hours')) || 'Minimum rest period must be between 1 and 24 hours';
            Messaging.showError(msg);
            return false;
        }

        if (data.defaultWorkingHours < 1 || data.defaultWorkingHours > 24) {
            const msg = (window.t && window.t('arbeitszeitcheck', 'Default working hours must be between 1 and 24')) || 'Default working hours must be between 1 and 24';
            Messaging.showError(msg);
            return false;
        }

        if (data.retentionPeriod < 1 || data.retentionPeriod > 10) {
            const msg = (window.t && window.t('arbeitszeitcheck', 'Retention period must be between 1 and 10 years')) || 'Retention period must be between 1 and 10 years';
            Messaging.showError(msg);
            return false;
        }

        return true;
    }

    /**
     * Validate individual field
     */
    function validateField(field) {
        const value = parseFloat(field.value);
        const min = parseFloat(field.getAttribute('min'));
        const max = parseFloat(field.getAttribute('max'));

        if (isNaN(value) || value < min || value > max) {
            const msg = window.t ? window.t('arbeitszeitcheck', 'Value must be between {min} and {max}', {min: String(min), max: String(max)}) : 'Value must be between ' + min + ' and ' + max;
            Validation.showFieldError(field, msg);
            return false;
        } else {
            Validation.clearFieldError(field);
            return true;
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
