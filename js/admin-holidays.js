/**
 * Admin Holidays JavaScript for arbeitszeitcheck app
 *
 * Manages the UI for additional company holidays (Feiertage & Kalender).
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};
    const Messaging = window.ArbeitszeitCheckMessaging || {};

    function init() {
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
        dateInput.placeholder = (window.t && window.t('arbeitszeitcheck', 'dd.mm.yyyy')) || 'dd.mm.yyyy';
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
        optFull.textContent = window.t ? window.t('arbeitszeitcheck', 'Voller Feiertag') : 'Voller Feiertag';
        const optHalf = document.createElement('option');
        optHalf.value = 'half';
        optHalf.textContent = window.t ? window.t('arbeitszeitcheck', 'Halber Feiertag') : 'Halber Feiertag';
        typeSelect.appendChild(optFull);
        typeSelect.appendChild(optHalf);
        typeCell.appendChild(typeSelect);

        // Geltungsbereich (scope)
        const scopeCell = document.createElement('td');
        const scopeSelect = document.createElement('select');
        scopeSelect.name = 'scope';
        scopeSelect.className = 'form-select';
        const scopes = [
            { value: 'company', label: window.t ? window.t('arbeitszeitcheck', 'Firmenfeiertag') : 'Firmenfeiertag' },
            { value: 'custom', label: window.t ? window.t('arbeitszeitcheck', 'Benutzerdefiniert') : 'Benutzerdefiniert' },
            { value: 'statutory', label: window.t ? window.t('arbeitszeitcheck', 'Gesetzlich') : 'Gesetzlich' }
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
        saveBtn.textContent = window.t ? window.t('arbeitszeitcheck', 'Speichern') : 'Speichern';
        Utils.on(saveBtn, 'click', function() {
            saveHolidayRow(row);
        });

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'btn btn--secondary btn--sm';
        deleteBtn.textContent = window.t ? window.t('arbeitszeitcheck', 'Entfernen') : 'Entfernen';
        Utils.on(deleteBtn, 'click', function() {
            row.remove();
        });

        actionsCell.appendChild(saveBtn);
        actionsCell.appendChild(deleteBtn);

        row.appendChild(dateCell);
        row.appendChild(nameCell);
        row.appendChild(typeCell);
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
            const msg = window.t ? window.t('arbeitszeitcheck', 'Bitte Datum und Name des Feiertags angeben.') : 'Bitte Datum und Name des Feiertags angeben.';
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
                    const msg = window.t ? window.t('arbeitszeitcheck', 'Feiertag wurde gespeichert.') : 'Feiertag wurde gespeichert.';
                    Messaging.showSuccess(msg);
                }
            } else {
                const errorMsg = (data && data.error) || (window.t ? window.t('arbeitszeitcheck', 'Feiertag konnte nicht gespeichert werden.') : 'Feiertag konnte nicht gespeichert werden.');
                if (Messaging && Messaging.showError) {
                    Messaging.showError(errorMsg);
                } else {
                    alert(errorMsg);
                }
            }
        }).catch(function() {
            const msg = window.t ? window.t('arbeitszeitcheck', 'Beim Speichern des Feiertags ist ein Fehler aufgetreten.') : 'Beim Speichern des Feiertags ist ein Fehler aufgetreten.';
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

        fetch(url, {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        }).then(function(response) {
            return response.json();
        }).then(function(data) {
            if (!data || !data.success || !Array.isArray(data.holidays)) {
                return;
            }
            tbody.innerHTML = '';
            data.holidays.forEach(function(item) {
                appendExistingHolidayRow(tbody, item);
            });
        }).catch(function() {
            // Silent fail; page remains usable
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
            ? (window.t ? window.t('arbeitszeitcheck', 'Halber Feiertag') : 'Halber Feiertag')
            : (window.t ? window.t('arbeitszeitcheck', 'Voller Feiertag') : 'Voller Feiertag');
        typeCell.textContent = kindLabel;

        const scopeCell = document.createElement('td');
        let scopeLabel = '';
        if (item.scope === 'statutory') {
            scopeLabel = window.t ? window.t('arbeitszeitcheck', 'Gesetzlich') : 'Gesetzlich';
        } else if (item.scope === 'company') {
            scopeLabel = window.t ? window.t('arbeitszeitcheck', 'Firmenfeiertag') : 'Firmenfeiertag';
        } else {
            scopeLabel = window.t ? window.t('arbeitszeitcheck', 'Benutzerdefiniert') : 'Benutzerdefiniert';
        }
        scopeCell.textContent = scopeLabel;

        const actionsCell = document.createElement('td');
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'btn btn--secondary btn--sm';
        deleteBtn.textContent = window.t ? window.t('arbeitszeitcheck', 'Entfernen') : 'Entfernen';
        Utils.on(deleteBtn, 'click', function() {
            deleteHoliday(item.id, row);
        });
        actionsCell.appendChild(deleteBtn);

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
                    const msg = window.t ? window.t('arbeitszeitcheck', 'Feiertag wurde entfernt.') : 'Feiertag wurde entfernt.';
                    Messaging.showSuccess(msg);
                }
            } else {
                const errorMsg = (data && data.error) || (window.t ? window.t('arbeitszeitcheck', 'Feiertag konnte nicht entfernt werden.') : 'Feiertag konnte nicht entfernt werden.');
                if (Messaging && Messaging.showError) {
                    Messaging.showError(errorMsg);
                }
            }
        }).catch(function() {
            const msg = window.t ? window.t('arbeitszeitcheck', 'Beim Entfernen des Feiertags ist ein Fehler aufgetreten.') : 'Beim Entfernen des Feiertags ist ein Fehler aufgetreten.';
            if (Messaging && Messaging.showError) {
                Messaging.showError(msg);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

