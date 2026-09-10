/**
 * Admin Holidays JavaScript for arbeitszeitcheck app
 *
 * Manages the UI for additional company holidays (Feiertage & Kalender).
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || window.AzcMessaging || {};

    function showUserError(message) {
        if (Messaging && typeof Messaging.showError === 'function') {
            Messaging.showError(message);
            return;
        }
        const region = document.getElementById('azc-alert-region');
        if (region) {
            region.textContent = String(message);
        }
    }

    function showUserSuccess(message) {
        if (Messaging && typeof Messaging.showSuccess === 'function') {
            Messaging.showSuccess(message);
            return;
        }
        const region = document.getElementById('azc-live-region');
        if (region) {
            region.textContent = String(message);
        }
    }

    const HOLIDAYS_UI_JSON_ID = 'arbeitszeitcheck-admin-holidays-ui-strings';
    const HOLIDAYS_CONFIG_JSON_ID = 'arbeitszeitcheck-admin-holidays-config';

    let holidaysUiStringsFromDomApplied = false;
    let holidaysPageConfig = null;

    /**
     * Load translated strings from the JSON script at the bottom of admin-holidays.php.
     * Ensures server translations win over window.t fallbacks once the DOM node exists.
     */
    function ensureHolidaysUiStrings() {
        if (holidaysUiStringsFromDomApplied) {
            return;
        }
        const el = document.getElementById(HOLIDAYS_UI_JSON_ID);
        if (!el || !el.textContent || !el.textContent.trim()) {
            return;
        }
        try {
            const parsed = JSON.parse(el.textContent);
            if (parsed && typeof parsed === 'object') {
                window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
                window.ArbeitszeitCheck.holidaysUiStrings = parsed;
                holidaysUiStringsFromDomApplied = true;
            }
        } catch (e) {
            console.error('[admin-holidays] Could not parse holidays UI translations', e);
        }
    }

    function getHolidaysPageConfig() {
        if (holidaysPageConfig !== null) {
            return holidaysPageConfig;
        }
        holidaysPageConfig = { statutoryAutoReseed: true, settingsUrl: '', country: 'DE', defaultState: 'NW' };
        const el = document.getElementById(HOLIDAYS_CONFIG_JSON_ID);
        if (el && el.textContent && el.textContent.trim()) {
            try {
                const parsed = JSON.parse(el.textContent);
                if (parsed && typeof parsed === 'object') {
                    holidaysPageConfig = {
                        statutoryAutoReseed: parsed.statutoryAutoReseed !== false,
                        settingsUrl: typeof parsed.settingsUrl === 'string' ? parsed.settingsUrl : '',
                        country: typeof parsed.country === 'string' && parsed.country !== '' ? parsed.country : 'DE',
                        defaultState: typeof parsed.defaultState === 'string' && parsed.defaultState !== '' ? parsed.defaultState : 'NW',
                    };
                }
            } catch (e) {
                console.error('[admin-holidays] Could not parse holidays page config', e);
            }
        }
        return holidaysPageConfig;
    }

    /** 'BW' → 'DE' (legacy codes without a dash are German); 'AT-W' → 'AT'. */
    function countryOfRegion(code) {
        const value = String(code || '');
        const idx = value.indexOf('-');
        return idx === -1 ? 'DE' : value.slice(0, idx);
    }

    /**
     * Rebuild a <select> from region data for the given country.
     * Prefers preferredRegion when it belongs to the country; otherwise the country default.
     *
     * @param {HTMLSelectElement} select
     * @param {string} country
     * @param {{regionsByCountry?: Object, defaultRegionByCountry?: Object}} regionData
     * @param {string} [preferredRegion]
     * @returns {string} selected region code
     */
    function rebuildRegionSelect(select, country, regionData, preferredRegion) {
        const regionsByCountry = (regionData && regionData.regionsByCountry) || {};
        const defaultByCountry = (regionData && regionData.defaultRegionByCountry) || {};
        const regions = regionsByCountry[country] || [];
        if (!select || regions.length === 0) {
            return preferredRegion || '';
        }
        const target = regions.some(function(r) { return r.code === preferredRegion; })
            ? preferredRegion
            : (defaultByCountry[country] || regions[0].code);
        select.textContent = '';
        regions.forEach(function(region) {
            const option = document.createElement('option');
            option.value = region.code;
            option.textContent = region.label;
            option.selected = region.code === target;
            select.appendChild(option);
        });
        select.value = target;
        return target;
    }

    function parseRegionDataFromDom() {
        const dataEl = document.getElementById('azc-holidays-region-data');
        if (!dataEl || !dataEl.textContent || !dataEl.textContent.trim()) {
            return { regionsByCountry: {}, defaultRegionByCountry: {} };
        }
        try {
            const parsed = JSON.parse(dataEl.textContent);
            if (parsed && typeof parsed === 'object') {
                return {
                    regionsByCountry: parsed.regionsByCountry || {},
                    defaultRegionByCountry: parsed.defaultRegionByCountry || {},
                };
            }
        } catch (e) {
            console.error('[admin-holidays] Could not parse region data', e);
        }
        return { regionsByCountry: {}, defaultRegionByCountry: {} };
    }

    function isStatutoryAutoReseedEnabled() {
        const cfg = getHolidaysPageConfig();
        return cfg.statutoryAutoReseed !== false;
    }

    /** Prefer server-injected strings; window.t is not always available in this view. */
    function tAzc(msgid) {
        ensureHolidaysUiStrings();
        const map = window.ArbeitszeitCheck && window.ArbeitszeitCheck.holidaysUiStrings;
        if (map && Object.prototype.hasOwnProperty.call(map, msgid) && map[msgid] !== undefined && map[msgid] !== '') {
            return map[msgid];
        }
        if (typeof window.t === 'function') {
            return window.t('arbeitszeitcheck', msgid);
        }
        return msgid;
    }

    let initialized = false;
    let regionDataCache = null;
    let persistedCountry = 'DE';
    let savingCountryRegion = false;

    function init() {
        if (initialized) {
            return;
        }
        if (!Utils || typeof Utils.$ !== 'function') {
            // Shared utils not loaded yet (or Vitest unit harness) — skip page boot.
            return;
        }
        initialized = true;
        ensureHolidaysUiStrings();
        regionDataCache = parseRegionDataFromDom();
        const cfg = getHolidaysPageConfig();
        persistedCountry = String(cfg.country || 'DE').toUpperCase();
        const card = document.querySelector('[data-initial-country]');
        if (card) {
            persistedCountry = String(card.getAttribute('data-initial-country') || persistedCountry).toUpperCase();
        }
        bindEvents();
        loadExistingHolidays();
    }

    function getSelectedState() {
        const select = document.getElementById('holiday-state-select');
        return select ? select.value : 'NW';
    }

    function getSelectedYear() {
        const select = document.getElementById('holiday-year-select');
        if (!select) {
            return new Date().getFullYear();
        }
        const val = parseInt(select.value, 10);
        return Number.isNaN(val) ? (new Date().getFullYear()) : val;
    }

    function setCountryRegionControlsBusy(busy) {
        const defaultStateSelect = Utils.$('#holiday-default-state');
        if (defaultStateSelect) {
            defaultStateSelect.disabled = !!busy;
            if (busy) {
                defaultStateSelect.setAttribute('aria-busy', 'true');
            } else {
                defaultStateSelect.removeAttribute('aria-busy');
            }
        }
        const radios = document.querySelectorAll('input[name="holidayCountry"]');
        radios.forEach(function(radio) {
            radio.disabled = !!busy;
        });
    }

    function announceRegionLive(regionCode) {
        const live = Utils.$('#holiday-country-region-live');
        if (!live) {
            return;
        }
        const select = Utils.$('#holiday-default-state');
        let label = regionCode || '';
        if (select && select.options && select.selectedIndex >= 0) {
            label = select.options[select.selectedIndex].textContent || label;
        }
        live.textContent = tAzc('Region list updated. Default region: %s').replace('%s', label);
    }

    function syncCalendarViewerToRegion(regionCode) {
        const calendarSelect = Utils.$('#holiday-state-select');
        if (!calendarSelect || !regionCode) {
            return;
        }
        const option = Array.prototype.find.call(calendarSelect.options || [], function(opt) {
            return opt.value === regionCode;
        });
        if (!option) {
            return;
        }
        calendarSelect.value = regionCode;
        calendarSelect.setAttribute('data-last-value', regionCode);
        loadExistingHolidays();
    }

    function bindEvents() {
        if (!Utils || typeof Utils.$ !== 'function') {
            return;
        }
        const filterForm = Utils.$('#holiday-calendar-filters');
        if (filterForm) {
            Utils.on(filterForm, 'submit', function(event) {
                event.preventDefault();
            });
        }
        const addBtn = Utils.$('#holiday-add-entry');
        if (addBtn) {
            Utils.on(addBtn, 'click', handleAddHolidayClick);
        }
        const stateSelect = Utils.$('#holiday-state-select');
        const yearSelect = Utils.$('#holiday-year-select');
        if (stateSelect) {
            stateSelect.setAttribute('data-last-value', stateSelect.value);
            Utils.on(stateSelect, 'change', function() {
                handleRegionChange(stateSelect);
            });
        }
        if (yearSelect) {
            Utils.on(yearSelect, 'change', loadExistingHolidays);
        }
        const defaultStateSelect = Utils.$('#holiday-default-state');
        if (defaultStateSelect) {
            // Remember the last persisted value so we can roll back on failure.
            defaultStateSelect.setAttribute('data-last-value', defaultStateSelect.value);
            Utils.on(defaultStateSelect, 'change', function() {
                saveDefaultState(defaultStateSelect);
            });
        }
        bindCountryRadios();
    }

    function bindCountryRadios() {
        const radios = document.querySelectorAll('input[name="holidayCountry"]');
        if (radios.length === 0) {
            return;
        }
        radios.forEach(function(radio) {
            Utils.on(radio, 'change', function() {
                if (!this.checked) {
                    return;
                }
                handleCountryChange(this);
            });
        });
    }

    /**
     * Country change on the holidays page (E-8). Confirm first, then persist
     * country + default region together. Esc/Cancel restores the previous radio.
     */
    async function handleCountryChange(radio) {
        const nextCountry = String(radio.value || '').toUpperCase();
        const previousCountry = persistedCountry;
        if (nextCountry === previousCountry) {
            return;
        }
        if (savingCountryRegion || countryConfirmInFlight) {
            revertCountryRadio(previousCountry);
            return;
        }

        const Components = window.AzcComponents || window.ArbeitszeitCheckComponents;
        if (!Components || typeof Components.confirmDialog !== 'function') {
            revertCountryRadio(previousCountry);
            showUserError(tAzc('Could not show the country-change confirmation. Please reload the page and try again.'));
            return;
        }

        countryConfirmInFlight = true;
        setCountryRegionControlsBusy(true);

        const message = [
            tAzc('Working time rules will follow the newly selected country from now on.'),
            tAzc('The default holiday region is reset when it does not belong to the new country. Existing holiday calendars of other countries stay in the database.'),
            tAzc('Daily hour and rest limits you already set are kept. You can switch back to another country later the same way.'),
        ].join('\n\n');

        let accepted = false;
        try {
            const result = await Components.confirmDialog({
                title: tAzc('Change working time country?'),
                message: message,
                confirmLabel: tAzc('Change country'),
                cancelLabel: tAzc('Cancel'),
                variant: 'info',
            });
            accepted = result === true || !!(result && result.confirmed);
        } finally {
            countryConfirmInFlight = false;
            setCountryRegionControlsBusy(false);
        }

        if (!accepted) {
            revertCountryRadio(previousCountry);
            return;
        }

        const defaultStateSelect = Utils.$('#holiday-default-state');
        const nextRegion = rebuildRegionSelect(
            defaultStateSelect,
            nextCountry,
            regionDataCache || parseRegionDataFromDom(),
            defaultStateSelect ? defaultStateSelect.value : ''
        );
        announceRegionLive(nextRegion);
        await persistCountryAndRegion(nextCountry, nextRegion, previousCountry);
    }

    function revertCountryRadio(country) {
        const target = document.getElementById('holiday-country-' + String(country || 'DE').toLowerCase());
        if (target) {
            target.checked = true;
        } else {
            const radios = document.querySelectorAll('input[name="holidayCountry"]');
            radios.forEach(function(r) {
                r.checked = String(r.value).toUpperCase() === String(country).toUpperCase();
            });
        }
    }

    function persistCountryAndRegion(country, region, previousCountry) {
        if (savingCountryRegion) {
            return Promise.resolve(false);
        }
        savingCountryRegion = true;
        setCountryRegionControlsBusy(true);

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/settings');
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ country: country, germanState: region })
        }).then(function(response) {
            return response.json().catch(function() { return null; });
        }).then(function(data) {
            savingCountryRegion = false;
            setCountryRegionControlsBusy(false);

            if (data && data.success) {
                const savedCountry = data.settings && data.settings.country
                    ? String(data.settings.country).toUpperCase()
                    : country;
                const savedRegion = data.settings && data.settings.germanState
                    ? String(data.settings.germanState)
                    : region;
                persistedCountry = savedCountry;
                holidaysPageConfig = holidaysPageConfig || getHolidaysPageConfig();
                holidaysPageConfig.country = savedCountry;
                holidaysPageConfig.defaultState = savedRegion;
                const card = document.querySelector('[data-initial-country]');
                if (card) {
                    card.setAttribute('data-initial-country', savedCountry);
                }
                const defaultStateSelect = Utils.$('#holiday-default-state');
                if (defaultStateSelect) {
                    if (defaultStateSelect.value !== savedRegion) {
                        rebuildRegionSelect(defaultStateSelect, savedCountry, regionDataCache || parseRegionDataFromDom(), savedRegion);
                    }
                    defaultStateSelect.setAttribute('data-last-value', savedRegion);
                }
                showUserSuccess(tAzc('Country and region were saved.'));
                syncCalendarViewerToRegion(savedRegion);
                return true;
            }

            revertCountryRadio(previousCountry);
            const defaultStateSelect = Utils.$('#holiday-default-state');
            if (defaultStateSelect) {
                rebuildRegionSelect(
                    defaultStateSelect,
                    previousCountry,
                    regionDataCache || parseRegionDataFromDom(),
                    defaultStateSelect.getAttribute('data-last-value') || ''
                );
            }
            const errorMsg = (data && data.error) || tAzc('The country and region could not be saved.');
            showUserError(errorMsg);
            return false;
        }).catch(function() {
            savingCountryRegion = false;
            setCountryRegionControlsBusy(false);
            revertCountryRadio(previousCountry);
            const defaultStateSelect = Utils.$('#holiday-default-state');
            if (defaultStateSelect) {
                rebuildRegionSelect(
                    defaultStateSelect,
                    previousCountry,
                    regionDataCache || parseRegionDataFromDom(),
                    defaultStateSelect.getAttribute('data-last-value') || ''
                );
            }
            showUserError(tAzc('The country and region could not be saved.'));
            return false;
        });
    }

    /**
     * Region change in the calendar viewer. Crossing a country border shows a
     * plain-language confirmation first (viewing lazily seeds that country's
     * statutory holidays); Esc/Cancel reverts the selection.
     */
    async function handleRegionChange(select) {
        const previous = select.getAttribute('data-last-value') || select.value;
        const next = select.value;
        if (next === previous) {
            return;
        }

        if (countryOfRegion(next) !== countryOfRegion(previous)) {
            const message = [
                tAzc('The statutory holidays of the selected region will be added to the calendar automatically.'),
                tAzc('Working time rules are not affected — they follow the country configured for the whole organisation.'),
                tAzc('You can switch back to any other region at any time.'),
            ].join('\n\n');

            let accepted = false;
            const Components = window.AzcComponents || window.ArbeitszeitCheckComponents;
            if (Components && typeof Components.confirmDialog === 'function') {
                const result = await Components.confirmDialog({
                    title: tAzc('Show holidays of another country?'),
                    message: message,
                    confirmLabel: tAzc('Show region'),
                    cancelLabel: tAzc('Cancel'),
                    variant: 'info',
                });
                accepted = result === true || !!(result && result.confirmed);
            }
            if (!accepted) {
                select.value = previous;
                return;
            }
        }

        select.setAttribute('data-last-value', next);
        loadExistingHolidays();
    }

    let savingDefaultState = false;

    function saveDefaultState(select) {
        if (!select || savingDefaultState || savingCountryRegion) {
            return;
        }
        const value = select.value;
        const previous = select.getAttribute('data-last-value') || value;
        if (value === previous) {
            return;
        }

        // Guard: default region must belong to the persisted organisation country.
        if (countryOfRegion(value) !== persistedCountry) {
            select.value = previous;
            showUserError(tAzc('The selected region does not belong to the selected country'));
            return;
        }

        savingDefaultState = true;
        setCountryRegionControlsBusy(true);

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/settings');
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ germanState: value })
        }).then(function(response) {
            return response.json().catch(function() { return null; });
        }).then(function(data) {
            savingDefaultState = false;
            setCountryRegionControlsBusy(false);

            if (data && data.success) {
                select.setAttribute('data-last-value', value);
                if (holidaysPageConfig) {
                    holidaysPageConfig.defaultState = value;
                }
                showUserSuccess(tAzc('Default region was saved.'));
                syncCalendarViewerToRegion(value);
            } else {
                select.value = previous;
                const errorMsg = (data && data.error) || tAzc('The default region could not be saved.');
                showUserError(errorMsg);
            }
        }).catch(function() {
            savingDefaultState = false;
            setCountryRegionControlsBusy(false);
            select.value = previous;
            showUserError(tAzc('The default region could not be saved.'));
        });
    }

    function handleAddHolidayClick(e) {
        e.preventDefault();
        const tbody = Utils.$('#holiday-tbody');
        if (!tbody) {
            return;
        }

        const row = document.createElement('tr');

        // Datum
        const dateCell = document.createElement('td');
        dateCell.setAttribute('data-label', tAzc('Date'));
        const dateInput = document.createElement('input');
        dateInput.type = 'text';
        dateInput.name = 'date';
        dateInput.required = true;
        dateInput.className = 'form-input datepicker-input';
        dateInput.placeholder = tAzc('dd.mm.yyyy');
        dateInput.setAttribute('pattern', '\\d{2}\\.\\d{2}\\.\\d{4}');
        dateInput.setAttribute('maxlength', '10');
        dateCell.appendChild(dateInput);

        // Name
        const nameCell = document.createElement('td');
        nameCell.setAttribute('data-label', tAzc('Holiday name'));
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.name = 'name';
        nameInput.required = true;
        nameInput.className = 'form-input';
        nameCell.appendChild(nameInput);

        // Art (voll / halb)
        const typeCell = document.createElement('td');
        typeCell.setAttribute('data-label', tAzc('Type'));
        const typeSelect = document.createElement('select');
        typeSelect.name = 'kind';
        typeSelect.className = 'form-select';
        const optFull = document.createElement('option');
        optFull.value = 'full';
        optFull.textContent = tAzc('Full-day holiday');
        const optHalf = document.createElement('option');
        optHalf.value = 'half';
        optHalf.textContent = tAzc('Half-day holiday');
        typeSelect.appendChild(optFull);
        typeSelect.appendChild(optHalf);
        typeCell.appendChild(typeSelect);

        // Geltungsbereich (scope)
        const scopeCell = document.createElement('td');
        scopeCell.setAttribute('data-label', tAzc('Scope'));
        const scopeSelect = document.createElement('select');
        scopeSelect.name = 'scope';
        scopeSelect.className = 'form-select';
        const scopes = [
            { value: 'company', label: tAzc('Company holiday') },
            { value: 'custom', label: tAzc('custom') },
            { value: 'statutory', label: tAzc('Statutory') }
        ];
        scopes.forEach(function(s) {
            const opt = document.createElement('option');
            opt.value = s.value;
            opt.textContent = s.label;
            scopeSelect.appendChild(opt);
        });
        scopeCell.appendChild(scopeSelect);

        // Aktionen (Speichern / Löschen)
        const actionsCell = document.createElement('td');
        actionsCell.className = 'actions-cell';
        actionsCell.setAttribute('data-label', tAzc('Actions'));
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'azc-btn azc-btn--primary azc-btn--sm';
        saveBtn.textContent = tAzc('Save');
        Utils.on(saveBtn, 'click', function() {
            saveHolidayRow(row);
        });

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'azc-btn azc-btn--secondary azc-btn--sm';
        deleteBtn.textContent = tAzc('Remove');
        Utils.on(deleteBtn, 'click', function() {
            row.remove();
        });

        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'azc-table-actions admin-holidays__row-actions';
        actionsWrap.setAttribute('role', 'group');
        actionsWrap.appendChild(saveBtn);
        actionsWrap.appendChild(deleteBtn);
        actionsCell.appendChild(actionsWrap);

        row.appendChild(dateCell);
        row.appendChild(nameCell);
        row.appendChild(typeCell);
        row.appendChild(scopeCell);
        row.appendChild(actionsCell);

        tbody.appendChild(row);

        // Initialize datepicker with German dd.mm.yyyy format
        if (window.ArbeitszeitCheckDatepicker && window.ArbeitszeitCheckDatepicker.initializeDatepicker) {
            window.ArbeitszeitCheckDatepicker.initializeDatepicker(dateInput, {});
        }

        dateInput.focus();
    }

    function saveHolidayRow(row) {
        const dateInput = row.querySelector('input[name="date"]');
        const nameInput = row.querySelector('input[name="name"]');
        const typeSelect = row.querySelector('select[name="kind"]');
        const scopeSelect = row.querySelector('select[name="scope"]');

        if (!dateInput || !nameInput || !typeSelect || !scopeSelect) {
            const msg = tAzc('Technical error: Required fields for the holiday could not be found.');
            showUserError(msg);
            return;
        }

        const dp = window.ArbeitszeitCheckDatepicker;
        const toISO = dp ? dp.convertEuropeanToISO : function(s) { return s; };

        const payload = {
            id: row.getAttribute('data-id') ? parseInt(row.getAttribute('data-id'), 10) : null,
            state: getSelectedState(),
            year: getSelectedYear(),
            date: toISO(dateInput.value),
            name: nameInput.value,
            kind: typeSelect.value,
            scope: scopeSelect.value
        };

        if (!payload.date || !payload.name) {
            const msg = tAzc('Please specify date and name of the holiday.');
            showUserError(msg);
            return;
        }

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays');
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify(payload)
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (data && data.success) {
                const tbodyEl = Utils.$('#holiday-tbody');
                if (tbodyEl) {
                    tbodyEl.innerHTML = '';
                }
                loadExistingHolidays();
                showUserSuccess(tAzc('Holiday was saved.'));
            } else {
                const errorMsg = (data && data.error) || tAzc('Holiday could not be saved.');
                showUserError(errorMsg);
            }
        }).catch(function() {
            showUserError(tAzc('An error occurred while saving the holiday.'));
        });
    }

    function setResultsBusy(isBusy) {
        const results = document.getElementById('holiday-results');
        if (results) {
            results.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        }
    }

    let holidaysLoadSeq = 0;
    let countryConfirmInFlight = false;

    function loadExistingHolidays() {
        const tbody = Utils.$('#holiday-tbody');
        if (!tbody) {
            return;
        }

        const state = getSelectedState();
        const year = getSelectedYear();
        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays') +
            '?state=' + encodeURIComponent(state) + '&year=' + encodeURIComponent(String(year));
        const seq = ++holidaysLoadSeq;

        tbody.innerHTML = '';
        setResultsBusy(true);

        fetch(url, {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (seq !== holidaysLoadSeq) {
                return; // Stale response — a newer region/year load is in flight.
            }
            setResultsBusy(false);
            if (!data || data.success !== true || !Array.isArray(data.holidays)) {
                renderEmptyHolidaysRow(tbody);
                showUserError(tAzc('Holidays could not be loaded.'));
                return;
            }

            if (data.statutoryAutoReseed !== undefined) {
                holidaysPageConfig = holidaysPageConfig || { statutoryAutoReseed: true, settingsUrl: '' };
                holidaysPageConfig.statutoryAutoReseed = data.statutoryAutoReseed !== false;
            }

            if (data.holidays.length === 0) {
                renderEmptyHolidaysRow(tbody);
                return;
            }

            data.holidays.forEach(function(item) {
                appendExistingHolidayRow(tbody, item);
            });
        }).catch(function() {
            if (seq !== holidaysLoadSeq) {
                return;
            }
            setResultsBusy(false);
            renderEmptyHolidaysRow(tbody);
            showUserError(tAzc('Holidays could not be loaded.'));
        });

        loadHolidaySuggestions(state, year);
    }

    /**
     * "Common additional holidays" below the table: catalog suggestions
     * (never auto-seeded) with one-click add as company holiday. The section
     * stays hidden when the catalog has no suggestions (currently Germany).
     */
    function loadHolidaySuggestions(state, year) {
        const section = document.getElementById('holiday-suggestions-section');
        const list = document.getElementById('holiday-suggestions-list');
        if (!section || !list) {
            return;
        }

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays/suggestions') +
            '?state=' + encodeURIComponent(state) + '&year=' + encodeURIComponent(String(year));

        fetch(url, {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (!data || data.success !== true || !Array.isArray(data.suggestions) || data.suggestions.length === 0) {
                section.hidden = true;
                list.innerHTML = '';
                return;
            }

            const goodFridayNote = document.getElementById('holiday-good-friday-note');
            if (goodFridayNote) {
                goodFridayNote.hidden = data.country !== 'AT';
            }

            list.innerHTML = '';
            data.suggestions.forEach(function(suggestion) {
                list.appendChild(buildSuggestionItem(suggestion, state));
            });
            section.hidden = false;
        }).catch(function() {
            section.hidden = true;
            list.innerHTML = '';
        });
    }

    function formatDisplayDate(isoDate) {
        let displayDate = isoDate || '';
        if (window.ArbeitszeitCheckDatepicker && window.ArbeitszeitCheckDatepicker.convertISOToEuropean) {
            displayDate = window.ArbeitszeitCheckDatepicker.convertISOToEuropean(displayDate);
        } else if (/^\d{4}-\d{2}-\d{2}$/.test(displayDate)) {
            const p = displayDate.split('-');
            displayDate = p[2] + '.' + p[1] + '.' + p[0];
        }
        return displayDate;
    }

    function buildSuggestionItem(suggestion, state) {
        const item = document.createElement('li');
        item.className = 'admin-holidays__suggestion';

        const displayDate = formatDisplayDate(suggestion.date);

        const text = document.createElement('span');
        text.className = 'admin-holidays__suggestion-text';
        text.textContent = displayDate + ' — ' + (suggestion.name || '');
        item.appendChild(text);

        if (suggestion.exists) {
            const badge = document.createElement('span');
            badge.className = 'admin-holidays-badge admin-holidays-badge--company';
            badge.textContent = tAzc('Already in the calendar');
            item.appendChild(badge);
            return item;
        }

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'azc-btn azc-btn--secondary azc-btn--sm';
        addBtn.textContent = tAzc('Add as company holiday');
        addBtn.setAttribute('aria-label', tAzc('Add {name} ({date}) as a company holiday')
            .replace('{name}', suggestion.name || '')
            .replace('{date}', displayDate));
        Utils.on(addBtn, 'click', function() {
            addBtn.disabled = true;
            addBtn.setAttribute('aria-busy', 'true');

            const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays');
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    state: state,
                    date: suggestion.date,
                    name: suggestion.name,
                    kind: (suggestion.kind === 'half') ? 'half' : 'full',
                    scope: 'company'
                })
            }).then(function(response) {
                return response.json();
            }).then(function(data) {
                addBtn.disabled = false;
                addBtn.removeAttribute('aria-busy');
                if (data && data.success) {
                    showUserSuccess(tAzc('Holiday "{name}" was added as a company holiday.')
                        .replace('{name}', suggestion.name || ''));
                    loadExistingHolidays();
                } else {
                    const errorMsg = (data && data.error) || tAzc('Holiday could not be saved.');
                    showUserError(errorMsg);
                }
            }).catch(function() {
                addBtn.disabled = false;
                addBtn.removeAttribute('aria-busy');
                showUserError(tAzc('An error occurred while saving the holiday.'));
            });
        });
        item.appendChild(addBtn);

        return item;
    }

    function appendExistingHolidayRow(tbody, item) {
        const row = document.createElement('tr');

        const dateCell = document.createElement('td');
        dateCell.setAttribute('data-label', tAzc('Date'));
        let displayDate = item.date || '';
        if (window.ArbeitszeitCheckDatepicker && window.ArbeitszeitCheckDatepicker.convertISOToEuropean) {
            displayDate = window.ArbeitszeitCheckDatepicker.convertISOToEuropean(displayDate);
        } else if (/^\d{4}-\d{2}-\d{2}$/.test(displayDate)) {
            const p = displayDate.split('-');
            displayDate = p[2] + '.' + p[1] + '.' + p[0];
        }
        dateCell.textContent = displayDate;

        const nameCell = document.createElement('td');
        nameCell.setAttribute('data-label', tAzc('Holiday name'));
        nameCell.textContent = item.name || '';

        const typeCell = document.createElement('td');
        typeCell.setAttribute('data-label', tAzc('Type'));
        const kindLabel = item.kind === 'half'
            ? tAzc('Half-day holiday')
            : tAzc('Full-day holiday');
        const typeBadge = document.createElement('span');
        typeBadge.className = 'admin-holidays-badge ' + (item.kind === 'half' ? 'admin-holidays-badge--half' : 'admin-holidays-badge--full');
        typeBadge.textContent = kindLabel;
        typeCell.appendChild(typeBadge);

        const scopeCell = document.createElement('td');
        scopeCell.setAttribute('data-label', tAzc('Scope'));
        let scopeLabel = '';
        let scopeBadgeClass = 'admin-holidays-badge--custom';
        if (item.scope === 'statutory') {
            scopeLabel = tAzc('Statutory');
            scopeBadgeClass = 'admin-holidays-badge--statutory';
        } else if (item.scope === 'company') {
            scopeLabel = tAzc('Company holiday');
            scopeBadgeClass = 'admin-holidays-badge--company';
        } else {
            scopeLabel = tAzc('custom');
            scopeBadgeClass = 'admin-holidays-badge--custom';
        }
        const scopeBadge = document.createElement('span');
        scopeBadge.className = 'admin-holidays-badge ' + scopeBadgeClass;
        scopeBadge.textContent = scopeLabel;
        scopeCell.appendChild(scopeBadge);

        const actionsCell = document.createElement('td');
        actionsCell.className = 'actions-cell';
        actionsCell.setAttribute('data-label', tAzc('Actions'));
        {
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'azc-btn azc-btn--secondary azc-btn--sm';
            deleteBtn.textContent = tAzc('Remove');
            const labelTemplate = tAzc('Remove holiday {name} on {date}');
            const ariaLabel = labelTemplate
                .replace('{name}', item.name || '')
                .replace('{date}', displayDate || '');
            deleteBtn.setAttribute('aria-label', ariaLabel);
            Utils.on(deleteBtn, 'click', function() {
                confirmRemoveHoliday(item, row, displayDate);
            });
            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'azc-table-actions admin-holidays__row-actions';
            actionsWrap.setAttribute('role', 'group');
            actionsWrap.appendChild(deleteBtn);
            actionsCell.appendChild(actionsWrap);
        }

        row.appendChild(dateCell);
        row.appendChild(nameCell);
        row.appendChild(typeCell);
        row.appendChild(scopeCell);
        row.appendChild(actionsCell);
        if (item.id) {
            row.setAttribute('data-id', String(item.id));
        }

        tbody.appendChild(row);
    }

    function renderEmptyHolidaysRow(tbody) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'admin-holidays-empty';
        cell.textContent = tAzc('No holidays configured for this year.');
        row.appendChild(cell);
        tbody.appendChild(row);
    }

    /**
     * Confirm then DELETE a holiday row (shared by table actions + Vitest).
     * @param {{id?: number|string, name?: string, scope?: string}} item
     * @param {HTMLElement|null} row
     * @param {string} [displayDate]
     * @returns {Promise<boolean>} whether delete was confirmed
     */
    async function confirmRemoveHoliday(item, row, displayDate) {
        const name = (item && item.name) || '';
        const title = tAzc('Remove holiday');
        const baseMessage = tAzc('Do you really want to remove the holiday "{name}" on {date}?')
            .replace('{name}', name)
            .replace('{date}', displayDate || '');

        let extra = '';
        if (item && item.scope === 'statutory') {
            extra = isStatutoryAutoReseedEnabled()
                ? tAzc('Removed statutory holidays are restored automatically while auto-restore is enabled in settings.')
                : tAzc('Statutory holiday removal is permanent because auto-restore is disabled in settings.');
        }

        // Plain-text message only — confirmDialog escapes HTML (no XSS via holiday names).
        const message = extra ? (extra + '\n\n' + baseMessage) : baseMessage;
        const Components = window.AzcComponents || window.ArbeitszeitCheckComponents;
        let confirmed = false;
        if (Components && typeof Components.confirmDialog === 'function') {
            const result = await Components.confirmDialog({
                title: title,
                message: message,
                confirmLabel: tAzc('Remove'),
                cancelLabel: tAzc('Cancel'),
                variant: 'destructive',
            });
            confirmed = result === true || !!(result && result.confirmed);
        } else if (window.ArbeitszeitCheckUtils && typeof window.ArbeitszeitCheckUtils.confirmDestructiveAction === 'function') {
            confirmed = !!(await window.ArbeitszeitCheckUtils.confirmDestructiveAction({
                title: title,
                message: message,
                confirmLabel: tAzc('Remove'),
                variant: 'destructive',
            }));
        }
        if (confirmed && item && item.id != null) {
            deleteHoliday(item.id, row, item.scope);
        }
        return confirmed;
    }

    function deleteHoliday(id, row, scope) {
        if (!id) {
            row.remove();
            return;
        }

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays/' + encodeURIComponent(String(id)));
        const deleteInit = Utils.normalizeMutatingFetchInit
            ? Utils.normalizeMutatingFetchInit({
                method: 'DELETE',
                headers: { requesttoken: OC.requestToken },
            })
            : {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    requesttoken: OC.requestToken,
                },
                body: JSON.stringify({}),
            };
        fetch(url, deleteInit).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (data && data.success) {
                loadExistingHolidays();
                // Be honest: with auto-restore enabled a statutory day is added
                // back on the next calendar view, so it will reappear here too.
                if (scope === 'statutory' && isStatutoryAutoReseedEnabled()) {
                    showUserSuccess(tAzc('Statutory holiday removed. It will be added again automatically because auto-restore is enabled.'));
                } else {
                    showUserSuccess(tAzc('Holiday was removed.'));
                }
            } else {
                const errorMsg = (data && data.error) || tAzc('Holiday could not be removed.');
                if (Messaging && Messaging.showError) {
                    Messaging.showError(errorMsg);
                }
            }
        }).catch(function() {
            const msg = tAzc('An error occurred while removing the holiday.');
            if (Messaging && Messaging.showError) {
                Messaging.showError(msg);
            }
        });
    }

    // Test surface for Vitest (helpers + confirm call-site handlers).
    if (typeof window !== 'undefined') {
        window.__ArbeitszeitCheckAdminHolidaysTestables = {
            countryOfRegion: countryOfRegion,
            rebuildRegionSelect: rebuildRegionSelect,
            parseRegionDataFromDom: parseRegionDataFromDom,
            handleCountryChange: handleCountryChange,
            handleRegionChange: handleRegionChange,
            confirmRemoveHoliday: confirmRemoveHoliday,
            setPersistedCountry: function(country) {
                persistedCountry = String(country || 'DE').toUpperCase();
            },
            suggestedKindFromPayload: function(suggestion) {
                return (suggestion && suggestion.kind === 'half') ? 'half' : 'full';
            },
        };
    }

    // Robust initialisierung: sowohl beim DOMContentLoaded-Event als auch,
    // falls das Skript nach dem Laden des DOMs eingebunden wurde.
    document.addEventListener('DOMContentLoaded', init);
    if (document.readyState !== 'loading') {
        init();
    }
})();

