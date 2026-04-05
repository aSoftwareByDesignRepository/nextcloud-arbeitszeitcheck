/**
 * Settings Page JavaScript for ArbeitszeitCheck App
 * Handles personal settings form submission and working time model display
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function(window, OC) {
    'use strict';

    /**
     * Settings page controller
     */
    const SettingsPage = {
        /**
         * Merge API URLs from hidden #azc-settings-config (required when inline boot script is absent).
         */
        applyConfigFromDom: function() {
            const el = document.getElementById('azc-settings-config');
            if (!el || !el.dataset) {
                return;
            }
            window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
            window.ArbeitszeitCheck.apiUrl = window.ArbeitszeitCheck.apiUrl || {};
            if (el.dataset.updateUrl && !window.ArbeitszeitCheck.apiUrl.updateSettings) {
                window.ArbeitszeitCheck.apiUrl.updateSettings = el.dataset.updateUrl;
            }
            if (el.dataset.settingsLegacyUrl) {
                window.ArbeitszeitCheck._settingsLegacyUrl = el.dataset.settingsLegacyUrl;
            }
        },

        getLegacySettingsUrl: function() {
            if (window.ArbeitszeitCheck && window.ArbeitszeitCheck._settingsLegacyUrl) {
                return window.ArbeitszeitCheck._settingsLegacyUrl;
            }
            return OC.generateUrl('/apps/arbeitszeitcheck/api/settings-legacy');
        },

        /**
         * Initialize the settings page
         */
        init: function() {
            this.applyConfigFromDom();
            this.loadCurrentSettings().then(() => {
                if (document.getElementById('working-time-settings-form')) {
                    this.setupWorkingTimeForm();
                }
                if (document.getElementById('notification-settings-form')) {
                    this.setupNotificationForm();
                }
                if (document.getElementById('calendar-sync-settings-form')) {
                    this.setupCalendarSyncForm();
                }
                this.loadWorkingTimeModelInfo();
            });
        },

        /**
         * Load current settings from API
         */
        loadCurrentSettings: function() {
            return fetch(this.getLegacySettingsUrl(), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) || (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || ''
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

                    const notificationsEnabled = document.getElementById('notifications-enabled');
                    if (notificationsEnabled) {
                        notificationsEnabled.checked = result.settings.notifications_enabled === '1' || result.settings.notifications_enabled === true;
                    }

                    const breakReminders = document.getElementById('break-reminders');
                    if (breakReminders) {
                        breakReminders.checked = result.settings.break_reminders_enabled === '1' || result.settings.break_reminders_enabled === true;
                    }

                    const hol = document.getElementById('nc-calendar-sync-holidays');
                    if (hol) {
                        const hk = 'nc_calendar_sync_holidays';
                        const v = result.settings[hk];
                        // Default on when unset (matches server default for new users)
                        hol.checked = v === undefined || v === null || v === '1' || v === true;
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

        setupCalendarSyncForm: function() {
            const form = document.getElementById('calendar-sync-settings-form');
            if (!form) return;
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const data = {
                    nc_calendar_sync_holidays: form.querySelector('#nc-calendar-sync-holidays').checked
                };
                this.submitSettings(data, 'calendar-sync-settings-form');
            });
        },

        /**
         * Save working time preferences
         */
        saveWorkingTimeSettings: function(form) {
            const _formData = new FormData(form);
            const data = {
                auto_break_calculation: form.querySelector('#auto-break-calculation').checked
            };

            this.submitSettings(data, 'working-time-settings-form');
        },

        /**
         * Save notification settings
         */
        saveNotificationSettings: function(form) {
            const _formData = new FormData(form);
            const data = {
                notifications_enabled: form.querySelector('#notifications-enabled').checked,
                break_reminders_enabled: form.querySelector('#break-reminders').checked
            };

            this.submitSettings(data, 'notification-settings-form');
        },

        /**
         * Submit settings to API
         */
        submitSettings: function(data, formId) {
            const submitButton = document.querySelector(`#${formId} button[type="submit"]`);
            const originalText = submitButton ? submitButton.textContent : '';
            
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = (window.t && window.t('arbeitszeitcheck', 'Saving...')) || 'Saving...';
            }

            let apiUrl = window.ArbeitszeitCheck?.apiUrl?.updateSettings;
            if (!apiUrl) {
                apiUrl = OC.generateUrl('/apps/arbeitszeitcheck/settings');
            }

            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) || (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || ''
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
                const errorMsg = (window.t && window.t('arbeitszeitcheck', 'Failed to save settings')) ||
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

})(window, OC);
