/**
 * Shared weekday-schedule editor for arbeitszeitcheck.
 *
 * Renders and binds the per-weekday work/break matrix used by both the
 * organisation working-time-models page and the per-employee "individual
 * contract hours" block in the admin user detail form.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const Utils = window.ArbeitszeitCheckUtils || {};

    /** Prefer server-injected l10n; window.t may be unavailable. */
    function msg(key, englishFallback) {
        const v = window.ArbeitszeitCheck?.l10n?.[key];
        if (v !== undefined && v !== '') {
            return v;
        }
        if (typeof window.t === 'function' && englishFallback) {
            return window.t('arbeitszeitcheck', englishFallback);
        }
        return englishFallback || '';
    }

    /** "Mo–Thu long / Fri short" preset (Banss reference schedule). */
    function preset() {
        const long = {
            work: true,
            start: '07:00',
            end: '16:15',
            breaks: [{ start: '12:15', end: '13:00', paid: false }],
        };
        const fri = {
            work: true,
            start: '07:00',
            end: '11:45',
            breaks: [{ start: '09:00', end: '09:15', paid: false }],
        };
        const off = { work: false };
        return {
            version: 1,
            days: {
                mon: long, tue: long, wed: long, thu: long, fri: fri, sat: off, sun: off,
            },
        };
    }

    /**
     * @param {string} prefix id/name prefix for all generated controls
     * @param {object|null} existingSchedule schedule payload ({version, days})
     * @param {object} [opts]
     * @param {string} [opts.scalarFields] comma-separated field names the
     *   derived weekly/daily/workday totals sync into (default
     *   "weeklyHours,dailyHours,workDaysPerWeek")
     */
    function sectionHtml(prefix, existingSchedule, opts) {
        const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        const labels = {
            mon: msg('monday', 'Monday'),
            tue: msg('tuesday', 'Tuesday'),
            wed: msg('wednesday', 'Wednesday'),
            thu: msg('thursday', 'Thursday'),
            fri: msg('friday', 'Friday'),
            sat: msg('saturday', 'Saturday'),
            sun: msg('sunday', 'Sunday'),
        };
        const schedule = existingSchedule && existingSchedule.days ? existingSchedule : null;
        const title = msg('weekdaySchedule', 'Weekday times (optional)');
        const lead = msg(
            'weekdayScheduleHelp',
            'Set different hours per weekday and fixed unpaid breaks. Weekly and daily hours update automatically. Leave empty to use the simple weekly hours above.'
        );
        const presetLabel = msg('weekdaySchedulePreset', 'Mo–Thu long / Fri short');
        const workLabel = msg('workDay', 'Work');
        const startLabel = msg('start', 'Start');
        const endLabel = msg('end', 'End');
        const breakLabel = msg('breakWindow', 'Break');
        const netLabel = msg('netHours', 'Net');
        const scalarFieldsAttr = opts && opts.scalarFields
            ? ` data-scalar-fields="${Utils.escapeHtml(opts.scalarFields)}"`
            : '';

        let rows = '';
        days.forEach((day) => {
            const row = schedule && schedule.days[day] ? schedule.days[day] : { work: false };
            const work = !!row.work;
            const br = (row.breaks && row.breaks[0]) ? row.breaks[0] : { start: '', end: '' };
            rows += `
                <tr data-day="${day}">
                    <th scope="row">${Utils.escapeHtml(labels[day])}</th>
                    <td><input type="checkbox" class="wtm-day-work" id="${prefix}-${day}-work" ${work ? 'checked' : ''} aria-label="${Utils.escapeHtml(labels[day] + ' ' + workLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-start" id="${prefix}-${day}-start" value="${Utils.escapeHtml(row.start || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + startLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-end" id="${prefix}-${day}-end" value="${Utils.escapeHtml(row.end || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + endLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-break-start" id="${prefix}-${day}-bstart" value="${Utils.escapeHtml(br.start || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + breakLabel + ' ' + startLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-break-end" id="${prefix}-${day}-bend" value="${Utils.escapeHtml(br.end || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + breakLabel + ' ' + endLabel)}"></td>
                    <td class="wtm-day-net" id="${prefix}-${day}-net" aria-live="polite">—</td>
                </tr>`;
        });

        return `
            <fieldset class="wtm-weekday-schedule" data-schedule-prefix="${prefix}"${scalarFieldsAttr}>
                <legend class="form-label">${Utils.escapeHtml(title)}</legend>
                <p class="form-help" id="${prefix}-schedule-help">${Utils.escapeHtml(lead)}</p>
                <p>
                    <button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" data-action="apply-weekday-preset" aria-describedby="${prefix}-schedule-help">
                        ${Utils.escapeHtml(presetLabel)}
                    </button>
                    <button type="button" class="azc-btn azc-btn--tertiary azc-btn--sm" data-action="clear-weekday-schedule">
                        ${Utils.escapeHtml(msg('clearSchedule', 'Clear weekday times'))}
                    </button>
                </p>
                <div class="table-container" role="region" aria-label="${Utils.escapeHtml(title)}">
                    <table class="table wtm-weekday-table">
                        <thead>
                            <tr>
                                <th scope="col">${Utils.escapeHtml(msg('day', 'Day'))}</th>
                                <th scope="col">${Utils.escapeHtml(workLabel)}</th>
                                <th scope="col">${Utils.escapeHtml(startLabel)}</th>
                                <th scope="col">${Utils.escapeHtml(endLabel)}</th>
                                <th scope="col">${Utils.escapeHtml(breakLabel)} ${Utils.escapeHtml(startLabel)}</th>
                                <th scope="col">${Utils.escapeHtml(breakLabel)} ${Utils.escapeHtml(endLabel)}</th>
                                <th scope="col">${Utils.escapeHtml(netLabel)}</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <p class="wtm-week-total" id="${prefix}-week-total" aria-live="polite"></p>
            </fieldset>`;
    }

    function parseHmToMinutes(hm) {
        if (!hm || !/^\d{1,2}:\d{2}$/.test(hm)) {
            return null;
        }
        const parts = hm.split(':');
        const h = Number(parts[0]);
        const m = Number(parts[1]);
        if (!Number.isFinite(h) || !Number.isFinite(m) || h > 23 || m > 59) {
            return null;
        }
        return h * 60 + m;
    }

    function netHoursFromRow(start, end, bStart, bEnd) {
        const s = parseHmToMinutes(start);
        const e = parseHmToMinutes(end);
        if (s === null || e === null || e <= s) {
            return null;
        }
        let unpaid = 0;
        const bs = parseHmToMinutes(bStart);
        const be = parseHmToMinutes(bEnd);
        if (bs !== null && be !== null && be > bs) {
            unpaid = be - bs;
        }
        return Math.max(0, (e - s - unpaid) / 60);
    }

    /** Names of the scalar hour fields the schedule derives (per fieldset attr). */
    function scalarFieldNames(fieldset) {
        const raw = fieldset.getAttribute('data-scalar-fields');
        const defaults = ['weeklyHours', 'dailyHours', 'workDaysPerWeek'];
        if (!raw) {
            return defaults;
        }
        const names = raw.split(',').map((s) => s.trim()).filter(Boolean);
        return names.length === 3 ? names : defaults;
    }

    function refreshTotals(fieldset) {
        if (!fieldset) {
            return;
        }
        const prefix = fieldset.getAttribute('data-schedule-prefix') || 'wtm';
        let week = 0;
        let anyWork = false;
        fieldset.querySelectorAll('tr[data-day]').forEach((tr) => {
            const day = tr.getAttribute('data-day');
            const work = tr.querySelector('.wtm-day-work')?.checked;
            const netEl = document.getElementById(`${prefix}-${day}-net`);
            const start = tr.querySelector('.wtm-day-start');
            const end = tr.querySelector('.wtm-day-end');
            const bStart = tr.querySelector('.wtm-day-break-start');
            const bEnd = tr.querySelector('.wtm-day-break-end');
            [start, end, bStart, bEnd].forEach((el) => {
                if (el) {
                    el.disabled = !work;
                }
            });
            if (!work) {
                if (netEl) {
                    netEl.textContent = '—';
                }
                return;
            }
            anyWork = true;
            const net = netHoursFromRow(start?.value, end?.value, bStart?.value, bEnd?.value);
            if (netEl) {
                netEl.textContent = net === null ? '—' : `${net.toFixed(2)} h`;
            }
            if (net !== null) {
                week += net;
            }
        });
        const totalEl = document.getElementById(`${prefix}-week-total`);
        if (totalEl) {
            totalEl.textContent = anyWork
                ? (msg('weekTotalNet', 'Week total (net): {h} h').replace('{h}', week.toFixed(2)))
                : '';
        }
        const names = scalarFieldNames(fieldset);
        const form = fieldset.closest('form');
        const weekly = form?.querySelector(`[name="${names[0]}"]`);
        const daily = form?.querySelector(`[name="${names[1]}"]`);
        const workDays = form?.querySelector(`[name="${names[2]}"]`);
        const workCount = fieldset.querySelectorAll('.wtm-day-work:checked').length;
        if (anyWork) {
            if (weekly) {
                weekly.value = String(week.toFixed(2));
            }
            if (daily && workCount > 0) {
                daily.value = String((week / workCount).toFixed(2));
            }
            if (workDays && workCount > 0) {
                workDays.value = String(workCount);
            }
        }
        // When a weekday matrix is active, scalars are derived — keep them readable but not editable.
        [weekly, daily, workDays].forEach((el) => {
            if (!el) {
                return;
            }
            el.readOnly = anyWork;
            if (anyWork) {
                el.setAttribute('aria-readonly', 'true');
            } else {
                el.removeAttribute('aria-readonly');
            }
        });
    }

    function bind(fieldset) {
        if (!fieldset || fieldset.dataset.bound === '1') {
            return;
        }
        fieldset.dataset.bound = '1';
        fieldset.addEventListener('change', () => refreshTotals(fieldset));
        fieldset.addEventListener('input', () => refreshTotals(fieldset));
        const presetBtn = fieldset.querySelector('[data-action="apply-weekday-preset"]');
        if (presetBtn) {
            presetBtn.addEventListener('click', () => {
                applyTo(fieldset, preset());
                refreshTotals(fieldset);
            });
        }
        const clearBtn = fieldset.querySelector('[data-action="clear-weekday-schedule"]');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                fieldset.querySelectorAll('.wtm-day-work').forEach((cb) => {
                    cb.checked = false;
                });
                fieldset.querySelectorAll('input[type="time"]').forEach((el) => {
                    el.value = '';
                });
                refreshTotals(fieldset);
            });
        }
        refreshTotals(fieldset);
    }

    function applyTo(fieldset, schedule) {
        const prefix = fieldset.getAttribute('data-schedule-prefix') || 'wtm';
        const days = schedule.days || {};
        Object.keys(days).forEach((day) => {
            const row = days[day] || {};
            const work = fieldset.querySelector(`#${prefix}-${day}-work`);
            const start = fieldset.querySelector(`#${prefix}-${day}-start`);
            const end = fieldset.querySelector(`#${prefix}-${day}-end`);
            const bStart = fieldset.querySelector(`#${prefix}-${day}-bstart`);
            const bEnd = fieldset.querySelector(`#${prefix}-${day}-bend`);
            if (work) {
                work.checked = !!row.work;
            }
            if (start) {
                start.value = row.start || '';
            }
            if (end) {
                end.value = row.end || '';
            }
            const br = (row.breaks && row.breaks[0]) ? row.breaks[0] : {};
            if (bStart) {
                bStart.value = br.start || '';
            }
            if (bEnd) {
                bEnd.value = br.end || '';
            }
        });
    }

    function collect(fieldset) {
        if (!fieldset) {
            return null;
        }
        const days = {};
        let any = false;
        fieldset.querySelectorAll('tr[data-day]').forEach((tr) => {
            const day = tr.getAttribute('data-day');
            const work = !!tr.querySelector('.wtm-day-work')?.checked;
            if (!work) {
                days[day] = { work: false };
                return;
            }
            any = true;
            const start = tr.querySelector('.wtm-day-start')?.value || '';
            const end = tr.querySelector('.wtm-day-end')?.value || '';
            const bStart = tr.querySelector('.wtm-day-break-start')?.value || '';
            const bEnd = tr.querySelector('.wtm-day-break-end')?.value || '';
            const breaks = [];
            if (bStart && bEnd) {
                breaks.push({ start: bStart, end: bEnd, paid: false });
            }
            days[day] = { work: true, start, end, breaks };
        });
        if (!any) {
            return null;
        }
        return { version: 1, days };
    }

    window.ArbeitszeitCheckWeekdaySchedule = {
        preset: preset,
        sectionHtml: sectionHtml,
        bind: bind,
        refreshTotals: refreshTotals,
        applyTo: applyTo,
        collect: collect,
        netHoursFromRow: netHoursFromRow,
        parseHmToMinutes: parseHmToMinutes,
    };
})();
