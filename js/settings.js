/**
 * Settings Page JavaScript for ArbeitszeitCheck App
 * Handles personal settings form submission and working time model display
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function(window) {
    'use strict';

    function azcGenerateUrl(path) {
        if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.resolveUrl === 'function') {
            return window.ArbeitszeitCheckUtils.resolveUrl(path);
        }
        if (typeof window !== 'undefined' && window.OC && typeof window.OC.generateUrl === 'function') {
            return window.OC.generateUrl(path);
        }
        return path.charAt(0) === '/' ? path : '/' + path;
    }

    function azcRequestToken() {
        if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.getRequestToken === 'function') {
            return window.ArbeitszeitCheckUtils.getRequestToken();
        }
        if (typeof window !== 'undefined' && window.OC && window.OC.requestToken) {
            return window.OC.requestToken;
        }
        var head = document.querySelector('head');
        return head ? (head.getAttribute('data-requesttoken') || '') : '';
    }

    /**
     * Settings page controller
     */
    const SettingsPage = {
        /**
         * Initialize the settings page
         */
        init: function() {
            this.loadCurrentSettings().then(() => {
                if (document.getElementById('working-time-settings-form')) {
                    this.setupWorkingTimeForm();
                }
                if (document.getElementById('notification-settings-form')) {
                    this.setupNotificationForm();
                }
                this.setupGdprDelete();
                this.loadWorkingTimeModelInfo();
            });
        },

        /**
         * Load current settings from API
         */
        loadCurrentSettings: function() {
            return fetch(azcGenerateUrl('/apps/arbeitszeitcheck/api/settings-legacy'), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': azcRequestToken()
                },
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(result => {
                if (result.success && result.settings) {
                    // Set checkbox values
                    const autoBreakCalc = document.getElementById('auto-break-calculation');
                    if (autoBreakCalc) {
                        autoBreakCalc.checked = result.settings.auto_break_calculation === '1' || result.settings.auto_break_calculation === true;
                    }

                    const hoursDisplay = document.getElementById('hours-display');
                    if (hoursDisplay && result.settings.hours_display) {
                        hoursDisplay.value = result.settings.hours_display === 'hours_minutes' ? 'hours_minutes' : 'decimal';
                    }

                    const notificationsEnabled = document.getElementById('notifications-enabled');
                    if (notificationsEnabled) {
                        notificationsEnabled.checked = result.settings.notifications_enabled === '1' || result.settings.notifications_enabled === true;
                    }

                    const breakReminders = document.getElementById('break-reminders');
                    if (breakReminders) {
                        breakReminders.checked = result.settings.break_reminders_enabled === '1' || result.settings.break_reminders_enabled === true;
                    }

                    const missingClockInReminders = document.getElementById('missing-clock-in-reminders');
                    if (missingClockInReminders) {
                        missingClockInReminders.checked = result.settings.missing_clock_in_reminders_enabled === '1' || result.settings.missing_clock_in_reminders_enabled === true;
                    }

                }
            })
            .catch(error => {
                // Silently fail - use default values from HTML
                console.warn('Could not load current settings:', error);
            });
        },

        /**
         * Setup working time settings form
         */
        setupWorkingTimeForm: function() {
            const form = document.getElementById('working-time-settings-form');
            if (!form) return;

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveWorkingTimeSettings(form);
            });
        },

        /**
         * Setup notification settings form
         */
        setupNotificationForm: function() {
            const form = document.getElementById('notification-settings-form');
            if (!form) return;

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveNotificationSettings(form);
            });
        },

        setupGdprDelete: function() {
            const btn = document.getElementById('btn-gdpr-delete');
            if (!btn) return;

            btn.addEventListener('click', async () => {
                const Utils = window.ArbeitszeitCheckUtils;
                const title = window.t ? window.t('arbeitszeitcheck', 'Delete my ArbeitszeitCheck data') : 'Delete my ArbeitszeitCheck data';
                const message = window.t
                    ? window.t('arbeitszeitcheck', 'This deletes eligible time entries older than the retention period and clears your personal settings. Recent entries, absences, and compliance records stay where the law requires it.')
                    : 'This deletes eligible time entries older than the retention period and clears your personal settings. Recent entries, absences, and compliance records stay where the law requires it.';
                const confirmResult = Utils?.confirmDestructiveAction
                    ? await Utils.confirmDestructiveAction({
                        title,
                        message,
                        confirmLabel: window.t ? window.t('arbeitszeitcheck', 'Delete permanently') : 'Delete permanently',
                        variant: 'danger',
                        requireTypedConfirm: true,
                        typedConfirmPhrase: 'DELETE',
                        requireReason: true,
                    })
                    : null;
                if (!confirmResult) {
                    return;
                }

                const url = btn.getAttribute('data-delete-url')
                    || window.ArbeitszeitCheck?.apiUrl?.gdprDelete;
                if (!url) {
                    return;
                }

                btn.setAttribute('aria-busy', 'true');
                btn.disabled = true;

                const reason = Utils?.confirmDialogReason?.(confirmResult, 'user_request') || 'user_request';

                const api = window.AzcApi;
                const result = api
                    ? await api.fetch(url, { method: 'POST', json: { reason: reason || 'user_request' } })
                    : null;

                btn.removeAttribute('aria-busy');
                btn.disabled = false;

                if (result && result.ok) {
                    const msg = window.t
                        ? window.t('arbeitszeitcheck', 'Your ArbeitszeitCheck data has been deleted.')
                        : 'Your ArbeitszeitCheck data has been deleted.';
                    window.ArbeitszeitCheckMessaging?.showSuccess?.(msg);
                    window.setTimeout(() => {
                        window.location.href = window.OC?.generateUrl?.('/') || '/';
                    }, 1500);
                } else {
                    const err = (result && result.error)
                        || (window.t ? window.t('arbeitszeitcheck', 'Could not delete your data. Please try again or contact your administrator.') : 'Could not delete your data.');
                    window.ArbeitszeitCheckMessaging?.showError?.(err);
                }
            });
        },

        /**
         * Save working time preferences
         */
        saveWorkingTimeSettings: function(form) {
            const autoBreak = form.querySelector('#auto-break-calculation');
            if (!autoBreak) {
                return;
            }
            const hoursDisplay = form.querySelector('#hours-display');
            const data = {
                auto_break_calculation: !!autoBreak.checked,
                hours_display: hoursDisplay && hoursDisplay.value === 'hours_minutes' ? 'hours_minutes' : 'decimal'
            };

            this.submitSettings(data, 'working-time-settings-form');
        },

        /**
         * Save notification settings
         */
        saveNotificationSettings: function(form) {
            const notificationsEnabled = form.querySelector('#notifications-enabled');
            const breakReminders = form.querySelector('#break-reminders');
            const missingClockIn = form.querySelector('#missing-clock-in-reminders');
            if (!notificationsEnabled || !breakReminders || !missingClockIn) {
                return;
            }
            const data = {
                notifications_enabled: !!notificationsEnabled.checked,
                break_reminders_enabled: !!breakReminders.checked,
                missing_clock_in_reminders_enabled: !!missingClockIn.checked
            };

            this.submitSettings(data, 'notification-settings-form');
        },

        /**
         * Submit settings to API
         */
        submitSettings: function(data, formId) {
            const submitButton = document.querySelector(`#${formId} button[type="submit"]`);
            if (submitButton && (submitButton.disabled || submitButton.getAttribute('aria-busy') === 'true')) {
                return;
            }
            const originalText = submitButton ? submitButton.textContent : '';
            
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.textContent = window.ArbeitszeitCheck?.l10n?.saving || (window.t && window.t('arbeitszeitcheck', 'Saving...')) || 'Saving...';
            }

            let apiUrl = window.ArbeitszeitCheck?.apiUrl?.updateSettings;
            if (!apiUrl) {
                apiUrl = azcGenerateUrl('/apps/arbeitszeitcheck/settings');
            }

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': azcRequestToken()
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const message = result.message || 
                        (window.ArbeitszeitCheck?.l10n?.settingsSaved) ||
                        (window.t && window.t('arbeitszeitcheck', 'Settings saved successfully')) ||
                        'Settings saved successfully';
                    
                    if (window.ArbeitszeitCheckMessaging) {
                        window.ArbeitszeitCheckMessaging.showSuccess(message);
                    } else if (window.OC && window.OC.Notification) {
                        window.OC.Notification.showTemporary(message);
                    }
                } else {
                    const errorMsg = result.error || 
                        (window.ArbeitszeitCheck?.l10n?.error) ||
                        (window.t && window.t('arbeitszeitcheck', 'An error occurred')) ||
                        'An error occurred';
                    
                    if (window.ArbeitszeitCheckMessaging) {
                        window.ArbeitszeitCheckMessaging.showError(errorMsg);
                    } else if (window.OC && window.OC.Notification) {
                        window.OC.Notification.showTemporary(errorMsg);
                    }
                }
            })
            .catch(error => {
                const errorMsg = window.ArbeitszeitCheck?.l10n?.failedToSaveSettings ||
                    (window.t && window.t('arbeitszeitcheck', 'Failed to save settings')) ||
                    'Failed to save settings';
                
                if (window.ArbeitszeitCheckMessaging) {
                    window.ArbeitszeitCheckMessaging.showError(errorMsg + ': ' + error.message);
                } else if (window.OC && window.OC.Notification) {
                    window.OC.Notification.showTemporary(errorMsg);
                }
            })
            .finally(() => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.removeAttribute('aria-busy');
                    submitButton.textContent = originalText;
                }
            });
        },

        /**
         * Load working time model information
         * Note: Working time models are assigned by administrators
         */
        loadWorkingTimeModelInfo: function() {
            const infoBox = document.getElementById('working-time-model-info');
            if (!infoBox) return;

            // Info is already set in template, no need to load dynamically
            // This function is kept for potential future enhancements
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        SettingsPage.init();
    });

    // Export for global access if needed
    window.ArbeitszeitCheckSettings = SettingsPage;

})(window);
