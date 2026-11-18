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
        const loadedIsV2 = schedule && Number(schedule.version) === 2;
        const addBlockLabel = msg('addWorkBlock', 'Add work block');
        const removeBlockLabel = msg('removeWorkBlock', 'Remove block');
        const copyDayLabel = msg('copyDayTo', 'Copy this day to…');
        const blockWord = msg('block', 'block');
        // Stored split_service is optional; absent ≡ every option off.
        const splitCfg = (schedule && schedule.split_service && typeof schedule.split_service === 'object')
            ? schedule.split_service
            : {};
        const splitAutoOn = splitCfg.automatic_split === true;
        const splitCoverOn = splitCfg.interruption_counts_as_break === true;
        const splitCutoffValue = (splitAutoOn && typeof splitCfg.split_when_clock_out_after === 'string')
            ? splitCfg.split_when_clock_out_after
            : '';

        let anyMultiBlock = false;
        let firstBlockSample = null;
        let rows = '';
        days.forEach((day) => {
            const row = schedule && schedule.days[day] ? schedule.days[day] : { work: false };
            const work = !!row.work;
            // v2 uses explicit blocks[]; v1 derives one block from start/end.
            const blocks = Array.isArray(row.blocks) && row.blocks.length
                ? row.blocks
                : (work ? [{ start: row.start || '', end: row.end || '' }] : []);
            if (work && blocks.length > 1) {
                anyMultiBlock = true;
                if (!firstBlockSample) {
                    // Preview sample times come from the first interrupted
                    // day's first block (spec §7.2 — cut-off stays empty).
                    firstBlockSample = { weekday: day, start: blocks[0].start || '', end: blocks[0].end || '' };
                }
            }
            const block0 = blocks[0] || { start: '', end: '' };
            const br = (row.breaks && row.breaks[0]) ? row.breaks[0] : { start: '', end: '' };
            rows += `
                <tr data-day="${day}">
                    <th scope="row">${Utils.escapeHtml(labels[day])}</th>
                    <td><input type="checkbox" class="wtm-day-work" id="${prefix}-${day}-work" ${work ? 'checked' : ''} aria-label="${Utils.escapeHtml(labels[day] + ' ' + workLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-start" id="${prefix}-${day}-start" value="${Utils.escapeHtml(block0.start || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + startLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-end" id="${prefix}-${day}-end" value="${Utils.escapeHtml(block0.end || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + endLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-break-start" id="${prefix}-${day}-bstart" value="${Utils.escapeHtml(br.start || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + breakLabel + ' ' + startLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-break-end" id="${prefix}-${day}-bend" value="${Utils.escapeHtml(br.end || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + breakLabel + ' ' + endLabel)}"></td>
                    <td class="wtm-day-net" id="${prefix}-${day}-net"><span class="wtm-day-net-value" aria-live="polite">—</span>
                        <button type="button" class="azc-btn azc-btn--ghost azc-btn--sm wtm-add-block" data-action="add-block" data-day="${day}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + addBlockLabel)}" title="${Utils.escapeHtml(addBlockLabel)}">+</button>
                        <button type="button" class="azc-btn azc-btn--ghost azc-btn--sm wtm-copy-day" data-action="copy-day" data-day="${day}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ' ' + copyDayLabel)}" title="${Utils.escapeHtml(copyDayLabel)}">⧉</button>
                    </td>
                </tr>`;
            for (let bi = 1; bi < blocks.length; bi++) {
                const blk = blocks[bi] || {};
                rows += `
                <tr data-day="${day}" data-extra-block>
                    <th scope="row" class="visually-hidden">${Utils.escapeHtml(labels[day])} ${bi + 1}</th>
                    <td></td>
                    <td><input type="time" class="form-input wtm-day-start" value="${Utils.escapeHtml(blk.start || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ', ' + blockWord + ' ' + (bi + 1) + ', ' + startLabel)}"></td>
                    <td><input type="time" class="form-input wtm-day-end" value="${Utils.escapeHtml(blk.end || '')}" ${work ? '' : 'disabled'} aria-label="${Utils.escapeHtml(labels[day] + ', ' + blockWord + ' ' + (bi + 1) + ', ' + endLabel)}"></td>
                    <td colspan="2"></td>
                    <td><button type="button" class="azc-btn azc-btn--ghost azc-btn--sm wtm-remove-block" data-action="remove-block" data-day="${day}" aria-label="${Utils.escapeHtml(labels[day] + ' ' + removeBlockLabel)}">×</button></td>
                </tr>`;
            }
        });
        // Render the v2 editor when the loaded document is version 2 OR when
        // any working day carries more than one block (a stored multi-block
        // day must never render as a v1 draft that silently drops blocks).
        const isV2View = !!(loadedIsV2 || anyMultiBlock);
        const scheduleVersion = isV2View ? '2' : '1';
        const modeClass = isV2View ? ' wtm-weekday-schedule--v2' : ' wtm-weekday-schedule--v1';

        return `
            <fieldset class="wtm-weekday-schedule${modeClass}" data-schedule-prefix="${prefix}" data-schedule-version="${scheduleVersion}" data-schedule-mode="${scheduleVersion}" data-loaded-version="${loadedIsV2 ? '2' : '1'}"${scalarFieldsAttr}>
                <legend class="form-label">${Utils.escapeHtml(title)}</legend>
                <p class="form-help" id="${prefix}-schedule-help">${Utils.escapeHtml(lead)}</p>
                <fieldset class="wtm-schedule-mode">
                    <legend class="visually-hidden">${Utils.escapeHtml(msg('scheduleModeLegend', 'Working time layout'))}</legend>
                    <div class="azc-choice-cards">
                        <label class="azc-choice-card">
                            <input type="radio" name="${prefix}-schedule-mode" value="1"${isV2View ? '' : ' checked'}>
                            <span class="azc-choice-card__copy">
                                <span class="azc-choice-card__title">${Utils.escapeHtml(msg('scheduleModeSame', 'Same times every working day'))}</span>
                                <span class="azc-choice-card__hint">${Utils.escapeHtml(msg('scheduleModeSameHint', 'One start and one end time per working day.'))}</span>
                            </span>
                        </label>
                        <label class="azc-choice-card">
                            <input type="radio" name="${prefix}-schedule-mode" value="2"${isV2View ? ' checked' : ''}>
                            <span class="azc-choice-card__copy">
                                <span class="azc-choice-card__title">${Utils.escapeHtml(msg('scheduleModeBlocks', 'Different blocks per weekday'))}</span>
                                <span class="azc-choice-card__hint">${Utils.escapeHtml(msg('scheduleModeBlocksHint', 'Two or more working blocks with an interruption in between.'))}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <p>
                    <button type="button" class="azc-btn azc-btn--secondary azc-btn--sm" data-action="apply-weekday-preset" aria-describedby="${prefix}-schedule-help">
                        ${Utils.escapeHtml(presetLabel)}
                    </button>
                    <button type="button" class="azc-btn azc-btn--ghost azc-btn--sm" data-action="clear-weekday-schedule">
                        ${Utils.escapeHtml(msg('clearSchedule', 'Clear weekday times'))}
                    </button>
                </p>
                <p class="wtm-copy-status" role="status" aria-live="polite"></p>
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
                ${splitServiceHtml(prefix, {
                    visible: anyMultiBlock,
                    autoOn: splitAutoOn,
                    coverOn: splitCoverOn,
                    cutoff: splitCutoffValue,
                    sample: firstBlockSample,
                })}
            </fieldset>`;
    }

    /**
     * Split-service disclosure (spec §7.2): only rendered visible when at
     * least one working day has two or more blocks — visibility is toggled
     * live by updateSplitServiceVisibility() as blocks come and go.
     */
    function splitServiceHtml(prefix, ctx) {
        const title = msg('splitService', 'Split service');
        const autoLabel = msg('splitAutoLabel', 'Split automatically into partial services');
        const cutoffLabel = msg('splitAutoCutoffLabel', 'Split if the clock-out is after');
        const autoHelp = msg(
            'splitAutoHelp',
            'If an employee clocks out later than this time, the booking is divided at the interruptions. Otherwise it stays one continuous service with the normal break rules.'
        );
        const coverLabel = msg('splitBreakCoverLabel', 'Interruption counts as break period');
        // Legal wording (spec §7.2/§7.5) — never "rest taken"; the
        // employer-responsibility sentence must stay verbatim.
        const coverHelp = msg(
            'splitBreakCoverHelp',
            'The scheduled interruption can cover the required break for working time in this company. It is not recorded as a rest break taken. The employer remains responsible for rest breaks and for total working time with other employers.'
        );
        const sample = ctx.sample || { weekday: '', start: '', end: '' };
        return `
            <details class="wtm-split-service" id="${prefix}-split-service"${ctx.visible ? '' : ' hidden'}>
                <summary class="wtm-split-service__summary">${Utils.escapeHtml(title)}</summary>
                <div class="wtm-split-service__body">
                    <div class="azc-switch-field">
                        <input type="hidden" name="${prefix}-split-auto" value="0">
                        <input type="checkbox" class="azc-switch-field__input wtm-split-auto" id="${prefix}-split-auto" name="${prefix}-split-auto" value="1"${ctx.autoOn ? ' checked' : ''}>
                        <label class="azc-switch-field__label" for="${prefix}-split-auto">
                            <span class="azc-switch-field__track" aria-hidden="true"></span>
                            <span class="azc-switch-field__text">${Utils.escapeHtml(autoLabel)}</span>
                        </label>
                    </div>
                    <div class="form-group wtm-split-cutoff-group"${ctx.autoOn ? '' : ' hidden'}>
                        <label for="${prefix}-split-cutoff" class="form-label">${Utils.escapeHtml(cutoffLabel)}</label>
                        <input type="time" step="60" class="form-input wtm-split-cutoff" id="${prefix}-split-cutoff" value="${Utils.escapeHtml(ctx.cutoff || '')}"${ctx.autoOn ? '' : ' disabled'} aria-describedby="${prefix}-split-cutoff-help ${prefix}-split-cutoff-error">
                        <p id="${prefix}-split-cutoff-help" class="form-help">${Utils.escapeHtml(autoHelp)}</p>
                        <p id="${prefix}-split-cutoff-error" class="field-error wtm-split-cutoff-error" role="alert" hidden></p>
                    </div>
                    <div class="form-group wtm-split-weekday-cutoffs"${ctx.autoOn ? '' : ' hidden'}>
                        <p class="form-label wtm-split-weekday-cutoffs__legend">${Utils.escapeHtml(msg('splitWeekdayCutoffsLabel', 'Different cut-off per weekday (optional)'))}</p>
                        <p class="form-help">${Utils.escapeHtml(msg('splitWeekdayCutoffsHelp', 'Override the cut-off for individual weekdays. Leave a field empty to use the default above.'))}</p>
                        <div class="wtm-split-weekday-cutoffs__rows"></div>
                    </div>
                    <div class="azc-switch-field">
                        <input type="hidden" name="${prefix}-split-break-cover" value="0">
                        <input type="checkbox" class="azc-switch-field__input wtm-split-break-cover" id="${prefix}-split-break-cover" name="${prefix}-split-break-cover" value="1"${ctx.coverOn ? ' checked' : ''} aria-describedby="${prefix}-split-cover-help">
                        <label class="azc-switch-field__label" for="${prefix}-split-break-cover">
                            <span class="azc-switch-field__track" aria-hidden="true"></span>
                            <span class="azc-switch-field__text">${Utils.escapeHtml(coverLabel)}</span>
                        </label>
                    </div>
                    <p id="${prefix}-split-cover-help" class="form-help">${Utils.escapeHtml(coverHelp)}</p>
                    <div class="wtm-split-preview">
                        <p class="wtm-split-preview-actions">
                            <button type="button" class="azc-btn azc-btn--secondary" data-action="split-preview" id="${prefix}-split-preview-btn">${Utils.escapeHtml(msg('splitPreviewButton', 'Preview'))}</button>
                            <span class="wtm-split-preview-status" role="status"></span>
                        </p>
                        <div class="azc-callout azc-callout--danger wtm-split-preview-error" role="alert" hidden>
                            <div class="azc-callout__body"><p class="azc-callout__text"></p></div>
                        </div>
                        <div class="table-container wtm-split-preview-tablewrap">
                            <table class="table wtm-split-preview-table">
                                <caption>${Utils.escapeHtml(msg('splitPreviewCaption', 'Preview: how a day is counted when the clock-out passes the cut-off'))}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">${Utils.escapeHtml(msg('splitPreviewExample', 'Example'))}</th>
                                        <th scope="col">${Utils.escapeHtml(msg('splitPreviewClockIn', 'Clock-in'))}</th>
                                        <th scope="col">${Utils.escapeHtml(msg('splitPreviewClockOut', 'Clock-out'))}</th>
                                        <th scope="col">${Utils.escapeHtml(msg('splitPreviewResult', 'Result'))}</th>
                                    </tr>
                                </thead>
                                <tbody class="wtm-split-preview-rows">
                                    <tr data-preview-row="sample">
                                        <th scope="row">${Utils.escapeHtml(msg('splitPreviewSample', 'Your sample'))}</th>
                                        <td><input type="time" class="form-input wtm-split-sample-start" value="${Utils.escapeHtml(sample.start || '')}" aria-label="${Utils.escapeHtml(msg('splitPreviewSampleIn', 'Sample clock-in'))}"></td>
                                        <td><input type="time" class="form-input wtm-split-sample-end" value="${Utils.escapeHtml(sample.end || '')}" aria-label="${Utils.escapeHtml(msg('splitPreviewSampleOut', 'Sample clock-out'))}"></td>
                                        <td class="wtm-split-preview-sample-result">—</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="azc-callout azc-callout--warning wtm-split-preview-cliff" role="status" hidden>
                            <div class="azc-callout__body"><p class="azc-callout__text"></p></div>
                        </div>
                    </div>
                </div>
            </details>`;
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
        return netHoursForDay([{ start, end }], bStart, bEnd);
    }

    /**
     * Net hours of a day = sum of block spans minus unpaid break minutes
     * overlapping a block (clipped per block; mirrors PHP
     * WeekdaySchedule::netHoursForWeekdayV2). null when any block is
     * incomplete/invalid — keeps the net cell honest instead of guessing.
     */
    function netHoursForDay(blocks, bStart, bEnd) {
        const spans = [];
        let gross = 0;
        for (let i = 0; i < blocks.length; i++) {
            const s = parseHmToMinutes(blocks[i].start);
            const e = parseHmToMinutes(blocks[i].end);
            if (s === null || e === null || e <= s) {
                return null;
            }
            spans.push([s, e]);
            gross += e - s;
        }
        let unpaid = 0;
        const bs = parseHmToMinutes(bStart);
        const be = parseHmToMinutes(bEnd);
        if (bs !== null && be !== null && be > bs) {
            spans.forEach(([s, e]) => {
                const overlap = Math.min(e, be) - Math.max(s, bs);
                if (overlap > 0) {
                    unpaid += overlap;
                }
            });
        }
        return Math.max(0, (gross - unpaid) / 60);
    }

    /** All rows (main + extra blocks) of a day, in DOM order. */
    function dayRows(fieldset, day) {
        return Array.from(fieldset.querySelectorAll(`tr[data-day="${day}"]`));
    }

    function extraBlockRows(fieldset, day) {
        return Array.from(fieldset.querySelectorAll(`tr[data-day="${day}"][data-extra-block]`));
    }

    function blockInputsOfRow(tr) {
        return {
            start: tr.querySelector('.wtm-day-start')?.value || '',
            end: tr.querySelector('.wtm-day-end')?.value || '',
        };
    }

    const MAX_BLOCKS_PER_DAY = 4;

    function addBlockRow(fieldset, day, prefix, labels) {
        const extras = extraBlockRows(fieldset, day);
        if (extras.length + 1 >= MAX_BLOCKS_PER_DAY) {
            return;
        }
        const mainTr = fieldset.querySelector(`tr[data-day="${day}"]:not([data-extra-block])`);
        const lastTr = extras.length ? extras[extras.length - 1] : mainTr;
        const blockNo = extras.length + 2;
        const tr = document.createElement('tr');
        tr.setAttribute('data-day', day);
        tr.setAttribute('data-extra-block', '');
        tr.innerHTML = `
            <th scope="row" class="visually-hidden"></th>
            <td></td>
            <td><input type="time" class="form-input wtm-day-start"></td>
            <td><input type="time" class="form-input wtm-day-end"></td>
            <td colspan="2"></td>
            <td><button type="button" class="azc-btn azc-btn--ghost azc-btn--sm wtm-remove-block" data-action="remove-block" data-day="${day}">×</button></td>`;
        const dayLabel = labels && labels[day] ? labels[day] : day;
        const blockWord = (labels && labels.block) || msg('block', 'block');
        tr.querySelector('th').textContent = `${dayLabel} ${blockNo}`;
        const startInput = tr.querySelector('.wtm-day-start');
        const endInput = tr.querySelector('.wtm-day-end');
        const removeBtn = tr.querySelector('.wtm-remove-block');
        if (startInput) {
            // Screen-reader contract: "Monday, block 2, Start" (spec §7.7).
            startInput.setAttribute('aria-label', `${dayLabel}, ${blockWord} ${blockNo}, ${labels?.start || 'Start'}`);
        }
        if (endInput) {
            endInput.setAttribute('aria-label', `${dayLabel}, ${blockWord} ${blockNo}, ${labels?.end || 'End'}`);
        }
        if (removeBtn) {
            removeBtn.setAttribute('aria-label', `${dayLabel}, ${blockWord} ${blockNo}, ${labels?.remove || 'Remove block'}`);
        }
        lastTr.insertAdjacentElement('afterend', tr);
        if (startInput) {
            startInput.focus();
        }
    }

    /** Reflect the editor mode on the fieldset (hides "+ block" in v1 mode). */
    function syncModeClass(fieldset, mode) {
        fieldset.classList.toggle('wtm-weekday-schedule--v2', mode === '2');
        fieldset.classList.toggle('wtm-weekday-schedule--v1', mode !== '2');
    }

    /**
     * v1/v2 choice-card switch (spec §7.2). v1→v2 keeps the existing
     * start/end as block 1 (it already is one in the DOM) and unlocks
     * "+ Add work block". v2→v1 collapses to a single block per day after
     * confirmation when extra blocks exist — that is a destructive local
     * step, so it goes through the shared dialog, never window.confirm.
     * A document loaded as v2 is never downgraded: its output version
     * stays 2 even in single-block view.
     */
    function applyScheduleMode(fieldset, mode) {
        const loadedIsV2 = fieldset.getAttribute('data-loaded-version') === '2';
        if (mode === '2') {
            fieldset.setAttribute('data-schedule-version', '2');
            fieldset.setAttribute('data-schedule-mode', '2');
            syncModeClass(fieldset, '2');
            refreshTotals(fieldset);
            return;
        }
        const extras = Array.from(fieldset.querySelectorAll('tr[data-extra-block]'));
        const finish = () => {
            extras.forEach((tr) => tr.remove());
            const effective = loadedIsV2 ? '2' : '1';
            fieldset.setAttribute('data-schedule-version', effective);
            fieldset.setAttribute('data-schedule-mode', '1');
            syncModeClass(fieldset, '1');
            refreshTotals(fieldset);
        };
        if (!extras.length) {
            finish();
            return;
        }
        const components = window.ArbeitszeitCheckComponents || {};
        const ask = typeof components.showConfirmDialog === 'function'
            ? components.showConfirmDialog({
                title: msg('scheduleModeCollapseTitle', 'Switch to one block per day'),
                message: msg(
                    'scheduleModeCollapseMessage',
                    'The additional working blocks are removed. The first block of each working day is kept.'
                ),
                confirmLabel: msg('scheduleModeCollapseConfirm', 'Remove extra blocks'),
                cancelLabel: msg('cancel', 'Cancel'),
                variant: 'warning',
            })
            : Promise.resolve(false);
        Promise.resolve(ask).then((accepted) => {
            if (accepted) {
                finish();
                return;
            }
            // Declined → restore the v2 card as the checked option.
            const v2Radio = fieldset.querySelector('input[type="radio"][name$="-schedule-mode"][value="2"]');
            if (v2Radio) {
                v2Radio.checked = true;
            }
        });
    }

    /** Reveal the cut-off field only while the automatic-split switch is on. */
    function syncSplitCutoff(fieldset) {
        const auto = !!fieldset.querySelector('.wtm-split-auto')?.checked;
        const group = fieldset.querySelector('.wtm-split-cutoff-group');
        const input = fieldset.querySelector('.wtm-split-cutoff');
        if (group) {
            group.hidden = !auto;
        }
        if (input) {
            input.disabled = !auto;
        }
        const weekdayGroup = fieldset.querySelector('.wtm-split-weekday-cutoffs');
        if (weekdayGroup) {
            weekdayGroup.hidden = !auto;
        }
        fieldset.querySelectorAll('.wtm-split-day-cutoff').forEach((el) => {
            el.disabled = !auto;
        });
    }

    /**
     * Per-weekday cut-off rows (NH-01): one optional <input type="time">
     * per weekday that currently has two or more blocks. Rebuilt whenever
     * the block geometry changes; already-typed values and values applied
     * from a stored document are preserved by weekday.
     */
    function syncSplitWeekdayCutoffs(fieldset) {
        const container = fieldset.querySelector('.wtm-split-weekday-cutoffs__rows');
        const group = fieldset.querySelector('.wtm-split-weekday-cutoffs');
        if (!container || !group) {
            return;
        }
        const labels = {
            mon: msg('monday', 'Monday'), tue: msg('tuesday', 'Tuesday'),
            wed: msg('wednesday', 'Wednesday'), thu: msg('thursday', 'Thursday'),
            fri: msg('friday', 'Friday'), sat: msg('saturday', 'Saturday'),
            sun: msg('sunday', 'Sunday'),
        };
        const current = {};
        container.querySelectorAll('.wtm-split-day-cutoff').forEach((el) => {
            current[el.getAttribute('data-day')] = el.value;
        });
        let applied = {};
        try {
            applied = JSON.parse(fieldset.dataset.pendingSplitWeekdayCutoffs || '{}') || {};
        } catch (e) {
            applied = {};
        }
        const prefix = fieldset.getAttribute('data-schedule-prefix') || 'wtm';
        const cutoffLabel = msg('splitWeekdayCutoffAria', 'Cut-off');
        const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        let html = '';
        days.forEach((day) => {
            const work = !!fieldset.querySelector(`#${prefix}-${day}-work`)?.checked;
            if (!work || dayRows(fieldset, day).length < 2) {
                return;
            }
            const value = Object.prototype.hasOwnProperty.call(current, day)
                ? current[day]
                : (typeof applied[day] === 'string' ? applied[day] : '');
            const id = `${prefix}-split-cutoff-${day}`;
            html += `<div class="wtm-split-weekday-cutoff">`
                + `<label class="form-label" for="${id}">${Utils.escapeHtml(labels[day] || day)}</label>`
                + `<input type="time" step="60" class="form-input wtm-split-day-cutoff" id="${id}" data-day="${day}"`
                + ` value="${Utils.escapeHtml(value)}" aria-label="${Utils.escapeHtml((labels[day] || day) + ', ' + cutoffLabel)}">`
                + `</div>`;
        });
        container.innerHTML = html;
        delete fieldset.dataset.pendingSplitWeekdayCutoffs;
        syncSplitCutoff(fieldset);
    }

    function clearSplitCutoffError(fieldset) {
        const err = fieldset.querySelector('.wtm-split-cutoff-error');
        const input = fieldset.querySelector('.wtm-split-cutoff');
        if (err) {
            err.textContent = '';
            err.hidden = true;
        }
        if (input) {
            input.removeAttribute('aria-invalid');
        }
    }

    /** Inline, announced error on the cut-off field (shared field-error pattern). */
    function showSplitCutoffError(fieldset, message) {
        const details = fieldset.querySelector('.wtm-split-service');
        const err = fieldset.querySelector('.wtm-split-cutoff-error');
        const input = fieldset.querySelector('.wtm-split-cutoff');
        if (!details || !err || !input) {
            return false;
        }
        // Errors auto-open the containing disclosure (spec §7.2).
        details.open = true;
        err.textContent = message;
        err.hidden = false;
        input.setAttribute('aria-invalid', 'true');
        if (typeof input.focus === 'function') {
            input.focus();
        }
        return true;
    }

    /** First checked work day that has an interruption — the preview basis. */
    function splitPreviewWeekday(fieldset) {
        let day = null;
        fieldset.querySelectorAll('tr[data-day]:not([data-extra-block])').forEach((mainTr) => {
            if (day) {
                return;
            }
            if (mainTr.querySelector('.wtm-day-work')?.checked
                && dayRows(fieldset, mainTr.getAttribute('data-day')).length > 1) {
                day = mainTr.getAttribute('data-day');
            }
        });
        return day;
    }

    /** Whole-seconds → "h:mm" (floored to minutes, like the spec examples). */
    function formatSecondsAsHm(seconds) {
        const s = Math.max(0, Math.floor(Number(seconds) || 0));
        const totalMin = Math.floor(s / 60);
        const h = Math.floor(totalMin / 60);
        const m = totalMin % 60;
        return `${h}:${String(m).padStart(2, '0')}`;
    }

    function formatSegments(segments) {
        if (!Array.isArray(segments)) {
            return '';
        }
        return segments
            .map((seg) => (Array.isArray(seg) ? `${seg[0] || ''}–${seg[1] || ''}` : ''))
            .filter((s) => s.length > 1)
            .join(', ');
    }

    /** One preview row's result cell text ("continuous, credited 7:00" style). */
    function formatSplitRowResult(row) {
        if (!row || typeof row !== 'object') {
            return '—';
        }
        const credited = formatSecondsAsHm(row.creditedSeconds);
        let text;
        if (row.split) {
            text = msg('splitPreviewSplitResult', 'Split — partial services {segments}, credited {credited}')
                .replace('{segments}', formatSegments(row.segments) || '—')
                .replace('{credited}', credited);
        } else {
            text = msg('splitPreviewContinuousResult', 'Continuous, credited {credited}')
                .replace('{credited}', credited);
        }
        if (row.breakCovered) {
            text += ' · ' + msg('splitPreviewCovered', 'break covered by interruption');
        }
        return text;
    }

    function showSplitPreviewError(region, err) {
        const callout = region.querySelector('.wtm-split-preview-error');
        const textEl = callout && callout.querySelector('.azc-callout__text');
        const message = (err && err.error)
            || (err && err.data && err.data.error)
            || msg('splitPreviewError', 'The preview could not be calculated. You can still save the model.');
        if (callout && textEl) {
            textEl.textContent = String(message);
            callout.hidden = false;
        }
    }

    /**
     * Server-computed split preview (spec §7.4, §9.8): POSTs the CURRENT
     * draft schedule plus the editable sample times. A generation counter
     * drops stale responses and the in-flight request is aborted on every
     * new click — newest wins, no flicker. Preview never writes.
     */
    function runSplitPreview(fieldset) {
        const region = fieldset.querySelector('.wtm-split-preview');
        if (!region) {
            return;
        }
        const statusEl = region.querySelector('.wtm-split-preview-status');
        const errorCallout = region.querySelector('.wtm-split-preview-error');
        const sampleDay = splitPreviewWeekday(fieldset);
        const sampleStartEl = region.querySelector('.wtm-split-sample-start');
        const sampleEndEl = region.querySelector('.wtm-split-sample-end');
        // Keep the sample prefilled from the first block while untouched —
        // a user-entered pair is never overwritten.
        if (sampleDay && sampleStartEl && sampleEndEl && (!sampleStartEl.value || !sampleEndEl.value)) {
            const mainTr = fieldset.querySelector(`tr[data-day="${sampleDay}"]:not([data-extra-block])`);
            const first = mainTr ? blockInputsOfRow(mainTr) : null;
            if (first) {
                if (!sampleStartEl.value) {
                    sampleStartEl.value = first.start;
                }
                if (!sampleEndEl.value) {
                    sampleEndEl.value = first.end;
                }
            }
        }
        const gen = (region._splitPreviewGen = (region._splitPreviewGen || 0) + 1);
        if (region._splitPreviewCtl && typeof region._splitPreviewCtl.abort === 'function') {
            try {
                region._splitPreviewCtl.abort();
            } catch (_e) { /* already settled */ }
        }
        const ctl = typeof AbortController === 'function' ? new AbortController() : null;
        region._splitPreviewCtl = ctl;
        region.setAttribute('aria-busy', 'true');
        if (statusEl) {
            statusEl.textContent = msg('splitPreviewComputing', 'Computing preview…');
        }
        if (errorCallout) {
            errorCallout.hidden = true;
            const errText = errorCallout.querySelector('.azc-callout__text');
            if (errText) {
                errText.textContent = '';
            }
        }
        const body = { weekday_schedule: collect(fieldset) };
        const sampleStart = sampleStartEl ? String(sampleStartEl.value || '') : '';
        const sampleEnd = sampleEndEl ? String(sampleEndEl.value || '') : '';
        if (sampleDay && sampleStart && sampleEnd) {
            body.sample = { weekday: sampleDay, start: sampleStart, end: sampleEnd };
        }
        const settle = () => {
            if (region._splitPreviewGen !== gen) {
                return false;
            }
            region.removeAttribute('aria-busy');
            if (statusEl) {
                statusEl.textContent = '';
            }
            return true;
        };
        if (!Utils || typeof Utils.ajax !== 'function') {
            if (settle()) {
                showSplitPreviewError(region, null);
            }
            return;
        }
        Utils.ajax('/apps/arbeitszeitcheck/api/admin/working-time-models/split-preview', {
            method: 'POST',
            data: body,
            signal: ctl ? ctl.signal : null,
            onSuccess: function(data) {
                if (!settle()) {
                    return; // stale response — a newer request owns the region
                }
                renderSplitPreview(fieldset, data);
            },
            onError: function(err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                if (!settle()) {
                    return;
                }
                showSplitPreviewError(region, err);
            },
        });
    }

    /**
     * Render the documented preview shape defensively: rows under
     * `preview` or at top level, each {clockIn, clockOut, split,
     * segments, interruptionSeconds, creditedSeconds, breakCovered},
     * plus `cliffDeltaSeconds`. Missing rows are skipped.
     */
    function renderSplitPreview(fieldset, data) {
        const tbody = fieldset.querySelector('.wtm-split-preview-rows');
        if (!tbody) {
            return;
        }
        const src = (data && typeof data === 'object')
            ? ((data.preview && typeof data.preview === 'object') ? data.preview : data)
            : {};
        tbody.querySelectorAll('tr[data-preview-result]').forEach((tr) => tr.remove());
        const sampleTr = tbody.querySelector('tr[data-preview-row="sample"]');
        [
            ['at_cutoff', msg('splitPreviewAtCutoff', 'At cut-off')],
            ['just_after', msg('splitPreviewJustAfter', 'One minute later')],
            ['planned_day', msg('splitPreviewPlannedDay', 'Planned day')],
        ].forEach(([key, label]) => {
            const row = src[key];
            if (!row || typeof row !== 'object') {
                return;
            }
            const tr = document.createElement('tr');
            tr.setAttribute('data-preview-result', key);
            const th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            const tdIn = document.createElement('td');
            tdIn.textContent = row.clockIn || '—';
            const tdOut = document.createElement('td');
            tdOut.textContent = row.clockOut || '—';
            const tdRes = document.createElement('td');
            tdRes.textContent = formatSplitRowResult(row);
            tr.appendChild(th);
            tr.appendChild(tdIn);
            tr.appendChild(tdOut);
            tr.appendChild(tdRes);
            tbody.insertBefore(tr, sampleTr);
        });
        if (sampleTr) {
            const resCell = sampleTr.querySelector('.wtm-split-preview-sample-result');
            const srow = src.sample;
            if (resCell) {
                resCell.textContent = (srow && typeof srow === 'object') ? formatSplitRowResult(srow) : '—';
            }
        }
        const cliff = Number(src.cliffDeltaSeconds);
        const cliffEl = fieldset.querySelector('.wtm-split-preview-cliff');
        if (cliffEl) {
            if (Number.isFinite(cliff) && cliff >= 3600) {
                const txt = cliffEl.querySelector('.azc-callout__text');
                if (txt) {
                    txt.textContent = msg(
                        'splitCliffNotice',
                        'Clocking out one minute later changes the credited time by {t}. Check that this is intended.'
                    ).replace('{t}', formatSecondsAsHm(cliff));
                }
                cliffEl.hidden = false;
            } else {
                cliffEl.hidden = true;
            }
        }
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
        let anyInvalidNet = false;
        fieldset.querySelectorAll('tr[data-day]:not([data-extra-block])').forEach((mainTr) => {
            const day = mainTr.getAttribute('data-day');
            const work = mainTr.querySelector('.wtm-day-work')?.checked;
            const netEl = document.getElementById(`${prefix}-${day}-net`);
            const rows = dayRows(fieldset, day);
            rows.forEach((tr) => {
                tr.querySelectorAll('input[type="time"]').forEach((el) => {
                    el.disabled = !work;
                });
            });
            const addBtn = mainTr.querySelector('.wtm-add-block');
            if (addBtn) {
                addBtn.disabled = !work || rows.length >= MAX_BLOCKS_PER_DAY;
            }
            const copyBtn = mainTr.querySelector('.wtm-copy-day');
            if (copyBtn && !fieldset.dataset.copySourceDay) {
                copyBtn.disabled = !work;
            }
            const bStart = mainTr.querySelector('.wtm-day-break-start');
            const bEnd = mainTr.querySelector('.wtm-day-break-end');
            const netValueEl = netEl ? netEl.querySelector('.wtm-day-net-value') : null;
            if (!work) {
                if (netValueEl) {
                    netValueEl.textContent = '—';
                }
                return;
            }
            anyWork = true;
            const blocks = rows.map(blockInputsOfRow);
            const net = netHoursForDay(blocks, bStart?.value, bEnd?.value);
            if (netValueEl) {
                netValueEl.textContent = net === null ? '—' : `${net.toFixed(2)} h`;
            }
            if (net !== null) {
                week += net;
            } else {
                anyInvalidNet = true;
            }
        });
        const totalEl = document.getElementById(`${prefix}-week-total`);
        if (totalEl) {
            // Draft-invalid days make the week total unavailable — never a
            // partial sum that silently reads as the real total (spec §7.6).
            totalEl.textContent = anyWork
                ? (anyInvalidNet
                    ? msg('weekTotalUnavailable', 'Week total: unavailable until every working day is complete')
                    : msg('weekTotalNet', 'Week total (net): {h} h').replace('{h}', week.toFixed(2)))
                : '';
        }
        const names = scalarFieldNames(fieldset);
        const form = fieldset.closest('form');
        const weekly = form?.querySelector(`[name="${names[0]}"]`);
        const daily = form?.querySelector(`[name="${names[1]}"]`);
        const workDays = form?.querySelector(`[name="${names[2]}"]`);
        const workCount = fieldset.querySelectorAll('.wtm-day-work:checked').length;
        if (anyWork && !anyInvalidNet) {
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
        updateSplitServiceVisibility(fieldset);
    }

    /** True while at least one checked work day has two or more block rows. */
    function hasWorkDayWithMultipleBlocks(fieldset) {
        let found = false;
        fieldset.querySelectorAll('tr[data-day]:not([data-extra-block])').forEach((mainTr) => {
            if (found) {
                return;
            }
            if (mainTr.querySelector('.wtm-day-work')?.checked
                && dayRows(fieldset, mainTr.getAttribute('data-day')).length > 1) {
                found = true;
            }
        });
        return found;
    }

    /**
     * The split-service disclosure exists only for schedules with at least
     * one interruption (spec §7.2/§7.6): show/hide it live as blocks are
     * added, removed or days un-checked.
     */
    function updateSplitServiceVisibility(fieldset) {
        const details = fieldset.querySelector('.wtm-split-service');
        if (!details) {
            return;
        }
        details.hidden = !hasWorkDayWithMultipleBlocks(fieldset);
        // Keep the cut-off's enabled state in lockstep with its switch and
        // the per-weekday override rows in lockstep with the blocks.
        syncSplitWeekdayCutoffs(fieldset);
        syncSplitCutoff(fieldset);
    }

    function copyDayLabels() {
        return {
            mon: msg('monday', 'Monday'), tue: msg('tuesday', 'Tuesday'),
            wed: msg('wednesday', 'Wednesday'), thu: msg('thursday', 'Thursday'),
            fri: msg('friday', 'Friday'), sat: msg('saturday', 'Saturday'),
            sun: msg('sunday', 'Sunday'),
        };
    }

    /**
     * NH-03 copy-day helper: first ⧉ click "arms" the source day; the other
     * day buttons become paste targets, a live status explains the state,
     * and clicking the source again (or Escape) cancels. No dialog.
     */
    function armCopyDay(fieldset, day) {
        fieldset.dataset.copySourceDay = day;
        syncCopyDayButtons(fieldset);
        const status = fieldset.querySelector('.wtm-copy-status');
        const labels = copyDayLabels();
        if (status) {
            status.textContent = msg('copyDayArmed', '%s selected — choose another day to paste into.')
                .replace('%s', labels[day] || day);
        }
    }

    function disarmCopyDay(fieldset) {
        delete fieldset.dataset.copySourceDay;
        syncCopyDayButtons(fieldset);
        const status = fieldset.querySelector('.wtm-copy-status');
        if (status) {
            status.textContent = '';
        }
    }

    function syncCopyDayButtons(fieldset) {
        const labels = copyDayLabels();
        const source = fieldset.dataset.copySourceDay || '';
        fieldset.querySelectorAll('.wtm-copy-day').forEach((btn) => {
            const day = btn.getAttribute('data-day');
            const dayName = labels[day] || day;
            if (!source) {
                btn.setAttribute('aria-pressed', 'false');
                btn.textContent = '⧉';
                btn.setAttribute('aria-label', dayName + ' ' + msg('copyDayTo', 'Copy this day to…'));
                return;
            }
            if (day === source) {
                btn.setAttribute('aria-pressed', 'true');
                btn.textContent = msg('copyDayCancel', 'Cancel');
                btn.setAttribute('aria-label', msg('copyDayCancelAria', 'Cancel copying %s').replace('%s', dayName));
            } else {
                btn.setAttribute('aria-pressed', 'false');
                btn.textContent = '⇥';
                btn.setAttribute('aria-label', msg('copyDayPasteInto', 'Paste into %s').replace('%s', dayName));
            }
        });
        // Keep buttons enabled state consistent with the day's work flag.
        // While armed every target stays enabled — pasting activates the
        // day and is the whole point of copying onto an off day.
        fieldset.querySelectorAll('tr[data-day]:not([data-extra-block])').forEach((mainTr) => {
            const work = mainTr.querySelector('.wtm-day-work')?.checked;
            const btn = mainTr.querySelector('.wtm-copy-day');
            if (btn) {
                btn.disabled = source ? false : !work;
            }
        });
    }

    function applyCopyDay(fieldset, sourceDay, targetDay) {
        const prefix = fieldset.getAttribute('data-schedule-prefix') || 'wtm';
        const labels = copyDayLabels();
        const srcRows = dayRows(fieldset, sourceDay);
        const blocks = srcRows.map(blockInputsOfRow);
        const srcMain = fieldset.querySelector(`tr[data-day="${sourceDay}"]:not([data-extra-block])`);
        const brStart = srcMain?.querySelector('.wtm-day-break-start')?.value || '';
        const brEnd = srcMain?.querySelector('.wtm-day-break-end')?.value || '';

        extraBlockRows(fieldset, targetDay).forEach((tr) => tr.remove());
        for (let i = 1; i < blocks.length; i++) {
            addBlockRow(fieldset, targetDay, prefix, null);
        }
        dayRows(fieldset, targetDay).forEach((tr, i) => {
            const startEl = tr.querySelector('.wtm-day-start');
            const endEl = tr.querySelector('.wtm-day-end');
            if (startEl) {
                startEl.value = blocks[i] ? blocks[i].start : '';
            }
            if (endEl) {
                endEl.value = blocks[i] ? blocks[i].end : '';
            }
        });
        const work = fieldset.querySelector(`#${prefix}-${targetDay}-work`);
        if (work) {
            work.checked = true;
        }
        const bStart = fieldset.querySelector(`#${prefix}-${targetDay}-bstart`);
        const bEnd = fieldset.querySelector(`#${prefix}-${targetDay}-bend`);
        if (bStart) {
            bStart.value = brStart;
        }
        if (bEnd) {
            bEnd.value = brEnd;
        }
        refreshTotals(fieldset);
        updateSplitServiceVisibility(fieldset);
        const status = fieldset.querySelector('.wtm-copy-status');
        if (status) {
            status.textContent = msg('copyDayPasted', 'Copied %s to %s.')
                .replace('%s', labels[sourceDay] || sourceDay)
                .replace('%s', labels[targetDay] || targetDay);
        }
        const targetMain = fieldset.querySelector(`tr[data-day="${targetDay}"]:not([data-extra-block])`);
        const focusEl = targetMain?.querySelector('.wtm-day-start');
        if (focusEl && typeof focusEl.focus === 'function') {
            focusEl.focus();
        }
    }

    function bind(fieldset) {
        if (!fieldset || fieldset.dataset.bound === '1') {
            return;
        }
        fieldset.dataset.bound = '1';
        fieldset.addEventListener('change', (ev) => {
            refreshTotals(fieldset);
            const t = ev.target;
            if (!(t instanceof HTMLInputElement)) {
                return;
            }
            if (t.type === 'radio' && /-schedule-mode$/.test(t.name || '')) {
                applyScheduleMode(fieldset, t.value === '2' ? '2' : '1');
            } else if (t.classList.contains('wtm-split-auto')) {
                syncSplitCutoff(fieldset);
                if (t.checked) {
                    // Progressive disclosure: the revealed cut-off is the
                    // next logical field — move focus there (spec §7.2).
                    const cutoff = fieldset.querySelector('.wtm-split-cutoff');
                    if (cutoff && typeof cutoff.focus === 'function') {
                        cutoff.focus();
                    }
                }
            }
        });
        fieldset.addEventListener('input', (ev) => {
            refreshTotals(fieldset);
            const t = ev.target;
            if (t instanceof HTMLInputElement && t.classList.contains('wtm-split-cutoff')) {
                clearSplitCutoffError(fieldset);
            }
        });
        fieldset.addEventListener('click', (ev) => {
            if (!(ev.target instanceof Element)) {
                return;
            }
            const addBtn = ev.target.closest('[data-action="add-block"]');
            if (addBtn) {
                addBlockRow(
                    fieldset,
                    addBtn.getAttribute('data-day'),
                    fieldset.getAttribute('data-schedule-prefix') || 'wtm',
                    {
                        mon: msg('monday', 'Monday'), tue: msg('tuesday', 'Tuesday'),
                        wed: msg('wednesday', 'Wednesday'), thu: msg('thursday', 'Thursday'),
                        fri: msg('friday', 'Friday'), sat: msg('saturday', 'Saturday'),
                        sun: msg('sunday', 'Sunday'),
                        start: msg('start', 'Start'), end: msg('end', 'End'),
                        block: msg('block', 'block'),
                        remove: msg('removeWorkBlock', 'Remove block'),
                    }
                );
                // A second block IS blocks mode — flip the card/output so the
                // UI can never disagree with the document that gets saved.
                if (fieldset.getAttribute('data-schedule-mode') !== '2') {
                    fieldset.setAttribute('data-schedule-version', '2');
                    fieldset.setAttribute('data-schedule-mode', '2');
                    syncModeClass(fieldset, '2');
                    const v2Radio = fieldset.querySelector('input[type="radio"][name$="-schedule-mode"][value="2"]');
                    if (v2Radio) {
                        v2Radio.checked = true;
                    }
                }
                refreshTotals(fieldset);
                return;
            }
            const removeBtn = ev.target.closest('[data-action="remove-block"]');
            if (removeBtn) {
                const tr = removeBtn.closest('tr[data-extra-block]');
                if (tr) {
                    const day = tr.getAttribute('data-day');
                    const nextTr = (tr.nextElementSibling
                        && tr.nextElementSibling.matches(`tr[data-day="${day}"][data-extra-block]`))
                        ? tr.nextElementSibling
                        : null;
                    tr.remove();
                    // Focus moves to the next block row, or back to "Add"
                    // when the removed row was the last block (spec §7.7).
                    const focusTarget = nextTr
                        ? nextTr.querySelector('.wtm-day-start')
                        : fieldset.querySelector(`.wtm-add-block[data-day="${day}"]`);
                    if (focusTarget && typeof focusTarget.focus === 'function') {
                        focusTarget.focus();
                    }
                    refreshTotals(fieldset);
                }
                return;
            }
            if (ev.target.closest('[data-action="split-preview"]')) {
                runSplitPreview(fieldset);
            }
            const copyBtn = ev.target instanceof Element ? ev.target.closest('[data-action="copy-day"]') : null;
            if (copyBtn) {
                const day = copyBtn.getAttribute('data-day');
                const source = fieldset.dataset.copySourceDay || '';
                if (source === day) {
                    disarmCopyDay(fieldset);
                } else if (source) {
                    applyCopyDay(fieldset, source, day);
                    disarmCopyDay(fieldset);
                } else {
                    armCopyDay(fieldset, day);
                }
            }
        });
        fieldset.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && fieldset.dataset.copySourceDay) {
                disarmCopyDay(fieldset);
            }
        });
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
                fieldset.querySelectorAll('tr[data-extra-block]').forEach((tr) => tr.remove());
                fieldset.querySelectorAll('.wtm-day-work').forEach((cb) => {
                    cb.checked = false;
                });
                fieldset.querySelectorAll('input[type="time"]').forEach((el) => {
                    el.value = '';
                });
                refreshTotals(fieldset);
            });
        }
        syncSplitCutoff(fieldset);
        refreshTotals(fieldset);
    }

    function applyTo(fieldset, schedule) {
        const prefix = fieldset.getAttribute('data-schedule-prefix') || 'wtm';
        const days = schedule.days || {};
        Object.keys(days).forEach((day) => {
            const row = days[day] || {};
            // Reset extra block rows; re-add as needed for this day's blocks.
            extraBlockRows(fieldset, day).forEach((tr) => tr.remove());
            const work = fieldset.querySelector(`#${prefix}-${day}-work`);
            const blocks = Array.isArray(row.blocks) && row.blocks.length
                ? row.blocks
                : (row.work ? [{ start: row.start || '', end: row.end || '' }] : []);
            for (let i = 1; i < blocks.length; i++) {
                addBlockRow(fieldset, day, prefix, null);
            }
            const rows = dayRows(fieldset, day);
            rows.forEach((tr, i) => {
                const block = blocks[i] || {};
                const startEl = tr.querySelector('.wtm-day-start');
                const endEl = tr.querySelector('.wtm-day-end');
                if (startEl) {
                    startEl.value = block.start || '';
                }
                if (endEl) {
                    endEl.value = block.end || '';
                }
            });
            if (work) {
                work.checked = !!row.work;
            }
            const br = (row.breaks && row.breaks[0]) ? row.breaks[0] : {};
            const bStart = fieldset.querySelector(`#${prefix}-${day}-bstart`);
            const bEnd = fieldset.querySelector(`#${prefix}-${day}-bend`);
            if (bStart) {
                bStart.value = br.start || '';
            }
            if (bEnd) {
                bEnd.value = br.end || '';
            }
        });
        // Loaded v2 documents never downgrade (server would 409 anyway);
        // anything else settles on the applied document's version.
        const effectiveVersion = (Number(schedule.version) === 2
            || fieldset.getAttribute('data-loaded-version') === '2') ? '2' : '1';
        fieldset.setAttribute('data-schedule-version', effectiveVersion);
        fieldset.setAttribute('data-schedule-mode', effectiveVersion);
        syncModeClass(fieldset, effectiveVersion);
        const modeRadio = fieldset.querySelector(`input[type="radio"][name$="-schedule-mode"][value="${effectiveVersion}"]`);
        if (modeRadio) {
            modeRadio.checked = true;
        }
        // Split-service controls follow the applied document (absent = off).
        const splitCfg = (schedule.split_service && typeof schedule.split_service === 'object')
            ? schedule.split_service
            : {};
        const autoEl = fieldset.querySelector('.wtm-split-auto');
        const coverEl = fieldset.querySelector('.wtm-split-break-cover');
        const cutoffEl = fieldset.querySelector('.wtm-split-cutoff');
        if (autoEl) {
            autoEl.checked = splitCfg.automatic_split === true;
        }
        if (coverEl) {
            coverEl.checked = splitCfg.interruption_counts_as_break === true;
        }
        if (cutoffEl) {
            cutoffEl.value = (autoEl && autoEl.checked && typeof splitCfg.split_when_clock_out_after === 'string')
                ? splitCfg.split_when_clock_out_after
                : '';
        }
        fieldset.dataset.pendingSplitWeekdayCutoffs = JSON.stringify(
            (splitCfg.weekday_cutoffs && typeof splitCfg.weekday_cutoffs === 'object')
                ? splitCfg.weekday_cutoffs
                : {}
        );
        syncSplitWeekdayCutoffs(fieldset);
        syncSplitCutoff(fieldset);
    }

    function collect(fieldset) {
        if (!fieldset) {
            return null;
        }
        const days = {};
        let any = false;
        let anyMultiBlock = false;
        fieldset.querySelectorAll('tr[data-day]:not([data-extra-block])').forEach((mainTr) => {
            const day = mainTr.getAttribute('data-day');
            const work = !!mainTr.querySelector('.wtm-day-work')?.checked;
            if (!work) {
                days[day] = { work: false };
                return;
            }
            any = true;
            const rows = dayRows(fieldset, day);
            const blocks = rows.map(blockInputsOfRow);
            if (blocks.length > 1) {
                anyMultiBlock = true;
            }
            const bStart = mainTr.querySelector('.wtm-day-break-start')?.value || '';
            const bEnd = mainTr.querySelector('.wtm-day-break-end')?.value || '';
            const breaks = [];
            if (bStart && bEnd) {
                breaks.push({ start: bStart, end: bEnd, paid: false });
            }
            days[day] = { work: true, blocks, breaks, _single: blocks[0] };
        });
        if (!any) {
            // All-off is a real, persistable schedule only where the caller
            // opted in (on-demand contracts) — otherwise "no day checked"
            // still encodes "no schedule", so the field can be cleared.
            const keepV2 = fieldset.getAttribute('data-schedule-version') === '2';
            if (fieldset.dataset.allOffSchedule === '1') {
                return keepV2 ? { version: 2, interruption_mode: 'recorded_only', days } : { version: 1, days };
            }
            return null;
        }
        // Emit v1 byte-shape when the editor stays within v1 semantics AND
        // the loaded schedule was v1 — a loaded v2 document must never be
        // silently downgraded on save (server would 409 anyway).
        const isV2 = fieldset.getAttribute('data-schedule-version') === '2' || anyMultiBlock;
        if (!isV2) {
            Object.keys(days).forEach((day) => {
                const row = days[day];
                if (row.work && row._single) {
                    days[day] = { work: true, start: row._single.start, end: row._single.end, breaks: row.breaks };
                }
            });
            return { version: 1, days };
        }
        Object.keys(days).forEach((day) => {
            const row = days[day];
            if (row.work) {
                delete row._single;
            }
        });
        const doc = { version: 2, interruption_mode: 'recorded_only', days };
        const split = collectSplitService(fieldset, anyMultiBlock);
        if (split) {
            doc.split_service = split;
        }
        return doc;
    }

    /**
     * Split-service options → canonical form (spec §9.2):
     * {automatic_split, split_when_clock_out_after: 'HH:MM'|null,
     *  interruption_counts_as_break}. Emitted only while the section is
     * visible (≥1 working day with ≥2 blocks) AND at least one option is
     * on — the server stores "all off" as an absent key. Switches are read
     * via .checked (never .value — hidden "0" + checkbox "1" pattern).
     * Automatic off always means a null cut-off.
     */
    function collectSplitService(fieldset, anyMultiBlock) {
        const details = fieldset.querySelector('.wtm-split-service');
        if (!details || details.hidden || !anyMultiBlock) {
            return null;
        }
        const auto = !!details.querySelector('.wtm-split-auto')?.checked;
        const cover = !!details.querySelector('.wtm-split-break-cover')?.checked;
        if (!auto && !cover) {
            return null;
        }
        const cutoff = auto
            ? String(details.querySelector('.wtm-split-cutoff')?.value || '').trim()
            : '';
        const doc = {
            automatic_split: auto,
            split_when_clock_out_after: cutoff !== '' ? cutoff : null,
            interruption_counts_as_break: cover,
        };
        if (auto) {
            const weekdayCutoffs = {};
            details.querySelectorAll('.wtm-split-day-cutoff').forEach((el) => {
                const day = el.getAttribute('data-day');
                const value = String(el.value || '').trim();
                if (day && value !== '') {
                    weekdayCutoffs[day] = value;
                }
            });
            if (Object.keys(weekdayCutoffs).length > 0) {
                doc.weekday_cutoffs = weekdayCutoffs;
            }
        }
        return doc;
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
        showSplitCutoffError: showSplitCutoffError,
        clearSplitCutoffError: clearSplitCutoffError,
        formatSecondsAsHm: formatSecondsAsHm,
    };
})();
