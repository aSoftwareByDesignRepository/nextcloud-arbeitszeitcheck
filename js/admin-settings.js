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

    function buildApiUrl(path) {
        if (Utils.buildAppUrl) {
            return Utils.buildAppUrl(path);
        }
        if (Utils.resolveUrl) {
            return Utils.resolveUrl(path);
        }
        return path;
    }

    /** @type {{ clear: function(): void, getUserId: function(): string } | null} */
    let monthReopenPicker = null;

    function setLiveMessage(liveRegion, message, type) {
        if (!liveRegion) {
            return;
        }
        liveRegion.textContent = message || '';
        liveRegion.classList.remove('admin-settings-live--error', 'admin-settings-live--success');
        if (type === 'error') {
            liveRegion.classList.add('admin-settings-live--error');
        } else if (type === 'success') {
            liveRegion.classList.add('admin-settings-live--success');
        }
        // Do not scrollIntoView: the live region can sit far from the control
        // that just saved. Scrolling yanks the admin away mid-workflow.
    }

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
        const reopenBtn = Utils.$('#monthClosureReopenBtn');
        if (reopenBtn) {
            Utils.on(reopenBtn, 'click', handleMonthReopen);
        }
        // Real-time validation
        const numberInputs = Utils.$$('#admin-settings-form input[type="number"]');
        numberInputs.forEach(input => {
            Utils.on(input, 'blur', function() {
                validateField(this);
            });
        });

        initMonthReopenUserPicker();
        initAppAdminsPicker();
        initAccessModeToggle();
        initAccessUsersPicker();
        initAccessGroupsPicker();
        initProjectCheckAdminToggle();
        initOrganizationTimeCapture();
        initCountryRegionPicker();
    }

    /**
     * Country → region picker (Country and region card).
     *
     * When the admin selects a different country, the region select is rebuilt
     * from the server-rendered JSON (#azc-region-data) and preset to that
     * country's default region. Nothing is saved until the form is submitted
     * (WCAG 3.2.2 — no change of context on input).
     */
    function initCountryRegionPicker() {
        const dataEl = document.getElementById('azc-region-data');
        const regionSelect = Utils.$('#germanState');
        const radios = Utils.$$('input[name="country"]');
        if (!dataEl || !regionSelect || radios.length === 0) {
            return;
        }

        let regionData;
        try {
            regionData = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            return;
        }
        const regionsByCountry = regionData.regionsByCountry || {};
        const defaultByCountry = regionData.defaultRegionByCountry || {};
        const liveRegion = Utils.$('#country-region-live');
        const t = (text) => (window.t ? window.t('arbeitszeitcheck', text) : text);

        function rebuildRegions(country, preferredRegion) {
            const regions = regionsByCountry[country] || [];
            if (regions.length === 0) {
                return;
            }
            const target = regions.some(r => r.code === preferredRegion)
                ? preferredRegion
                : (defaultByCountry[country] || regions[0].code);
            regionSelect.textContent = '';
            regions.forEach(region => {
                const option = document.createElement('option');
                option.value = region.code;
                option.textContent = region.label;
                option.selected = region.code === target;
                regionSelect.appendChild(option);
            });
            regionSelect.value = target;
        }

        radios.forEach(radio => {
            Utils.on(radio, 'change', function() {
                if (!this.checked) {
                    return;
                }
                rebuildRegions(this.value, regionSelect.value);
                const weeklyGroup = Utils.$('#weekly-absolute-max-group');
                const weeklySelect = Utils.$('#weeklyAbsoluteMaxHours');
                const isCh = this.value === 'CH';
                if (weeklyGroup) {
                    weeklyGroup.hidden = !isCh;
                }
                if (weeklySelect) {
                    weeklySelect.disabled = !isCh;
                }
                const vacationCallout = Utils.$('#vacation-days-suggestion-callout');
                const vacationText = Utils.$('#vacation-days-suggestion-text');
                if (vacationCallout && vacationText) {
                    const key = 'data-suggestion-' + String(this.value).toLowerCase();
                    const suggestion = parseInt(vacationCallout.getAttribute(key) || '25', 10);
                    vacationCallout.setAttribute('data-current-suggestion', String(suggestion));
                    const template = t('When assigning working time models, the suggested default is %1$d vacation days per year for the selected country (Germany/Austria typically 25; Switzerland typically 20). Existing assignments are never changed automatically.');
                    vacationText.textContent = template.replace('%1$d', String(suggestion));
                }
                if (liveRegion) {
                    const selectedLabel = regionSelect.options[regionSelect.selectedIndex]
                        ? regionSelect.options[regionSelect.selectedIndex].textContent
                        : '';
                    liveRegion.textContent = t('Region list updated. Default region: %s')
                        .replace('%s', selectedLabel);
                }
            });
        });
    }

    function initOrganizationTimeCapture() {
        const clockEl = Utils.$('#clockStampingEnabled');
        const manualEl = Utils.$('#manualTimeEntryEnabled');
        const errorEl = Utils.$('#admin-time-capture-error');
        if (!clockEl || !manualEl || !errorEl) {
            return;
        }
        const badge = Utils.$('#admin-time-capture-status-badge');
        const status = Utils.$('#admin-time-capture-status-text');
        const t = (key, fallback) => (window.t ? window.t('arbeitszeitcheck', key) : fallback);

        function syncStatus() {
            const clockOn = !!clockEl.checked;
            const manualOn = !!manualEl.checked;
            if (badge) {
                if (clockOn && manualOn) {
                    badge.textContent = t('Both methods active', 'Both methods active');
                    badge.className = 'azc-badge azc-badge--info';
                } else if (clockOn) {
                    badge.textContent = t('Stamping only', 'Stamping only');
                    badge.className = 'azc-badge azc-badge--success';
                } else if (manualOn) {
                    badge.textContent = t('Manual entries only', 'Manual entries only');
                    badge.className = 'azc-badge azc-badge--warning';
                } else {
                    badge.textContent = t('No method selected', 'No method selected');
                    badge.className = 'azc-badge azc-badge--danger';
                }
            }
            if (status) {
                if (clockOn && manualOn) {
                    status.textContent = t(
                        'Employees can clock in/out on the dashboard and add manual time entries.',
                        'Employees can clock in/out on the dashboard and add manual time entries.'
                    );
                } else if (clockOn) {
                    status.textContent = t(
                        'Employees must use the punch clock. Manual time entries are hidden for everyone.',
                        'Employees must use the punch clock. Manual time entries are hidden for everyone.'
                    );
                } else if (manualOn) {
                    status.textContent = t(
                        'Employees add completed work blocks by date and time. The punch clock is hidden for everyone.',
                        'Employees add completed work blocks by date and time. The punch clock is hidden for everyone.'
                    );
                } else {
                    status.textContent = t(
                        'Select at least one method before saving.',
                        'Select at least one method before saving.'
                    );
                }
            }
        }

        const validate = () => {
            const ok = clockEl.checked || manualEl.checked;
            errorEl.hidden = ok;
            if (!ok) {
                errorEl.textContent = window.ArbeitszeitCheck?.l10n?.timeCaptureAtLeastOneOrg
                    || (window.t && window.t('arbeitszeitcheck', 'Enable clock in/out or manual time entries — at least one method is required for the organisation.'))
                    || 'Enable clock in/out or manual time entries — at least one method is required for the organisation.';
            }
            syncStatus();
            return ok;
        };
        Utils.on(clockEl, 'change', validate);
        Utils.on(manualEl, 'change', validate);
        syncStatus();
    }

    function initProjectCheckAdminToggle() {
        const toggle = Utils.$('#projectCheckIntegrationEnabled');
        if (!toggle) {
            return;
        }
        const badge = Utils.$('#projectcheck-admin-status-badge');
        const status = Utils.$('#projectcheck-admin-status-text');
        const t = (key, fallback) => (window.t ? window.t('arbeitszeitcheck', key) : fallback);

        function syncUi() {
            const on = !!toggle.checked;
            toggle.setAttribute('aria-checked', on ? 'true' : 'false');
            if (badge) {
                badge.textContent = on ? t('Connection on', 'Connection on') : t('Connection off', 'Connection off');
                badge.classList.toggle('azc-projectcheck-connection__badge--on', on);
                badge.classList.toggle('azc-projectcheck-connection__badge--off', !on);
            }
            if (status) {
                status.textContent = on
                    ? t('Employees can link time to customer projects.', 'Employees can link time to customer projects.')
                    : t('Project linking is disabled for everyone until you turn this on.', 'Project linking is disabled for everyone until you turn this on.');
            }
        }

        Utils.on(toggle, 'change', syncUi);
    }

    function initAppAdminsPicker() {
        const search = Utils.$('#appAdminUsersSearch');
        const list = Utils.$('#appAdminUsersList');
        const empty = Utils.$('#appAdminUsersEmpty');
        const countEl = Utils.$('#appAdminUsersCount');
        const l10n = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n ? window.ArbeitszeitCheck.l10n : {};
        if (!search || !list) {
            return;
        }

        let items = Array.prototype.slice.call(list.querySelectorAll('.access-groups-item'));
        let checkboxes = Array.prototype.slice.call(list.querySelectorAll('input[name="appAdminUserIds[]"]'));

        function updateCount() {
            if (!countEl) {
                return;
            }
            checkboxes = Array.prototype.slice.call(list.querySelectorAll('input[name="appAdminUserIds[]"]'));
            const selectedCount = checkboxes.filter(function(box) { return box.checked; }).length;
            if (selectedCount === 0) {
                countEl.textContent = l10n.appAdminsAllAdmins || 'No app admins selected (all Nextcloud admins are allowed).';
                const summary = Utils.$('#azcAccessAdminsSummary');
                if (summary) {
                    summary.textContent = countEl.textContent;
                }
                return;
            }
            const template = l10n.appAdminsSelected || '%s app admin(s) selected';
            countEl.textContent = template.indexOf('%s') !== -1
                ? template.replace('%s', String(selectedCount))
                : String(selectedCount) + ' ' + template;
            const summary = Utils.$('#azcAccessAdminsSummary');
            if (summary) {
                summary.textContent = countEl.textContent;
            }
        }

        function applyFilter() {
            items = Array.prototype.slice.call(list.querySelectorAll('.access-groups-item'));
            const q = String(search.value || '').trim().toLowerCase();
            let visible = 0;
            items.forEach(function(item) {
                const haystack = String(item.getAttribute('data-app-admin-search') || '');
                const show = q === '' || haystack.indexOf(q) !== -1;
                item.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        function ensureAdminOption(user) {
            const id = String(user.id || user.userId || '').trim();
            if (id === '') {
                return;
            }
            const existing = list.querySelector('input[name="appAdminUserIds[]"][value="' + CSS.escape(id) + '"]');
            if (existing) {
                existing.checked = true;
                updateCount();
                applyFilter();
                return;
            }
            const displayName = String(user.displayName || id);
            const label = document.createElement('label');
            label.className = 'access-groups-item';
            label.setAttribute('data-app-admin-search', (displayName + ' ' + id).toLowerCase());
            label.innerHTML = '<input type="checkbox" name="appAdminUserIds[]" value="" checked>'
                + '<span class="access-groups-item__label"></span>'
                + '<span class="access-groups-item__meta"></span>';
            label.querySelector('input').value = id;
            label.querySelector('.access-groups-item__label').textContent = displayName;
            label.querySelector('.access-groups-item__meta').textContent = id;
            list.appendChild(label);
            Utils.on(label.querySelector('input'), 'change', updateCount);
            updateCount();
            applyFilter();
        }

        Utils.on(search, 'input', applyFilter);
        checkboxes.forEach(function(box) {
            Utils.on(box, 'change', updateCount);
        });

        const PickerHost = window.ArbeitszeitCheck || {};
        const addSearch = Utils.$('#appAdminUsersAddSearch');
        const addList = Utils.$('#appAdminUsersAddList');
        if (typeof PickerHost.initAdminUserPicker === 'function' && addSearch && addList) {
            // Hidden field required by the shared picker; value is only used to detect picks.
            let hidden = Utils.$('#appAdminUsersAddHidden');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.id = 'appAdminUsersAddHidden';
                addSearch.parentNode.appendChild(hidden);
            }
            PickerHost.initAdminUserPicker({
                hiddenSelector: '#appAdminUsersAddHidden',
                searchSelector: '#appAdminUsersAddSearch',
                listSelector: '#appAdminUsersAddList',
                wrapSelector: '#appAdminUsersAddPicker',
                searchUrl: PickerHost.adminUsersListUrl || '',
                onChange: function(uid, meta) {
                    if (!uid) {
                        return;
                    }
                    ensureAdminOption({
                        id: uid,
                        displayName: (meta && meta.displayName) ? meta.displayName : uid,
                    });
                    hidden.value = '';
                    addSearch.value = '';
                },
            });
        }

        updateCount();
        applyFilter();
    }

    function initAccessModeToggle() {
        const radios = Array.prototype.slice.call(document.querySelectorAll('input[name="accessRestrictionEnabled"]'));
        const panels = Array.prototype.slice.call(document.querySelectorAll('[data-azc-access-allowlists]'));
        const summaryPanels = Array.prototype.slice.call(document.querySelectorAll('[data-azc-access-summary-panel]'));
        const modeSummary = Utils.$('#azcAccessModeSummary');
        const resetSearches = [
            Utils.$('#accessAllowedUsersSearch'),
            Utils.$('#accessAllowedGroupsSearch'),
        ].filter(Boolean);
        if (radios.length === 0) {
            return;
        }

        function sync() {
            const checked = radios.find(function(r) { return r.checked; }) || null;
            const restricted = checked !== null && String(checked.value) === '1';
            if (modeSummary && checked) {
                const label = checked.closest('label');
                const text = label ? label.textContent : '';
                modeSummary.textContent = String(text || '').trim();
            }
            panels.forEach(function(panel) {
                panel.hidden = !restricted;
            });
            summaryPanels.forEach(function(panel) {
                panel.hidden = !restricted;
            });
            if (!restricted) {
                resetSearches.forEach(function(input) {
                    if (String(input.value || '') === '') {
                        return;
                    }
                    input.value = '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }
        }

        radios.forEach(function(radio) {
            Utils.on(radio, 'change', sync);
        });
        sync();
    }

    function initAccessUsersPicker() {
        const search = Utils.$('#accessAllowedUsersSearch');
        const list = Utils.$('#accessAllowedUsersList');
        const empty = Utils.$('#accessAllowedUsersEmpty');
        const countEl = Utils.$('#accessAllowedUsersCount');
        const l10n = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n ? window.ArbeitszeitCheck.l10n : {};
        if (!search || !list) {
            return;
        }

        let items = Array.prototype.slice.call(list.querySelectorAll('.access-groups-item'));
        let checkboxes = Array.prototype.slice.call(list.querySelectorAll('input[name="accessAllowedUserIds[]"]'));

        function updateCount() {
            if (!countEl) {
                return;
            }
            checkboxes = Array.prototype.slice.call(list.querySelectorAll('input[name="accessAllowedUserIds[]"]'));
            const selectedCount = checkboxes.filter(function(box) { return box.checked; }).length;
            if (selectedCount === 0) {
                countEl.textContent = l10n.accessUsersNone || 'No individual users selected.';
                const summary = Utils.$('#azcAccessUsersSummary');
                if (summary) {
                    summary.textContent = countEl.textContent;
                }
                return;
            }
            const template = l10n.accessUsersSelected || '%s user(s) selected';
            countEl.textContent = template.indexOf('%s') !== -1
                ? template.replace('%s', String(selectedCount))
                : String(selectedCount) + ' ' + template;
            const summary = Utils.$('#azcAccessUsersSummary');
            if (summary) {
                summary.textContent = countEl.textContent;
            }
        }

        function applyFilter() {
            items = Array.prototype.slice.call(list.querySelectorAll('.access-groups-item'));
            const q = String(search.value || '').trim().toLowerCase();
            let visible = 0;
            items.forEach(function(item) {
                const haystack = String(item.getAttribute('data-access-user-search') || '');
                const show = q === '' || haystack.indexOf(q) !== -1;
                item.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        function ensureUserOption(user) {
            const id = String(user.id || user.userId || '').trim();
            if (id === '') {
                return;
            }
            const existing = list.querySelector('input[name="accessAllowedUserIds[]"][value="' + CSS.escape(id) + '"]');
            if (existing) {
                existing.checked = true;
                updateCount();
                applyFilter();
                return;
            }
            const displayName = String(user.displayName || id);
            const label = document.createElement('label');
            label.className = 'access-groups-item';
            label.setAttribute('data-access-user-search', (displayName + ' ' + id).toLowerCase());
            label.innerHTML = '<input type="checkbox" name="accessAllowedUserIds[]" value="" checked>'
                + '<span class="access-groups-item__label"></span>'
                + '<span class="access-groups-item__meta"></span>';
            label.querySelector('input').value = id;
            label.querySelector('.access-groups-item__label').textContent = displayName;
            label.querySelector('.access-groups-item__meta').textContent = id;
            list.appendChild(label);
            Utils.on(label.querySelector('input'), 'change', updateCount);
            updateCount();
            applyFilter();
        }

        Utils.on(search, 'input', applyFilter);
        checkboxes.forEach(function(box) {
            Utils.on(box, 'change', updateCount);
        });

        const PickerHost = window.ArbeitszeitCheck || {};
        const addSearch = Utils.$('#accessAllowedUsersAddSearch');
        const addList = Utils.$('#accessAllowedUsersAddList');
        if (typeof PickerHost.initAdminUserPicker === 'function' && addSearch && addList) {
            let hidden = Utils.$('#accessAllowedUsersAddHidden');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.id = 'accessAllowedUsersAddHidden';
                addSearch.parentNode.appendChild(hidden);
            }
            PickerHost.initAdminUserPicker({
                hiddenSelector: '#accessAllowedUsersAddHidden',
                searchSelector: '#accessAllowedUsersAddSearch',
                listSelector: '#accessAllowedUsersAddList',
                wrapSelector: '#accessAllowedUsersAddPicker',
                searchUrl: PickerHost.adminUsersListUrl || '',
                onChange: function(uid, meta) {
                    if (!uid) {
                        return;
                    }
                    ensureUserOption({
                        id: uid,
                        displayName: (meta && meta.displayName) ? meta.displayName : uid,
                    });
                    hidden.value = '';
                    addSearch.value = '';
                },
            });
        }

        updateCount();
        applyFilter();
    }

    function initAccessGroupsPicker() {
        const search = Utils.$('#accessAllowedGroupsSearch');
        const list = Utils.$('#accessAllowedGroupsList');
        const empty = Utils.$('#accessAllowedGroupsEmpty');
        const countEl = Utils.$('#accessAllowedGroupsCount');
        const l10n = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n ? window.ArbeitszeitCheck.l10n : {};
        if (!search || !list) {
            return;
        }

        const items = Array.prototype.slice.call(list.querySelectorAll('.access-groups-item'));
        const checkboxes = Array.prototype.slice.call(list.querySelectorAll('input[name="accessAllowedGroups[]"]'));

        function updateCount() {
            if (!countEl) {
                return;
            }
            const selectedCount = checkboxes.filter(function(box) { return box.checked; }).length;
            if (selectedCount === 0) {
                countEl.textContent = l10n.accessGroupsNone || 'No groups selected.';
                const summary = Utils.$('#azcAccessGroupsSummary');
                if (summary) {
                    summary.textContent = countEl.textContent;
                }
                return;
            }
            const template = l10n.accessGroupsSelected || '%s group(s) selected';
            countEl.textContent = template.indexOf('%s') !== -1
                ? template.replace('%s', String(selectedCount))
                : String(selectedCount) + ' ' + template;
            const summary = Utils.$('#azcAccessGroupsSummary');
            if (summary) {
                summary.textContent = countEl.textContent;
            }
        }

        function applyFilter() {
            const q = String(search.value || '').trim().toLowerCase();
            let visible = 0;
            items.forEach(function(item) {
                const haystack = String(item.getAttribute('data-access-group-search') || '');
                const show = q === '' || haystack.indexOf(q) !== -1;
                item.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        Utils.on(search, 'input', applyFilter);
        checkboxes.forEach(function(box) {
            Utils.on(box, 'change', updateCount);
        });

        updateCount();
        applyFilter();
    }

    /**
     * Handle form submission
     */
    let adminSettingsSaveInflight = false;
    async function handleFormSubmit(e) {
        e.preventDefault();
        if (adminSettingsSaveInflight) {
            return;
        }

        const form = e.target;
        const formData = Utils.serializeForm(form);
        const scope = String(form.getAttribute('data-settings-section') || formData.settings_section || 'all');
        formData.settings_section = scope;

        // Convert checkboxes to boolean only when the control exists on this page.
        // Missing fields must not be sent as false — that would wipe sibling sections.
        function isChecked(v) { return v === 'on' || v === '1' || v === 1 || v === true; }
        function hasField(name) {
            return !!form.querySelector('[name="' + name + '"], [name="' + name + '[]"]');
        }
        function setBoolIfPresent(name) {
            if (!hasField(name)) {
                delete formData[name];
                return;
            }
            formData[name] = isChecked(formData[name]);
        }
        [
            'autoComplianceCheck',
            'realtimeComplianceCheck',
            'complianceStrictMode',
            'enableViolationNotifications',
            'breakAutoFallbackEnabled',
            'exportMidnightSplitEnabled',
            'monthClosureEnabled',
            'statutoryAutoReseed',
            'timeEntryChangesRequireApproval',
            'manualTimeEntriesRequireApproval',
            'clockStampingEnabled',
            'manualTimeEntryEnabled',
            'projectCheckIntegrationEnabled',
        ].forEach(setBoolIfPresent);

        if (hasField('accessAllowedGroups[]') || hasField('accessAllowedGroups')) {
            const accessGroupsRaw = formData['accessAllowedGroups[]'];
            formData.accessAllowedGroups = accessGroupsRaw === undefined
                ? []
                : (Array.isArray(accessGroupsRaw) ? accessGroupsRaw : [accessGroupsRaw]);
            delete formData['accessAllowedGroups[]'];
        } else {
            delete formData['accessAllowedGroups[]'];
            delete formData.accessAllowedGroups;
        }
        if (hasField('accessAllowedUserIds[]') || hasField('accessAllowedUserIds')) {
            const accessUsersRaw = formData['accessAllowedUserIds[]'];
            formData.accessAllowedUserIds = accessUsersRaw === undefined
                ? []
                : (Array.isArray(accessUsersRaw) ? accessUsersRaw : [accessUsersRaw]);
            delete formData['accessAllowedUserIds[]'];
        } else {
            delete formData['accessAllowedUserIds[]'];
            delete formData.accessAllowedUserIds;
        }
        if (hasField('accessRestrictionEnabled')) {
            formData.accessRestrictionEnabled = String(formData.accessRestrictionEnabled || '0') === '1';
        } else {
            delete formData.accessRestrictionEnabled;
        }
        if (hasField('appAdminUserIds[]') || hasField('appAdminUserIds')) {
            const appAdminRaw = formData['appAdminUserIds[]'];
            formData.appAdminUserIds = appAdminRaw === undefined
                ? []
                : (Array.isArray(appAdminRaw) ? appAdminRaw : [appAdminRaw]);
            delete formData['appAdminUserIds[]'];
        } else {
            delete formData['appAdminUserIds[]'];
            delete formData.appAdminUserIds;
        }
        if (hasField('requireSubstituteTypes[]') || hasField('requireSubstituteTypes')) {
            const requireSubstituteRaw = formData['requireSubstituteTypes[]'];
            formData.requireSubstituteTypes = requireSubstituteRaw === undefined
                ? []
                : (Array.isArray(requireSubstituteRaw) ? requireSubstituteRaw : [requireSubstituteRaw]);
            delete formData['requireSubstituteTypes[]'];
        } else {
            delete formData['requireSubstituteTypes[]'];
            delete formData.requireSubstituteTypes;
        }

        // Convert localized decimal numbers (use defaults on invalid/empty) — only if present.
        const parseLocalizedNumber = (v) => {
            if (v === undefined || v === null || String(v).trim() === '') {
                return Number.NaN;
            }
            const normalized = String(v).trim().replace(/\s+/g, '').replace(',', '.');
            const n = Number(normalized);
            return Number.isFinite(n) ? n : Number.NaN;
        };
        const num = (v, def) => {
            const n = parseLocalizedNumber(v);
            return Number.isFinite(n) ? n : def;
        };
        const int = (v, def) => { const n = parseInt(String(v), 10); return (Number.isInteger(n) ? n : def); };
        if (hasField('maxDailyHours')) {
            formData.maxDailyHours = num(formData.maxDailyHours, 10);
        } else {
            delete formData.maxDailyHours;
        }
        if (hasField('minRestPeriod')) {
            formData.minRestPeriod = num(formData.minRestPeriod, 11);
        } else {
            delete formData.minRestPeriod;
        }
        if (hasField('defaultWorkingHours')) {
            formData.defaultWorkingHours = num(formData.defaultWorkingHours, 8);
        } else {
            delete formData.defaultWorkingHours;
        }
        if (hasField('breakAutoFallbackMinutes')) {
            formData.breakAutoFallbackMinutes = int(formData.breakAutoFallbackMinutes, 180);
            if (formData.breakAutoFallbackMinutes < 15) {
                formData.breakAutoFallbackMinutes = 15;
            }
            if (formData.breakAutoFallbackMinutes > 720) {
                formData.breakAutoFallbackMinutes = 720;
            }
        } else {
            delete formData.breakAutoFallbackMinutes;
        }
        if (hasField('breakAutoFallbackFlexWindowStart')) {
            formData.breakAutoFallbackFlexWindowStart = int(formData.breakAutoFallbackFlexWindowStart, 11);
        } else {
            delete formData.breakAutoFallbackFlexWindowStart;
        }
        if (hasField('breakAutoFallbackFlexWindowEnd')) {
            formData.breakAutoFallbackFlexWindowEnd = int(formData.breakAutoFallbackFlexWindowEnd, 16);
        } else {
            delete formData.breakAutoFallbackFlexWindowEnd;
        }
        if (hasField('retentionPeriod')) {
            formData.retentionPeriod = int(formData.retentionPeriod, 2);
        } else {
            delete formData.retentionPeriod;
        }
        const graceInput = Utils.$('#monthClosureGraceDaysAfterEom');
        if (graceInput) {
            formData.monthClosureGraceDaysAfterEom = int(graceInput.value, 0);
        } else {
            delete formData.monthClosureGraceDaysAfterEom;
        }
        // Only submit the Swiss weekly absolute max when country is CH — a
        // disabled select is omitted from FormData; also strip stale values.
        const nextCountryForWeekly = formData.country ? String(formData.country).toUpperCase() : 'DE';
        if (nextCountryForWeekly === 'CH' && formData.weeklyAbsoluteMaxHours !== undefined) {
            formData.weeklyAbsoluteMaxHours = int(formData.weeklyAbsoluteMaxHours, 45);
        } else {
            delete formData.weeklyAbsoluteMaxHours;
        }

        const liveRegion = Utils.$('#admin-settings-live');
        const saveButton = Utils.$('#admin-settings-save');
        if (saveButton && (saveButton.disabled || saveButton.getAttribute('aria-busy') === 'true')) {
            return;
        }

        // Validate
        if (!validateForm(formData, liveRegion)) {
            return;
        }

        // E-8: country change (including “downgrade” AT/CH → DE) needs an explicit confirm.
        // Fail closed when confirmDialog is unavailable — never use window.confirm (§7.3).
        const initialCountry = (form.getAttribute('data-initial-country') || 'DE').toUpperCase();
        const nextCountry = formData.country ? String(formData.country).toUpperCase() : initialCountry;
        if (nextCountry !== initialCountry) {
            const Components = window.AzcComponents || window.ArbeitszeitCheckComponents;
            const t = (msg) => (window.t ? window.t('arbeitszeitcheck', msg) : msg);
            const message = [
                t('Working time rules will follow the newly selected country from now on.'),
                t('The default holiday region is reset when it does not belong to the new country. Existing holiday calendars of other countries stay in the database.'),
                t('Daily hour and rest limits you already set are kept. You can switch back to another country later the same way.'),
            ].join('\n\n');
            if (!Components || typeof Components.confirmDialog !== 'function') {
                setLiveMessage(
                    liveRegion,
                    t('Could not show the country-change confirmation. Please reload the page and try again.'),
                    'error'
                );
                return;
            }
            // Lock before await so a second Enter cannot start another confirm+save.
            adminSettingsSaveInflight = true;
            if (saveButton) {
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
            }
            const result = await Components.confirmDialog({
                title: t('Change working time country?'),
                message: message,
                confirmLabel: t('Change country'),
                cancelLabel: t('Cancel'),
                variant: 'info',
            });
            const accepted = result === true || !!(result && result.confirmed);
            if (!accepted) {
                adminSettingsSaveInflight = false;
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.removeAttribute('aria-busy');
                }
                return;
            }
        } else {
            adminSettingsSaveInflight = true;
            if (saveButton) {
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
            }
        }
        setLiveMessage(liveRegion, '', null);

        // Submit (use server-generated URL for subpath compatibility)
        Utils.ajax(
            (window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminSettingsApiUrl)
                || buildApiUrl('/apps/arbeitszeitcheck/api/admin/settings'),
            {
            method: 'POST',
            data: formData,
            onSuccess: function(data) {
                adminSettingsSaveInflight = false;
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.removeAttribute('aria-busy');
                }
                if (data.success) {
                    const msg = data.message || window.ArbeitszeitCheck?.l10n?.settingsSavedSuccessfully || (window.t ? window.t('arbeitszeitcheck', 'Settings saved successfully') : 'Settings saved successfully');
                    Messaging.showSuccess(msg);
                    setLiveMessage(liveRegion, msg, 'success');
                    if (data.settings && data.settings.country) {
                        form.setAttribute('data-initial-country', String(data.settings.country).toUpperCase());
                    }
                } else {
                    const msg = data.error || window.ArbeitszeitCheck?.l10n?.failedToSaveSettings || (window.t ? window.t('arbeitszeitcheck', 'Failed to save settings') : 'Failed to save settings');
                    Messaging.showError(msg);
                    setLiveMessage(liveRegion, msg, 'error');
                }
            },
            onError: function(_error) {
                adminSettingsSaveInflight = false;
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.removeAttribute('aria-busy');
                }
                const msg = window.ArbeitszeitCheck?.l10n?.errorSavingSettings || (window.t ? window.t('arbeitszeitcheck', 'An error occurred while saving settings') : 'An error occurred while saving settings');
                Messaging.showError(msg);
                setLiveMessage(liveRegion, msg, 'error');
            }
        });
    }

    /**
     * Searchable user picker for month reopen (GET /api/admin/users?picker=1).
     */
    function initMonthReopenUserPicker() {
        const search = Utils.$('#monthClosureReopenUserSearch');
        const hidden = Utils.$('#monthClosureReopenUserId');
        const list = Utils.$('#monthClosureReopenUserListbox');
        // Single-topic settings pages (e.g. outlook-subscription) omit month-closure
        // controls — skip quietly instead of surfacing a misleading user-search error.
        if (!search || !hidden || !list) {
            return;
        }

        const initPicker = window.ArbeitszeitCheck && window.ArbeitszeitCheck.initAdminUserPicker;
        const baseUrl = window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminUsersListUrl;
        const l10n = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n ? window.ArbeitszeitCheck.l10n : {};
        if (typeof initPicker !== 'function' || !baseUrl) {
            Messaging && Messaging.showError && Messaging.showError(
                l10n.monthReopenSearchUnavailable || l10n.searchError || 'Employee search is unavailable. Reload the page.'
            );
            return;
        }
        monthReopenPicker = initPicker({
            hiddenSelector: '#monthClosureReopenUserId',
            searchSelector: '#monthClosureReopenUserSearch',
            listSelector: '#monthClosureReopenUserListbox',
            wrapSelector: '#month-reopen-picker',
            statusSelector: '#monthClosureReopenUserStatus',
            searchUrl: baseUrl,
            limit: 20,
            minQueryLength: 2,
            idPrefix: 'month-reopen-user',
            l10n: l10n,
        });
        if (!monthReopenPicker) {
            Messaging && Messaging.showError && Messaging.showError(
                l10n.monthReopenSearchUnavailable || l10n.searchError || 'Employee search is unavailable. Reload the page.'
            );
        }
    }

    /**
     * Admin: reopen a finalized calendar month for an employee (revision-safe closure).
     */
    async function handleMonthReopen() {
        const userEl = Utils.$('#monthClosureReopenUserId');
        const yearEl = Utils.$('#monthClosureReopenYear');
        const monthEl = Utils.$('#monthClosureReopenMonth');
        const reasonEl = Utils.$('#monthClosureReopenReason');
        const live = Utils.$('#monthClosureReopenLive');
        const btn = Utils.$('#monthClosureReopenBtn');
        const l10n = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n ? window.ArbeitszeitCheck.l10n : {};
        const userId = (monthReopenPicker && typeof monthReopenPicker.getUserId === 'function')
            ? monthReopenPicker.getUserId()
            : (userEl && userEl.value ? String(userEl.value).trim() : '');
        const reason = reasonEl && reasonEl.value ? String(reasonEl.value).trim() : '';
        const year = yearEl ? parseInt(String(yearEl.value), 10) : NaN;
        const month = monthEl ? parseInt(String(monthEl.value), 10) : NaN;

        if (!userId || !reason || !Number.isInteger(year) || !Number.isInteger(month)) {
            Messaging.showError(l10n.monthReopenFillAll || 'Please select an employee, and enter year, month, and a reason.');
            return;
        }
        if (year < 1970 || year > 2100 || month < 1 || month > 12) {
            Messaging.showError(window.t ? window.t('arbeitszeitcheck', 'Invalid month') : 'Invalid month');
            return;
        }
        const confirmMsg = l10n.monthReopenConfirm || 'Reopen this finalized month?';
        const confirmed = await Utils.confirmDestructiveAction({
            title: l10n.monthReopenConfirmTitle || 'Reopen month',
            message: confirmMsg,
            confirmLabel: l10n.monthReopenConfirmAction || 'Reopen',
            variant: 'destructive',
        });
        if (!confirmed) {
            return;
        }
        const url = window.ArbeitszeitCheck && window.ArbeitszeitCheck.monthClosureReopenUrl;
        if (!url) {
            return;
        }
        if (btn) {
            btn.disabled = true;
        }
        if (live) {
            live.textContent = '';
        }
        Utils.ajax(url, {
            method: 'POST',
            data: { userId: userId, year: year, month: month, reason: reason },
            onSuccess: function (data) {
                if (data && data.success) {
                    const ok = l10n.monthReopenSuccess || 'Month reopened.';
                    Messaging.showSuccess(ok);
                    if (live) {
                        live.textContent = ok;
                    }
                    if (monthReopenPicker && typeof monthReopenPicker.clear === 'function') {
                        monthReopenPicker.clear();
                    } else {
                        const searchEl = Utils.$('#monthClosureReopenUserSearch');
                        if (userEl) {
                            userEl.value = '';
                        }
                        if (searchEl) {
                            searchEl.value = '';
                        }
                    }
                    if (reasonEl) {
                        reasonEl.value = '';
                    }
                } else {
                    Messaging.showError((data && data.error) ? data.error : 'Error');
                }
                if (btn) {
                    btn.disabled = false;
                }
            },
            onError: function (err) {
                const msg = (err && err.error) ? err.error : ((err && err.message) ? err.message : 'Error');
                Messaging.showError(msg);
                if (live) {
                    live.textContent = msg;
                }
                if (btn) {
                    btn.disabled = false;
                }
            }
        });
    }

    /**
     * Validate form data
     */
    function validateForm(data, liveRegion) {
        const fail = function (msg, focusId) {
            Messaging.showError(msg);
            setLiveMessage(liveRegion, msg, 'error');
            if (focusId) {
                const el = document.getElementById(focusId);
                if (el) {
                    el.focus();
                }
            }
            return false;
        };

        if (data.maxDailyHours !== undefined && (data.maxDailyHours < 1 || data.maxDailyHours > 24)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.maxDailyHoursRange || (window.t && window.t('arbeitszeitcheck', 'Maximum daily hours must be between 1 and 24')) || 'Maximum daily hours must be between 1 and 24',
                'maxDailyHours'
            );
        }

        if (data.minRestPeriod !== undefined && (data.minRestPeriod < 1 || data.minRestPeriod > 24)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.minRestPeriodRange || (window.t && window.t('arbeitszeitcheck', 'Minimum rest period must be between 1 and 24 hours')) || 'Minimum rest period must be between 1 and 24 hours',
                'minRestPeriod'
            );
        }

        if (data.defaultWorkingHours !== undefined && (data.defaultWorkingHours < 1 || data.defaultWorkingHours > 24)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.defaultWorkingHoursRange || (window.t && window.t('arbeitszeitcheck', 'Default working hours must be between 1 and 24')) || 'Default working hours must be between 1 and 24',
                'defaultWorkingHours'
            );
        }

        if (data.retentionPeriod !== undefined && (data.retentionPeriod < 1 || data.retentionPeriod > 10)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.retentionPeriodRange || (window.t && window.t('arbeitszeitcheck', 'Retention period must be between 1 and 10 years')) || 'Retention period must be between 1 and 10 years',
                'retentionPeriod'
            );
        }

        if (data.vacationCarryoverExpiryMonth !== undefined && (data.vacationCarryoverExpiryMonth < 1 || data.vacationCarryoverExpiryMonth > 12)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.carryoverMonthRange || (window.t && window.t('arbeitszeitcheck', 'Carryover expiry month must be between 1 and 12')) || 'Carryover expiry month must be between 1 and 12',
                'vacationCarryoverExpiryMonth'
            );
        }
        if (data.vacationCarryoverExpiryDay !== undefined && (data.vacationCarryoverExpiryDay < 1 || data.vacationCarryoverExpiryDay > 31)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.carryoverDayRange || (window.t && window.t('arbeitszeitcheck', 'Carryover expiry day must be between 1 and 31')) || 'Carryover expiry day must be between 1 and 31',
                'vacationCarryoverExpiryDay'
            );
        }

        if (data.monthClosureGraceDaysAfterEom !== undefined && (data.monthClosureGraceDaysAfterEom < 0 || data.monthClosureGraceDaysAfterEom > 90)) {
            return fail(
                window.ArbeitszeitCheck?.l10n?.monthClosureGraceDaysRange || (window.t && window.t('arbeitszeitcheck', 'Grace days after month end must be between 0 and 90')) || 'Grace days after month end must be between 0 and 90',
                'monthClosureGraceDaysAfterEom'
            );
        }

        if (data.clockStampingEnabled !== undefined || data.manualTimeEntryEnabled !== undefined) {
            if (!data.clockStampingEnabled && !data.manualTimeEntryEnabled) {
                return fail(
                    window.ArbeitszeitCheck?.l10n?.timeCaptureAtLeastOneOrg || (window.t && window.t('arbeitszeitcheck', 'Enable clock in/out or manual time entries — at least one method is required for the organisation.')) || 'Enable clock in/out or manual time entries — at least one method is required for the organisation.',
                    'clockStampingEnabled'
                );
            }
        }

        const capRaw = data.vacationCarryoverMaxDays;
        if (capRaw !== undefined && capRaw !== null && String(capRaw).trim() !== '') {
            const cap = parseFloat(String(capRaw).replace(',', '.'));
            if (!Number.isFinite(cap) || cap < 0 || cap > 366) {
                return fail(
                    window.ArbeitszeitCheck?.l10n?.maxCarryoverDaysRange || (window.t && window.t('arbeitszeitcheck', 'Maximum carryover days must be empty (unlimited) or between 0 and 366')) || 'Maximum carryover days must be empty (unlimited) or between 0 and 366',
                    'vacationCarryoverMaxDays'
                );
            }
        }

        return true;
    }

    /**
     * Validate individual field
     */
    function validateField(field) {
        const normalized = String(field.value || '').trim().replace(/\s+/g, '').replace(',', '.');
        const value = Number(normalized);
        const min = parseFloat(field.getAttribute('min'));
        const max = parseFloat(field.getAttribute('max'));

        if (isNaN(value) || value < min || value > max) {
            let msg = window.ArbeitszeitCheck?.l10n?.valueBetweenMinMax;
            if (msg) {
                msg = msg.replace('{min}', String(min)).replace('{max}', String(max));
            } else {
                msg = window.t ? window.t('arbeitszeitcheck', 'Value must be between {min} and {max}', {min: String(min), max: String(max)}) : 'Value must be between ' + min + ' and ' + max;
            }
            Validation.showFieldError(field, msg);
            return false;
        } else {
            Validation.clearFieldError(field);
            return true;
        }
    }

    // Initialize on DOM ready
    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckAdminSettingsTestables = {
            handleMonthReopen: handleMonthReopen,
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
