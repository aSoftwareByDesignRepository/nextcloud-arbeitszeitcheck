/**
 * Admin Holidays JavaScript for arbeitszeitcheck app
 *
 * Manages the UI for additional company holidays (Feiertage & Kalender).
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    const HOLIDAYS_UI_JSON_ID = 'arbeitszeitcheck-admin-holidays-ui-strings';

    let holidaysUiStringsFromDomApplied = false;

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

    function init() {
        if (initialized) {
            return;
        }
        initialized = true;
        ensureHolidaysUiStrings();
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

    function bindEvents() {
        const addBtn = Utils.$('#holiday-add-entry');
        if (addBtn) {
            Utils.on(addBtn, 'click', handleAddHolidayClick);
        }
        const stateSelect = Utils.$('#holiday-state-select');
        const yearSelect = Utils.$('#holiday-year-select');
        if (stateSelect) {
            Utils.on(stateSelect, 'change', loadExistingHolidays);
        }
        if (yearSelect) {
            Utils.on(yearSelect, 'change', loadExistingHolidays);
        }
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
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.name = 'name';
        nameInput.required = true;
        nameInput.className = 'form-input';
        nameCell.appendChild(nameInput);

        // Art (voll / halb)
        const typeCell = document.createElement('td');
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
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn--primary btn--sm';
        saveBtn.textContent = tAzc('Save');
        Utils.on(saveBtn, 'click', function() {
            saveHolidayRow(row);
        });

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'btn btn--secondary btn--sm';
        deleteBtn.textContent = tAzc('Remove');
        Utils.on(deleteBtn, 'click', function() {
            row.remove();
        });

        actionsCell.appendChild(saveBtn);
        actionsCell.appendChild(deleteBtn);

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
            if (Messaging && Messaging.showError) {
                Messaging.showError(msg);
            } else {
                alert(msg);
            }
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
            if (Messaging && Messaging.showError) {
                Messaging.showError(msg);
            } else {
                alert(msg);
            }
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
                if (Messaging && Messaging.showSuccess) {
                    const msg = tAzc('Holiday was saved.');
                    Messaging.showSuccess(msg);
                }
            } else {
                const errorMsg = (data && data.error) || tAzc('Holiday could not be saved.');
                if (Messaging && Messaging.showError) {
                    Messaging.showError(errorMsg);
                } else {
                    alert(errorMsg);
                }
            }
        }).catch(function() {
            const msg = tAzc('An error occurred while saving the holiday.');
            if (Messaging && Messaging.showError) {
                Messaging.showError(msg);
            } else {
                alert(msg);
            }
        });
    }

    function loadExistingHolidays() {
        const tbody = Utils.$('#holiday-tbody');
        if (!tbody) {
            return;
        }

        const state = getSelectedState();
        const year = getSelectedYear();
        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays') +
            '?state=' + encodeURIComponent(state) + '&year=' + encodeURIComponent(String(year));

        // Clear existing content
        tbody.innerHTML = '';

        fetch(url, {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (!data || data.success !== true || !Array.isArray(data.holidays)) {
                renderEmptyHolidaysRow(tbody);
                if (Messaging && Messaging.showError) {
                    const msg = tAzc('Holidays could not be loaded.');
                    Messaging.showError(msg);
                }
                return;
            }

            if (data.holidays.length === 0) {
                renderEmptyHolidaysRow(tbody);
                return;
            }

            data.holidays.forEach(function(item) {
                appendExistingHolidayRow(tbody, item);
            });
        }).catch(function() {
            renderEmptyHolidaysRow(tbody);
            if (Messaging && Messaging.showError) {
                const msg = tAzc('Holidays could not be loaded.');
                Messaging.showError(msg);
            }
        });
    }

    function appendExistingHolidayRow(tbody, item) {
        const row = document.createElement('tr');

        const dateCell = document.createElement('td');
        let displayDate = item.date || '';
        if (window.ArbeitszeitCheckDatepicker && window.ArbeitszeitCheckDatepicker.convertISOToEuropean) {
            displayDate = window.ArbeitszeitCheckDatepicker.convertISOToEuropean(displayDate);
        } else if (/^\d{4}-\d{2}-\d{2}$/.test(displayDate)) {
            const p = displayDate.split('-');
            displayDate = p[2] + '.' + p[1] + '.' + p[0];
        }
        dateCell.textContent = displayDate;

        const nameCell = document.createElement('td');
        nameCell.textContent = item.name || '';

        const typeCell = document.createElement('td');
        const kindLabel = item.kind === 'half'
            ? tAzc('Half-day holiday')
            : tAzc('Full-day holiday');
        const typeBadge = document.createElement('span');
        typeBadge.className = 'admin-holidays-badge ' + (item.kind === 'half' ? 'admin-holidays-badge--half' : 'admin-holidays-badge--full');
        typeBadge.textContent = kindLabel;
        typeCell.appendChild(typeBadge);

        const scopeCell = document.createElement('td');
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
        {
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn btn--secondary btn--sm';
            deleteBtn.textContent = tAzc('Remove');
            const labelTemplate = tAzc('Remove holiday {name} on {date}');
            const ariaLabel = labelTemplate
                .replace('{name}', item.name || '')
                .replace('{date}', displayDate || '');
            deleteBtn.setAttribute('aria-label', ariaLabel);
            Utils.on(deleteBtn, 'click', function() {
                const name = item.name || '';
                const title = tAzc('Remove holiday');

                const baseMessage = tAzc('Do you really want to remove the holiday "{name}" on {date}?')
                    .replace('{name}', name)
                    .replace('{date}', displayDate || '');

                let extra = '';
                if (item.scope === 'statutory') {
                    extra = tAzc('Statutory holidays are automatically restored when the calendar is viewed, unless "Auto-restore statutory holidays" is disabled in Settings.');
                }

                const body = extra ? (extra + '<br><br>' + baseMessage) : baseMessage;

                if (window.ArbeitszeitCheckComponents && window.ArbeitszeitCheckComponents.createModal) {
                    const Components = window.ArbeitszeitCheckComponents;
                    const content = `
                        <div class="modal-section">
                            <h2 id="holiday-delete-title" class="modal-title">${title}</h2>
                            <p id="holiday-delete-body" class="modal-text">${body}</p>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn--secondary" data-action="close-modal">
                                ${tAzc('Cancel')}
                            </button>
                            <button type="button" class="btn btn--primary btn--danger" data-action="confirm-delete-holiday">
                                ${tAzc('Remove')}
                            </button>
                        </div>
                    `;

                    const modal = Components.createModal({
                        id: 'delete-holiday-modal',
                        title: title,
                        content: content,
                        size: 'md',
                        closable: true,
                        ariaLabelledBy: 'holiday-delete-title',
                        ariaDescribedBy: 'holiday-delete-body',
                        onClose: function() {
                            const el = document.getElementById('delete-holiday-modal');
                            if (el && el.parentNode) {
                                el.parentNode.remove();
                            }
                        }
                    });

                    document.body.appendChild(modal);
                    Components.openModal('delete-holiday-modal');

                    const modalEl = document.getElementById('delete-holiday-modal');
                    if (!modalEl) {
                        return;
                    }

                    const cancelBtn = modalEl.querySelector('[data-action="close-modal"]');
                    const confirmBtn = modalEl.querySelector('[data-action="confirm-delete-holiday"]');

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function() {
                            Components.closeModal(modalEl);
                        });
                    }

                    if (confirmBtn) {
                        confirmBtn.addEventListener('click', function() {
                            Components.closeModal(modalEl);
                            deleteHoliday(item.id, row);
                        });
                        confirmBtn.focus();
                    }
                } else {
                    // Fallback to native confirm if modal components are not available
                    const confirmMsg = body.replace(/<br\s*\/?>/gi, '\n\n');
                    if (!window.confirm(confirmMsg)) {
                        return;
                    }
                    deleteHoliday(item.id, row);
                }
            });
            actionsCell.appendChild(deleteBtn);
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

    function deleteHoliday(id, row) {
        if (!id) {
            row.remove();
            return;
        }

        const url = OC.generateUrl('/apps/arbeitszeitcheck/api/admin/state-holidays/' + encodeURIComponent(String(id)));
        fetch(url, {
            method: 'DELETE',
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (data && data.success) {
                row.remove();
                if (Messaging && Messaging.showSuccess) {
                    const msg = tAzc('Holiday was removed.');
                    Messaging.showSuccess(msg);
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

    // Robust initialisierung: sowohl beim DOMContentLoaded-Event als auch,
    // falls das Skript nach dem Laden des DOMs eingebunden wurde.
    document.addEventListener('DOMContentLoaded', init);
    if (document.readyState !== 'loading') {
        init();
    }
})();

