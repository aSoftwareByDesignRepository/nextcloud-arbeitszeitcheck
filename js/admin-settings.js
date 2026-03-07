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
        if (formData['requireSubstituteTypes[]'] !== undefined) {
            formData.requireSubstituteTypes = Array.isArray(formData['requireSubstituteTypes[]'])
                ? formData['requireSubstituteTypes[]']
                : [formData['requireSubstituteTypes[]']];
            delete formData['requireSubstituteTypes[]'];
        }

        // Convert checkboxes to boolean (unchecked = not in form, so default false)
        formData.autoComplianceCheck = formData.autoComplianceCheck === 'on' || formData.autoComplianceCheck === true;
        formData.requireBreakJustification = formData.requireBreakJustification === 'on' || formData.requireBreakJustification === true;
        formData.enableViolationNotifications = formData.enableViolationNotifications === 'on' || formData.enableViolationNotifications === true;
        formData.sendIcalApprovedAbsences = formData.sendIcalApprovedAbsences === 'on' || formData.sendIcalApprovedAbsences === true;
        formData.sendIcalToSubstitute = formData.sendIcalToSubstitute === 'on' || formData.sendIcalToSubstitute === true;

        // Convert numbers
        formData.maxDailyHours = parseFloat(formData.maxDailyHours);
        formData.minRestPeriod = parseFloat(formData.minRestPeriod);
        formData.defaultWorkingHours = parseFloat(formData.defaultWorkingHours);
        formData.retentionPeriod = parseInt(formData.retentionPeriod);

        // Validate
        if (!validateForm(formData)) {
            return;
        }

        // Submit
        Utils.ajax('/apps/arbeitszeitcheck/api/admin/settings', {
            method: 'POST',
            data: formData,
            onSuccess: function(data) {
                if (data.success) {
                    Messaging.showSuccess(data.message || 'Settings saved successfully');
                } else {
                    Messaging.showError(data.error || 'Failed to save settings');
                }
            },
            onError: function(_error) {
                Messaging.showError('An error occurred while saving settings');
            }
        });
    }

    /**
     * Validate form data
     */
    function validateForm(data) {
        if (data.maxDailyHours < 1 || data.maxDailyHours > 24) {
            Messaging.showError('Maximum daily hours must be between 1 and 24');
            return false;
        }

        if (data.minRestPeriod < 1 || data.minRestPeriod > 24) {
            Messaging.showError('Minimum rest period must be between 1 and 24 hours');
            return false;
        }

        if (data.defaultWorkingHours < 1 || data.defaultWorkingHours > 24) {
            Messaging.showError('Default working hours must be between 1 and 24');
            return false;
        }

        if (data.retentionPeriod < 1 || data.retentionPeriod > 10) {
            Messaging.showError('Retention period must be between 1 and 10 years');
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
            Validation.showFieldError(field, `Value must be between ${min} and ${max}`);
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
