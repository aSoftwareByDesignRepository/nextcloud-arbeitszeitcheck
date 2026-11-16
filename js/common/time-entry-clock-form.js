/**
 * Shared date + HH:mm matrix and break editor for time-entry corrections.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};

	function escHtml(value) {
		const s = String(value ?? '');
		if (typeof Utils.escapeHtml === 'function') {
			return Utils.escapeHtml(s);
		}
		return s
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function resolveMinBreakMinutes(overrideMinutes) {
		const fromOverride = Number(overrideMinutes);
		if (Number.isFinite(fromOverride) && fromOverride > 0) {
			return Math.round(fromOverride);
		}
		const Validation = window.ArbeitszeitCheckValidation;
		if (Validation && typeof Validation.getMinBreakMinutes === 'function') {
			return Validation.getMinBreakMinutes();
		}
		const raw = Number(window.ArbeitszeitCheck?.complianceParams?.minBreakMinutes);
		return Number.isFinite(raw) && raw > 0 ? Math.round(raw) : 15;
	}

	function resolveMinBreakSeconds(overrideMinutes) {
		return resolveMinBreakMinutes(overrideMinutes) * 60;
	}

	function convertEuropeanToIso(dateStr) {
		const dp = window.ArbeitszeitCheckDatepicker;
		if (dp && typeof dp.convertEuropeanToISO === 'function') {
			return dp.convertEuropeanToISO(dateStr);
		}
		if (!dateStr) {
			return '';
		}
		if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
			return dateStr;
		}
		const parts = dateStr.split('.');
		if (parts.length === 3) {
			return parts[2] + '-' + parts[1].padStart(2, '0') + '-' + parts[0].padStart(2, '0');
		}
		return '';
	}

	function isoToHm(iso) {
		const api = window.ArbeitszeitCheckTime;
		if (!iso) {
			return { hour: '', minute: '' };
		}
		const tm = api ? api.formatTime(iso) : '';
		if (!tm || !/^\d{2}:\d{2}/.test(tm)) {
			return { hour: '', minute: '' };
		}
		const parts = tm.split(':');
		return { hour: parts[0], minute: parts[1] };
	}

	function isoToEuropeanDate(iso) {
		const api = window.ArbeitszeitCheckTime;
		if (!iso) {
			return '';
		}
		return api ? (api.formatDate(iso) || '') : '';
	}

	function hmOnDateToMs(dateIso, hm) {
		if (!dateIso || !hm || !/^\d{2}:\d{2}$/.test(hm)) {
			return NaN;
		}
		const ms = new Date(dateIso + 'T' + hm + ':00').getTime();
		return Number.isNaN(ms) ? NaN : ms;
	}

	function workEndMs(dateIso, startHm, endHm) {
		const startMs = hmOnDateToMs(dateIso, startHm);
		let endMs = hmOnDateToMs(dateIso, endHm);
		if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
			return { startMs: NaN, endMs: NaN };
		}
		if (endMs <= startMs) {
			endMs = hmOnDateToMs(dateIso, endHm) + 86400000;
		}
		return { startMs, endMs };
	}

	function addTimeOptions(hourSelect, minuteSelect, includeEmpty) {
		if (!hourSelect || !minuteSelect) {
			return;
		}
		hourSelect.innerHTML = '';
		minuteSelect.innerHTML = '';
		if (includeEmpty) {
			const empty = document.createElement('option');
			empty.value = '';
			empty.textContent = '--';
			hourSelect.appendChild(empty.cloneNode(true));
			minuteSelect.appendChild(empty.cloneNode(true));
		}
		for (let h = 0; h < 24; h++) {
			const opt = document.createElement('option');
			opt.value = String(h).padStart(2, '0');
			opt.textContent = opt.value;
			hourSelect.appendChild(opt);
		}
		for (let m = 0; m < 60; m++) {
			const opt = document.createElement('option');
			opt.value = String(m).padStart(2, '0');
			opt.textContent = opt.value;
			minuteSelect.appendChild(opt);
		}
	}

	function bindTimeInputs(hourSelect, minuteSelect, hiddenInput) {
		if (!hourSelect || !minuteSelect || !hiddenInput) {
			return;
		}
		if (hourSelect.dataset.bound === 'true') {
			return;
		}
		hourSelect.dataset.bound = 'true';
		const update = () => {
			const hour = hourSelect.value;
			const minute = minuteSelect.value;
			if (!hour || hour === '--' || !minute || minute === '--') {
				hiddenInput.value = '';
				return;
			}
			hiddenInput.value = hour + ':' + minute;
		};
		hourSelect.addEventListener('change', update);
		minuteSelect.addEventListener('change', update);
		update();
	}

	function setSelectHm(hourSelect, minuteSelect, hm) {
		if (!hourSelect || !minuteSelect) {
			return;
		}
		const hasHm = hm && hm.hour !== '' && hm.minute !== '';
		if (hasHm) {
			hourSelect.value = hm.hour;
			minuteSelect.value = hm.minute;
		} else {
			hourSelect.value = '';
			minuteSelect.value = '';
		}
		hourSelect.dispatchEvent(new Event('change'));
	}

	function pickerAvailable() {
		const tp = window.ArbeitszeitCheckTimePicker;
		return !!(tp && typeof tp.create === 'function' && typeof tp.initInRoot === 'function');
	}

	function pickerAttr(name, value) {
		if (value === undefined || value === null || value === '') {
			return '';
		}
		return ' ' + name + '="' + String(value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;') + '"';
	}

	/**
	 * Markup twin of lib/Support/TimePickerField::render — the hidden input
	 * keeps the submitted value so collect/validate code stays identical.
	 */
	function pickerHostHtml(spec) {
		return [
			'<div class="azc-timepicker" data-azc-timepicker data-allow-empty="',
			spec.allowEmpty ? '1' : '0',
			'"',
			spec.required ? ' data-required="1"' : '',
			pickerAttr('data-label-id', spec.labelId),
			pickerAttr('data-described-by', spec.describedBy),
			pickerAttr('data-hour-label', spec.hourLabel),
			pickerAttr('data-minute-label', spec.minuteLabel),
			pickerAttr('data-open-label', spec.openLabel),
			pickerAttr('data-hours-label', spec.hoursLabel),
			pickerAttr('data-minutes-label', spec.minutesLabel),
			pickerAttr('data-done-label', spec.doneLabel),
			pickerAttr('data-not-set-label', spec.notSetLabel),
			pickerAttr('data-clear-label', spec.clearLabel),
			'><input type="hidden"',
			pickerAttr('id', spec.id),
			pickerAttr('name', spec.name),
			pickerAttr('class', spec.hiddenClass),
			pickerAttr('value', spec.value),
			spec.breakIndex !== undefined && spec.breakIndex !== null
				? ' data-break-index="' + String(spec.breakIndex) + '"'
				: '',
			spec.required ? ' required' : '',
			'></div>',
		].join('');
	}

	function pickerHostForHidden(hiddenInput) {
		if (!hiddenInput) {
			return null;
		}
		return hiddenInput.closest ? hiddenInput.closest('[data-azc-timepicker]') : null;
	}

	function setPickerValue(hiddenInput, hm) {
		const tp = window.ArbeitszeitCheckTimePicker;
		const host = pickerHostForHidden(hiddenInput);
		if (!tp || !host || typeof tp.setValue !== 'function') {
			return;
		}
		const value = hm && hm.hour !== '' && hm.minute !== '' && hm.hour !== undefined
			? hm.hour + ':' + hm.minute
			: '';
		tp.setValue(host, value);
	}

	function strToHm(str) {
		if (!str || !/^\d{1,2}:\d{2}$/.test(str)) {
			return { hour: '', minute: '' };
		}
		const parts = str.split(':');
		return { hour: parts[0].padStart(2, '0'), minute: parts[1] };
	}

	function normalizeBreaksFromSummary(breaks) {
		if (!Array.isArray(breaks)) {
			return [];
		}
		return breaks.map((b) => {
			if (b.start && b.end) {
				return { start: b.start, end: b.end };
			}
			const start = isoToHm(b.start || b.startTime);
			const end = isoToHm(b.end || b.endTime);
			if (!start.hour || !end.hour) {
				return null;
			}
			return { start: start.hour + ':' + start.minute, end: end.hour + ':' + end.minute };
		}).filter(Boolean);
	}

	function collectBreaks(container) {
		const rows = container ? container.querySelectorAll('.clock-form-break-row') : [];
		const breaks = [];
		rows.forEach((row) => {
			const start = row.querySelector('.clock-form-break-start-hidden')?.value || '';
			const end = row.querySelector('.clock-form-break-end-hidden')?.value || '';
			if (start && end) {
				breaks.push({ start, end });
			}
		});
		return breaks;
	}

	function validateBreaks(dateIso, startHm, endHm, breaks, t, minBreakMinutes) {
		const minBreakSeconds = resolveMinBreakSeconds(minBreakMinutes);
		const { startMs, endMs } = workEndMs(dateIso, startHm, endHm);
		if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
			return { ok: false, error: t('invalidWorkTimes', 'Please enter valid start and end times.') };
		}
		const sorted = breaks.slice().sort((a, b) => a.start.localeCompare(b.start));
		let prevEnd = startMs;
		for (let i = 0; i < sorted.length; i++) {
			const b = sorted[i];
			const bStart = hmOnDateToMs(dateIso, b.start);
			let bEnd = hmOnDateToMs(dateIso, b.end);
			if (Number.isNaN(bStart) || Number.isNaN(bEnd)) {
				return { ok: false, error: t('invalidBreakTimes', 'Please enter valid break times.') };
			}
			if (bEnd <= bStart) {
				bEnd += 86400000;
			}
			if (bEnd - bStart < minBreakSeconds * 1000) {
				const minutes = resolveMinBreakMinutes(minBreakMinutes);
				return {
					ok: false,
					error: t('breakTooShort', 'Each break must be at least {minutes} minutes.').replace('{minutes}', String(minutes)),
				};
			}
			if (bStart < startMs || bEnd > endMs) {
				return { ok: false, error: t('breakOutsideWork', 'Breaks must be within working hours.') };
			}
			if (bStart < prevEnd) {
				return { ok: false, error: t('breaksOverlap', 'Breaks must not overlap.') };
			}
			prevEnd = bEnd;
		}
		return { ok: true };
	}

	function createBreakRow(container, index, breakData, t, usePicker, idPrefix) {
		const entry = document.createElement('div');
		entry.className = 'clock-form-break-row break-entry';
		const breakLabel = (t('breakNumber', 'Break {number}') || 'Break {number}').replace('{number}', String(index + 1));
		const p = idPrefix || 'cf';
		const pickerField = (side, labelText) => {
			const labelId = p + '-break-' + index + '-' + side + '-label';
			const openKey = side === 'start' ? 'chooseBreakStartTime' : 'chooseBreakEndTime';
			const openFallback = side === 'start' ? 'Choose break start time' : 'Choose break end time';
			return [
				'<div class="form-group">',
				'<label class="form-label time-pair-matrix__row-label" id="' + labelId + '"><span class="sr-only">' + breakLabel + ' — ' + labelText + '</span></label>',
				pickerHostHtml({
					id: p + '-break-' + index + '-' + side,
					hiddenClass: 'clock-form-break-' + side + '-hidden',
					breakIndex: index,
					allowEmpty: true,
					labelId: labelId,
					hourLabel: t(side === 'start' ? 'startHour' : 'endHour', side === 'start' ? 'Start hour' : 'End hour'),
					minuteLabel: t(side === 'start' ? 'startMinute' : 'endMinute', side === 'start' ? 'Start minute' : 'End minute'),
					openLabel: t(openKey, openFallback),
					hoursLabel: t('hours', 'Hours'),
					minutesLabel: t('minutes', 'Minutes'),
					doneLabel: t('done', 'Done'),
					notSetLabel: t('notSet', 'Not set'),
				}),
				'</div>',
			].join('');
		};
		entry.innerHTML = usePicker
			? [
				'<div class="time-pair-matrix__grid time-pair-matrix__grid--row">',
				pickerField('start', t('start', 'Start') || 'Start'),
				pickerField('end', t('end', 'End') || 'End'),
				'<div class="time-pair-matrix__action">',
				'<button type="button" class="azc-btn azc-btn--sm azc-btn--danger btn-remove-break clock-form-break-remove">' + (t('remove', 'Remove') || 'Remove') + '</button>',
				'</div>',
				'</div>',
			].join('')
			: [
				'<div class="time-pair-matrix__grid time-pair-matrix__grid--row">',
				'<div class="form-group">',
				'<label class="form-label">' + breakLabel + ' — ' + (t('start', 'Start') || 'Start') + '</label>',
				'<div class="time-input-group" role="group">',
				'<select class="form-input time-hour clock-form-break-start-hour"></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select class="form-input time-minute clock-form-break-start-minute"></select>',
				'<input type="hidden" class="clock-form-break-start-hidden" value="">',
				'</div>',
				'</div>',
				'<div class="form-group">',
				'<label class="form-label">' + breakLabel + ' — ' + (t('end', 'End') || 'End') + '</label>',
				'<div class="time-input-group" role="group">',
				'<select class="form-input time-hour clock-form-break-end-hour"></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select class="form-input time-minute clock-form-break-end-minute"></select>',
				'<input type="hidden" class="clock-form-break-end-hidden" value="">',
				'</div>',
				'</div>',
				'<div class="time-pair-matrix__action">',
				'<button type="button" class="btn btn--sm btn--danger clock-form-break-remove">' + (t('remove', 'Remove') || 'Remove') + '</button>',
				'</div>',
				'</div>',
			].join('');

		const sh = entry.querySelector('.clock-form-break-start-hour');
		const sm = entry.querySelector('.clock-form-break-start-minute');
		const eh = entry.querySelector('.clock-form-break-end-hour');
		const em = entry.querySelector('.clock-form-break-end-minute');
		const shHidden = entry.querySelector('.clock-form-break-start-hidden');
		const ehHidden = entry.querySelector('.clock-form-break-end-hidden');
		addTimeOptions(sh, sm, true);
		addTimeOptions(eh, em, true);
		bindTimeInputs(sh, sm, shHidden);
		bindTimeInputs(eh, em, ehHidden);
		container.appendChild(entry);
		if (usePicker && window.ArbeitszeitCheckTimePicker) {
			window.ArbeitszeitCheckTimePicker.initInRoot(entry);
		}
		if (breakData && breakData.start && breakData.end) {
			const shParts = breakData.start.split(':');
			const ehParts = breakData.end.split(':');
			if (usePicker) {
				setPickerValue(shHidden, { hour: shParts[0], minute: shParts[1] });
				setPickerValue(ehHidden, { hour: ehParts[0], minute: ehParts[1] });
			} else {
				setSelectHm(sh, sm, { hour: shParts[0], minute: shParts[1] });
				setSelectHm(eh, em, { hour: ehParts[0], minute: ehParts[1] });
			}
		}
		entry.querySelector('.clock-form-break-remove')?.addEventListener('click', () => {
			entry.remove();
			if (typeof container._onBreaksChange === 'function') {
				container._onBreaksChange();
			}
		});
		return entry;
	}

	function buildFormHtml(idPrefix, labels, options) {
		const p = idPrefix;
		const L = labels;
		// Server-catalog options (Workforce Suite) replace the built-in enum
		// once provided; the fallback keeps older servers identical.
		const wlOptions = Array.isArray(options && options.workLocations) && options.workLocations.length > 0
			? options.workLocations
			: null;
		const builtinWlOption = (code, label) =>
			'<option value="' + escHtml(code) + '">' + escHtml(label || code) + '</option>';
		const builtinWlLabels = {
			office: L.workLocationOffice || 'Office',
			home: L.workLocationHome || 'Home office',
			external: L.workLocationExternal || 'External appointment',
		};
		const wlOptionHtml = wlOptions !== null
			? wlOptions.map((it) => builtinWlOption(it.code, it.label || builtinWlLabels[it.code] || it.code)).join('')
			: builtinWlOption('office', builtinWlLabels.office)
				+ builtinWlOption('home', builtinWlLabels.home)
				+ builtinWlOption('external', builtinWlLabels.external);
		const usePicker = !!(options && options.useTimePicker) && pickerAvailable();
		const iconHtml = (name) => (window.AzcCatalog && typeof window.AzcCatalog.render === 'function')
			? '<span class="form-label-icon" aria-hidden="true">' + window.AzcCatalog.render(name, 'form-label-icon__svg') + '</span>'
			: '';
		const workingDayHtml = usePicker
			? [
				'<fieldset class="correction-fieldset">',
				'<legend class="correction-dialog__section-title">' + (L.dateTimeLegend || L.workingDayLegend) + '</legend>',
				'<div class="clock-form-when" role="group" aria-label="' + (L.dateTimeLegend || L.workingDayLegend) + '">',
				'<div class="form-group">',
				'<label for="' + p + '-date" id="' + p + '-date-label" class="form-label">' + iconHtml('calendar') + L.date + ' <span class="form-required" aria-hidden="true">*</span><span class="sr-only"> ' + L.required + '</span></label>',
				'<div class="form-input-wrapper form-input-wrapper--date">',
				'<input type="text" id="' + p + '-date" class="form-input datepicker-input correction-date-input" data-datepicker-defer',
				pickerAttr('data-datepicker-min', options.dateMin),
				pickerAttr('data-datepicker-max', options.dateMax),
				' inputmode="numeric" autocomplete="off" spellcheck="false" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" placeholder="' + L.datePlaceholder + '" required aria-required="true" aria-labelledby="' + p + '-date-label" aria-describedby="' + p + '-date-help">',
				'<button type="button" class="btn btn--sm btn--secondary ' + p + '-date-today">' + L.today + '</button>',
				'</div>',
				'<p id="' + p + '-date-help" class="form-help">' + L.dateHelp + '</p>',
				'</div>',
				'<div class="form-group">',
				'<label for="' + p + '-start-time-hour" id="' + p + '-start-time-label" class="form-label">' + iconHtml('clock') + L.startTime + ' <span class="form-required" aria-hidden="true">*</span></label>',
				pickerHostHtml({
					id: p + '-start-time',
					required: true,
					labelId: p + '-start-time-label',
					describedBy: p + '-start-time-help',
					hourLabel: L.startHourLabel || 'Start hour',
					minuteLabel: L.startMinuteLabel || 'Start minute',
					openLabel: L.startOpenLabel || 'Choose start time',
					hoursLabel: L.pickerHoursLabel || 'Hours',
					minutesLabel: L.pickerMinutesLabel || 'Minutes',
					doneLabel: L.pickerDoneLabel || 'Done',
					notSetLabel: L.pickerNotSetLabel || 'Not set',
				}),
				'<p id="' + p + '-start-time-help" class="form-help">' + (L.startTimeHelp || '') + '</p>',
				'</div>',
				'<div class="form-group">',
				'<label for="' + p + '-end-time-hour" id="' + p + '-end-time-label" class="form-label">' + iconHtml('clock') + L.endTime + ' <span class="form-required" aria-hidden="true">*</span></label>',
				pickerHostHtml({
					id: p + '-end-time',
					required: true,
					labelId: p + '-end-time-label',
					describedBy: p + '-end-time-help',
					hourLabel: L.endHourLabel || 'End hour',
					minuteLabel: L.endMinuteLabel || 'End minute',
					openLabel: L.endOpenLabel || 'Choose end time',
					hoursLabel: L.pickerHoursLabel || 'Hours',
					minutesLabel: L.pickerMinutesLabel || 'Minutes',
					doneLabel: L.pickerDoneLabel || 'Done',
					notSetLabel: L.pickerNotSetLabel || 'Not set',
				}),
				'<p id="' + p + '-end-time-help" class="form-help">' + L.nightShiftHint + '</p>',
				'</div>',
				'</div>',
				'</fieldset>',
			].join('')
			: [
				'<fieldset class="correction-fieldset">',
				'<legend class="sr-only">' + L.workingDayLegend + '</legend>',
				'<div class="form-group">',
				'<label for="' + p + '-date" class="form-label">' + L.date + ' <span class="form-required" aria-hidden="true">*</span><span class="sr-only"> ' + L.required + '</span></label>',
				'<div class="form-input-wrapper form-input-wrapper--date">',
				'<input type="text" id="' + p + '-date" class="form-input correction-date-input" data-datepicker-defer inputmode="numeric" autocomplete="off" pattern="\\d{2}\\.\\d{2}\\.\\d{4}" placeholder="' + L.datePlaceholder + '" required aria-required="true">',
				'<button type="button" class="btn btn--sm btn--secondary ' + p + '-date-today">' + L.today + '</button>',
				'</div>',
				'<p class="form-help">' + L.dateHelp + '</p>',
				'</div>',
				'<p class="time-pair-matrix__intro">' + L.workingHours + '</p>',
				'<div class="time-pair-matrix" role="group" aria-label="' + L.workingHours + '">',
				'<div class="time-pair-matrix__grid time-pair-matrix__grid--header">',
				'<span class="time-pair-matrix__colhead">' + L.startTime + '</span>',
				'<span class="time-pair-matrix__colhead">' + L.endTime + '</span>',
				'<span class="time-pair-matrix__colhead time-pair-matrix__colhead--action" aria-hidden="true"></span>',
				'</div>',
				'<div class="time-pair-matrix__grid time-pair-matrix__grid--row">',
				'<div class="form-group">',
				'<label for="' + p + '-start-hour" class="form-label">' + L.start + '</label>',
				'<div class="time-input-group" role="group">',
				'<select id="' + p + '-start-hour" class="form-input time-hour" required aria-label="' + L.startTime + '"></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select id="' + p + '-start-minute" class="form-input time-minute" required></select>',
				'<input type="hidden" id="' + p + '-start-time" value="">',
				'</div>',
				'</div>',
				'<div class="form-group">',
				'<label for="' + p + '-end-hour" class="form-label">' + L.end + '</label>',
				'<div class="time-input-group" role="group">',
				'<select id="' + p + '-end-hour" class="form-input time-hour" required></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select id="' + p + '-end-minute" class="form-input time-minute" required></select>',
				'<input type="hidden" id="' + p + '-end-time" value="">',
				'</div>',
				'</div>',
				'<div class="time-pair-matrix__action time-pair-matrix__action--spacer" aria-hidden="true"></div>',
				'</div>',
				'</div>',
				'<p class="form-help correction-dialog__hint">' + L.nightShiftHint + '</p>',
				'</fieldset>',
			].join('');
		return [
			'<p class="form-help clock-form-intro">' + L.intro + '</p>',
			workingDayHtml,
			'<fieldset class="correction-fieldset correction-fieldset--breaks">',
			'<legend class="correction-dialog__section-title">' + L.breaksOptional + '</legend>',
			L.breaksHelp ? '<p class="form-help">' + L.breaksHelp + '</p>' : '',
			usePicker && options.autoBreak ? [
				'<div class="auto-break-panel auto-break-panel--disabled">',
				'<div class="auto-break-panel__control">',
				'<label class="form-toggle">',
				'<input type="checkbox" id="' + p + '-auto-break-enabled" aria-describedby="' + p + '-auto-break-help ' + p + '-auto-break-state">',
				'<span class="toggle-slider" aria-hidden="true"></span>',
				'<span class="toggle-label">' + (L.autoBreakToggle || '') + '</span>',
				'</label>',
				'<span id="' + p + '-auto-break-state" class="auto-break-toggle__status auto-break-toggle__status--off" role="status" aria-live="polite">' + (L.autoBreakStateOff || '') + '</span>',
				'</div>',
				'<p id="' + p + '-auto-break-help" class="form-help auto-break-panel__help">' + (L.autoBreakHelp || '') + '</p>',
				'</div>',
			].join('') : '',
			usePicker ? '' : '<p class="correction-breaks-empty ' + p + '-breaks-empty">' + L.breaksEmpty + '</p>',
			'<div class="time-pair-matrix time-pair-matrix--breaks" role="group" aria-label="' + L.breaksOptional + '">',
			'<div class="time-pair-matrix__grid time-pair-matrix__grid--header">',
			'<span class="time-pair-matrix__colhead">' + L.startTime + '</span>',
			'<span class="time-pair-matrix__colhead">' + L.endTime + '</span>',
			'<span class="time-pair-matrix__colhead time-pair-matrix__colhead--action">' + L.actions + '</span>',
			'</div>',
			'<div id="' + p + '-breaks-container" class="clock-form-breaks-container"></div>',
			'</div>',
			usePicker && options.autoBreak ? '<p class="form-help ' + p + '-break-tiers-intro" hidden></p>' : '',
			'<button type="button" class="' + (usePicker ? 'azc-btn azc-btn--secondary azc-btn--sm' : 'btn btn--secondary btn--sm') + ' ' + p + '-add-break">' + L.addBreak + '</button>',
			'</fieldset>',
			L.workLocationLegend ? [
				'<fieldset class="correction-fieldset correction-fieldset--work-location">',
				usePicker ? '' : '<legend class="correction-dialog__section-title">' + L.workLocationLegend + '</legend>',
				'<div class="form-group">',
				'<label for="' + p + '-work-location" class="form-label">' + L.workLocationLegend + '</label>',
				'<select id="' + p + '-work-location" class="' + (usePicker ? 'form-select' : 'form-input') + '">',
				'<option value="">' + (L.workLocationUnspecified || '') + '</option>',
				wlOptionHtml,
				// Correction dialogs additionally allow proposing "remove the
				// mark" — sentinelled as __clear so it never collides with
				// "keep current" ('').
				L.workLocationClearOption ? '<option value="__clear">' + L.workLocationClearOption + '</option>' : '',
				'</select>',
				L.workLocationHelp ? '<p class="form-help">' + L.workLocationHelp + '</p>' : '',
				'</div>',
				'</fieldset>',
			].join('') : '',
			'<div class="form-group clock-form-reason">',
			'<label for="' + p + '-reason" class="form-label">' + L.reason + ' <span class="form-required" aria-hidden="true">*</span></label>',
			L.reasonHelp ? '<p id="' + p + '-reason-help" class="form-help">' + L.reasonHelp + '</p>' : '',
			'<textarea id="' + p + '-reason" class="form-textarea" rows="3" minlength="10" maxlength="2000" required aria-required="true"' + (L.reasonHelp ? ' aria-describedby="' + p + '-reason-help"' : '') + '></textarea>',
			'</div>',
			usePicker && options && options.summary ? [
				'<div id="' + p + '-summary" role="status" aria-live="polite" aria-atomic="true" class="clock-form-summary time-summary-card time-summary-card--secondary">',
				'<h4 class="time-summary-title">' + (L.daySummary || 'Day Summary') + '</h4>',
				'<div class="summary-row">',
				'<span class="summary-label">' + (L.workingHours || 'Working Hours') + ':</span>',
				'<span class="summary-amount">',
				'<span id="' + p + '-summary-working" class="summary-value">—</span><span class="summary-unit"> h</span>',
				'</span>',
				'</div>',
				'<div class="summary-row">',
				'<span class="summary-label">' + (L.breakTime || 'Break Time') + ':</span>',
				'<span class="summary-amount">',
				'<span id="' + p + '-summary-break" class="summary-value">—</span><span class="summary-unit"> h</span>',
				'</span>',
				'</div>',
				'</div>',
			].join('') : '',
			'<div class="' + p + '-status correction-dialog__status" aria-live="polite"></div>',
		].join('');
	}

	function bindForm(root, idPrefix, initial, t, options) {
		const p = idPrefix;
		const opts = options && typeof options === 'object' ? options : {};
		const usePicker = !!opts.useTimePicker && pickerAvailable();
		const currentMinBreakMinutes = () => resolveMinBreakMinutes(
			opts.minBreakMinutes !== undefined ? opts.minBreakMinutes : initial?.minBreakMinutes
		);
		const dateInput = root.querySelector('#' + p + '-date');
		const startHour = root.querySelector('#' + p + '-start-hour');
		const startMinute = root.querySelector('#' + p + '-start-minute');
		const startHidden = root.querySelector('#' + p + '-start-time');
		const endHour = root.querySelector('#' + p + '-end-hour');
		const endMinute = root.querySelector('#' + p + '-end-minute');
		const endHidden = root.querySelector('#' + p + '-end-time');
		const breaksContainer = root.querySelector('#' + p + '-breaks-container');
		const breaksEmpty = root.querySelector('.' + p + '-breaks-empty');
		const btnAddBreak = root.querySelector('.' + p + '-add-break');
		const btnToday = root.querySelector('.' + p + '-date-today');
		const workLocationSelect = root.querySelector('#' + p + '-work-location');
		const statusEl = root.querySelector('.' + p + '-status');
		const summaryWork = root.querySelector('#' + p + '-summary-working');
		const summaryBreak = root.querySelector('#' + p + '-summary-break');

		if (usePicker) {
			window.ArbeitszeitCheckTimePicker.initInRoot(root);
		}
		addTimeOptions(startHour, startMinute, true);
		addTimeOptions(endHour, endMinute, true);
		bindTimeInputs(startHour, startMinute, startHidden);
		bindTimeInputs(endHour, endMinute, endHidden);

		if (dateInput && initial.startTime) {
			dateInput.value = isoToEuropeanDate(initial.startTime);
		}
		if (dateInput && !dateInput.value && opts.defaults && opts.defaults.date) {
			dateInput.value = opts.defaults.date;
		}
		const startHm = isoToHm(initial.startTime);
		const endHm = isoToHm(initial.endTime);
		setSelectHm(startHour, startMinute, startHm);
		setSelectHm(endHour, endMinute, endHm);
		if (usePicker) {
			const defaults = opts.defaults && typeof opts.defaults === 'object' ? opts.defaults : {};
			setPickerValue(startHidden, startHm.hour !== '' ? startHm : strToHm(defaults.start));
			setPickerValue(endHidden, endHm.hour !== '' ? endHm : strToHm(defaults.end));
		}
		if (workLocationSelect) {
			const wl = typeof initial.workLocation === 'string' ? initial.workLocation : '';
			const known = Array.isArray(opts.workLocations) && opts.workLocations.length > 0
				? opts.workLocations.map((it) => it.code)
				: ['office', 'home', 'external'];
			if (wl !== '' && !known.includes(wl)) {
				// Stored code no longer in the catalog — keep it visible and
				// selectable so a correction never silently drops the mark.
				const synthetic = document.createElement('option');
				synthetic.value = wl;
				synthetic.textContent = wl;
				workLocationSelect.appendChild(synthetic);
			}
			workLocationSelect.value = known.includes(wl) || wl !== '' ? wl : '';
		}

		const dpApi = window.ArbeitszeitCheckDatepicker;
		if (dateInput && dpApi && typeof dpApi.initializeDatepicker === 'function') {
			dpApi.initializeDatepicker(dateInput, { openOnFocus: false });
		}

		if (btnToday && dateInput) {
			btnToday.addEventListener('click', () => {
				const api = window.ArbeitszeitCheckTime;
				if (api && api.todayYmd) {
					const parsed = api.parseYmd(api.todayYmd());
					if (parsed) {
						dateInput.value = api.formatDate(parsed);
						return;
					}
				}
				const d = new Date();
				dateInput.value = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
			});
		}

		function updateSummary() {
			if (!summaryWork && !summaryBreak) {
				return;
			}
			const dateIso = convertEuropeanToIso(dateInput ? dateInput.value.trim() : '');
			const sHm = startHidden ? startHidden.value : '';
			const eHm = endHidden ? endHidden.value : '';
			const { startMs, endMs } = workEndMs(dateIso, sHm, eHm);
			const workRangeOk = !Number.isNaN(startMs) && !Number.isNaN(endMs);
			let breakMs = 0;
			let breaksValid = true;
			collectBreaks(breaksContainer).forEach((b) => {
				const bStart = hmOnDateToMs(dateIso, b.start);
				let bEnd = hmOnDateToMs(dateIso, b.end);
				if (Number.isNaN(bStart) || Number.isNaN(bEnd)) {
					breaksValid = false;
					return;
				}
				if (bEnd <= bStart) {
					bEnd += 86400000;
				}
				breakMs += bEnd - bStart;
			});
			if (summaryWork) {
				summaryWork.textContent = workRangeOk && breaksValid
					? (Math.max(0, endMs - startMs - breakMs) / 3600000).toFixed(2)
					: '—';
			}
			if (summaryBreak) {
				summaryBreak.textContent = breaksValid
					? (breakMs / 3600000).toFixed(2)
					: '—';
			}
		}

		// ── Automatic statutory breaks (picker variant, employee-page parity) ──
		const autoToggle = root.querySelector('#' + p + '-auto-break-enabled');
		const autoState = root.querySelector('#' + p + '-auto-break-state');
		const autoPanel = root.querySelector('.auto-break-panel');
		const tiersIntro = root.querySelector('.' + p + '-break-tiers-intro');
		let userDismissedAutoBreak = false;
		let applyingAutoBreak = false;

		function breakTiers() {
			const raw = window.ArbeitszeitCheck?.complianceParams?.breakTiers;
			return (Array.isArray(raw) && raw.length > 0 ? raw : [
				{ afterHours: 6, breakMinutes: 30 },
				{ afterHours: 9, breakMinutes: 45 },
			]).slice().sort((a, b) => Number(b.afterHours) - Number(a.afterHours));
		}

		function requiredBreakMinutesFor(netWorkingHours) {
			const validation = window.ArbeitszeitCheckValidation;
			if (validation && typeof validation.calculateRequiredBreakMinutes === 'function') {
				return validation.calculateRequiredBreakMinutes(netWorkingHours);
			}
			for (const tier of breakTiers()) {
				if (netWorkingHours > Number(tier.afterHours)) {
					return Math.round(Number(tier.breakMinutes)) || 0;
				}
			}
			return 0;
		}

		if (tiersIntro) {
			const law = window.ArbeitszeitCheck?.complianceParams?.lawLabels?.breaks || '';
			const lines = breakTiers().slice().reverse().map((tier) => {
				const template = t('breakTierLine', 'From %1$s hours of work: at least %2$d minutes break (%3$s)');
				return template
					.replace('%1$s', String(Number(tier.afterHours)))
					.replace('%2$d', String(Math.round(Number(tier.breakMinutes))))
					.replace('%3$s', law);
			});
			lines.push(t('autoBreakIntroSuffix', 'Enable automatic breaks or add times yourself.'));
			tiersIntro.textContent = lines.join(' ');
			tiersIntro.hidden = false;
		}

		function manualBreakMinutes(dateIso) {
			const minBreakMs = currentMinBreakMinutes() * 60000;
			let total = 0;
			breaksContainer.querySelectorAll('.clock-form-break-row:not([data-auto-break])').forEach((row) => {
				const s = hmOnDateToMs(dateIso, row.querySelector('.clock-form-break-start-hidden')?.value || '');
				let e = hmOnDateToMs(dateIso, row.querySelector('.clock-form-break-end-hidden')?.value || '');
				if (Number.isNaN(s) || Number.isNaN(e)) {
					return;
				}
				if (e <= s) {
					e += 86400000;
				}
				const duration = e - s;
				if (duration >= minBreakMs) {
					total += duration / 60000;
				}
			});
			return total;
		}

		function removeAutoBreaks() {
			breaksContainer.querySelectorAll('.clock-form-break-row[data-auto-break]').forEach((row) => row.remove());
		}

		function markRowAuto(row) {
			row.setAttribute('data-auto-break', 'true');
			const grid = row.querySelector('.time-pair-matrix__grid--row');
			if (grid && !grid.querySelector('.auto-break-note')) {
				grid.classList.add('time-pair-matrix__grid--row--with-note');
				const note = document.createElement('p');
				note.className = 'form-help auto-break-note';
				note.setAttribute('role', 'status');
				const small = document.createElement('small');
				small.textContent = t('autoBreakNote', 'Automatically added for labour-law compliance.');
				note.appendChild(small);
				grid.appendChild(note);
			}
		}

		/**
		 * Mirror of the employee form's auto-break engine: statutory break
		 * minutes are computed from net working hours; a centered break row
		 * covers whatever manual breaks do not. Auto rows are rebuilt on
		 * every recompute so stale placements can never linger.
		 */
		function applyAutoBreak() {
			if (!autoToggle || !autoToggle.checked || userDismissedAutoBreak || applyingAutoBreak) {
				return;
			}
			applyingAutoBreak = true;
			try {
				const dateIso = convertEuropeanToIso(dateInput ? dateInput.value.trim() : '');
				const { startMs, endMs } = workEndMs(dateIso, startHidden ? startHidden.value : '', endHidden ? endHidden.value : '');
				if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
					removeAutoBreaks();
					return;
				}
				const grossHours = (endMs - startMs) / 3600000;
				const manualMinutes = manualBreakMinutes(dateIso);
				const validation = window.ArbeitszeitCheckValidation;
				const netHours = validation && typeof validation.netWorkingHours === 'function'
					? validation.netWorkingHours(grossHours, manualMinutes)
					: Math.max(0, grossHours - manualMinutes / 60);
				removeAutoBreaks();
				const shortfallMinutes = Math.max(0, requiredBreakMinutesFor(netHours) - manualMinutes);
				if (shortfallMinutes <= 0) {
					return;
				}
				const workMs = endMs - startMs;
				const breakMs = shortfallMinutes * 60000;
				let breakStartMs = startMs + Math.max(0, (workMs - breakMs) / 2);
				if (breakStartMs + breakMs > endMs) {
					breakStartMs = endMs - breakMs;
				}
				const toHm = (ms) => {
					const d = new Date(ms);
					return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
				};
				let row = null;
				breaksContainer.querySelectorAll('.clock-form-break-row:not([data-auto-break])').forEach((candidate) => {
					if (row) {
						return;
					}
					const s = candidate.querySelector('.clock-form-break-start-hidden')?.value || '';
					const e = candidate.querySelector('.clock-form-break-end-hidden')?.value || '';
					if (!s && !e) {
						row = candidate;
					}
				});
				if (!row) {
					row = createBreakRow(breaksContainer, breakIndex++, null, t, usePicker, p);
				}
				const breakData = { start: toHm(breakStartMs), end: toHm(breakStartMs + breakMs) };
				if (usePicker) {
					setPickerValue(row.querySelector('.clock-form-break-start-hidden'), strToHm(breakData.start));
					setPickerValue(row.querySelector('.clock-form-break-end-hidden'), strToHm(breakData.end));
				} else {
					row.querySelector('.clock-form-break-start-hidden').value = breakData.start;
					row.querySelector('.clock-form-break-end-hidden').value = breakData.end;
				}
				markRowAuto(row);
			} finally {
				applyingAutoBreak = false;
				updateSummary();
			}
		}

		function updateAutoToggleChrome() {
			if (!autoToggle) {
				return;
			}
			const isOn = autoToggle.checked;
			if (autoState) {
				autoState.textContent = isOn
					? t('autoBreakStateOn', 'Enabled')
					: t('autoBreakStateOff', 'Disabled');
				autoState.classList.toggle('auto-break-toggle__status--on', isOn);
				autoState.classList.toggle('auto-break-toggle__status--off', !isOn);
			}
			if (autoPanel) {
				autoPanel.classList.toggle('auto-break-panel--enabled', isOn);
				autoPanel.classList.toggle('auto-break-panel--disabled', !isOn);
			}
		}

		if (autoToggle) {
			userDismissedAutoBreak = !autoToggle.checked;
			autoToggle.addEventListener('change', () => {
				userDismissedAutoBreak = !autoToggle.checked;
				updateAutoToggleChrome();
				if (autoToggle.checked) {
					applyAutoBreak();
				} else {
					removeAutoBreaks();
					ensureVisibleBreakRow();
				}
				updateBreaksEmpty();
				updateSummary();
			});
			updateAutoToggleChrome();
		}

		let breakIndex = 0;
		const updateBreaksEmpty = () => {
			if (!breaksEmpty || !breaksContainer) {
				return;
			}
			breaksEmpty.hidden = breaksContainer.querySelectorAll('.clock-form-break-row').length > 0;
		};
		const ensureVisibleBreakRow = () => {
			// Picker variant keeps one editable row instead of an empty-state
			// box — unless auto-breaks own the rows right now.
			if (!usePicker || !breaksContainer || (autoToggle && autoToggle.checked)) {
				return;
			}
			if (!breaksContainer.querySelector('.clock-form-break-row')) {
				createBreakRow(breaksContainer, breakIndex++, {}, t, usePicker, p);
			}
		};
		breaksContainer._onBreaksChange = () => {
			updateBreaksEmpty();
			applyAutoBreak();
			ensureVisibleBreakRow();
			updateSummary();
		};

		normalizeBreaksFromSummary(initial.breaks).forEach((b) => {
			createBreakRow(breaksContainer, breakIndex++, b, t, usePicker, p);
		});
		ensureVisibleBreakRow();
		updateBreaksEmpty();
		applyAutoBreak();
		updateSummary();

		if (btnAddBreak) {
			btnAddBreak.addEventListener('click', () => {
				createBreakRow(breaksContainer, breakIndex++, null, t, usePicker, p);
				updateBreaksEmpty();
				applyAutoBreak();
				updateSummary();
			});
		}

		if (summaryWork || summaryBreak || autoToggle) {
			root.addEventListener('change', (ev) => {
				updateSummary();
				if (!autoToggle) {
					return;
				}
				// Only work-day inputs can change the statutory requirement,
				// and changes inside an auto-generated row must not retrigger
				// the engine — it would rebuild the row under the cursor.
				const el = ev.target;
				const timeField = el && el.id && (
					el.id === p + '-date' || el.id === p + '-start-time' || el.id === p + '-end-time'
				);
				const manualBreakField = el && el.classList && el.closest
					&& (el.classList.contains('clock-form-break-start-hidden') || el.classList.contains('clock-form-break-end-hidden'))
					&& !el.closest('.clock-form-break-row[data-auto-break]');
				if (timeField || manualBreakField) {
					applyAutoBreak();
				}
			});
			root.addEventListener('input', updateSummary);
		}

		function setStatus(message, isError) {
			if (!statusEl) {
				return;
			}
			statusEl.textContent = message || '';
			statusEl.classList.toggle('correction-dialog__status--error', !!isError);
			statusEl.classList.toggle('inline-message--error', !!isError);
			if (message) {
				statusEl.setAttribute('role', isError ? 'alert' : 'status');
			} else {
				statusEl.removeAttribute('role');
			}
		}

		function validateAndCollect() {
			const dateEuropean = dateInput ? dateInput.value.trim() : '';
			const dateIso = convertEuropeanToIso(dateEuropean);
			if (!dateIso) {
				return { ok: false, error: t('invalidDate', 'Please enter a valid date (dd.mm.yyyy).') };
			}
			const startHm = startHidden ? startHidden.value : '';
			const endHm = endHidden ? endHidden.value : '';
			if (!startHm || !endHm) {
				return { ok: false, error: t('invalidWorkTimes', 'Please enter valid start and end times.') };
			}
			const breaks = collectBreaks(breaksContainer);
			const breakCheck = validateBreaks(dateIso, startHm, endHm, breaks, t, currentMinBreakMinutes());
			if (!breakCheck.ok) {
				return breakCheck;
			}
			const reason = (root.querySelector('#' + p + '-reason') || {}).value || '';
			if (reason.trim().length < 10) {
				return { ok: false, error: t('reasonRequired', 'A reason of at least 10 characters is required.') };
			}
			const workLocation = workLocationSelect ? workLocationSelect.value : '';
			return {
				ok: true,
				payload: {
					date: dateEuropean,
					startTime: startHm,
					endTime: endHm,
					breaks,
					reason: reason.trim(),
					workLocation,
				},
			};
		}

		return { validateAndCollect, setStatus };
	}

	window.ArbeitszeitCheckClockForm = {
		resolveMinBreakMinutes,
		resolveMinBreakSeconds,
		/** @deprecated Prefer resolveMinBreakSeconds(); kept for older callers. */
		get MIN_BREAK_SECONDS() {
			return resolveMinBreakSeconds();
		},
		buildFormHtml,
		bindForm,
		validateBreaks,
	};
})();
