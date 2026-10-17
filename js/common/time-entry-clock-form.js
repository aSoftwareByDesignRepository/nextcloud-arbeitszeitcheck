/**
 * Shared date + HH:mm matrix and break editor for time-entry corrections.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

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
					error: t('breakTooShort', 'Each break must be at least ' + minutes + ' minutes.'),
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

	function createBreakRow(container, index, breakData, t) {
		const entry = document.createElement('div');
		entry.className = 'clock-form-break-row break-entry';
		const breakLabel = (t('breakNumber', 'Break {number}') || 'Break {number}').replace('{number}', String(index + 1));
		entry.innerHTML = [
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
		if (breakData && breakData.start && breakData.end) {
			const shParts = breakData.start.split(':');
			const ehParts = breakData.end.split(':');
			setSelectHm(sh, sm, { hour: shParts[0], minute: shParts[1] });
			setSelectHm(eh, em, { hour: ehParts[0], minute: ehParts[1] });
		}
		entry.querySelector('.clock-form-break-remove')?.addEventListener('click', () => {
			entry.remove();
			if (typeof container._onBreaksChange === 'function') {
				container._onBreaksChange();
			}
		});
		container.appendChild(entry);
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
			'<option value="' + code + '">' + (label || code) + '</option>';
		const wlOptionHtml = wlOptions !== null
			? wlOptions.map((it) => builtinWlOption(it.code, it.label)).join('')
			: builtinWlOption('office', L.workLocationOffice || 'Office')
				+ builtinWlOption('home', L.workLocationHome || 'Home office')
				+ builtinWlOption('external', L.workLocationExternal || 'External appointment');
		return [
			'<p class="form-help clock-form-intro">' + L.intro + '</p>',
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
			'<fieldset class="correction-fieldset correction-fieldset--breaks">',
			'<legend class="correction-dialog__section-title">' + L.breaksOptional + '</legend>',
			'<p class="form-help">' + L.breaksHelp + '</p>',
			'<p class="correction-breaks-empty ' + p + '-breaks-empty">' + L.breaksEmpty + '</p>',
			'<div class="time-pair-matrix time-pair-matrix--breaks" role="group" aria-label="' + L.breaksOptional + '">',
			'<div class="time-pair-matrix__grid time-pair-matrix__grid--header">',
			'<span class="time-pair-matrix__colhead">' + L.startTime + '</span>',
			'<span class="time-pair-matrix__colhead">' + L.endTime + '</span>',
			'<span class="time-pair-matrix__colhead time-pair-matrix__colhead--action">' + L.actions + '</span>',
			'</div>',
			'<div id="' + p + '-breaks-container" class="clock-form-breaks-container"></div>',
			'</div>',
			'<button type="button" class="btn btn--secondary btn--sm ' + p + '-add-break">' + L.addBreak + '</button>',
			'</fieldset>',
			L.workLocationLegend ? [
				'<fieldset class="correction-fieldset correction-fieldset--work-location">',
				'<legend class="correction-dialog__section-title">' + L.workLocationLegend + '</legend>',
				'<div class="form-group">',
				'<label for="' + p + '-work-location" class="form-label">' + L.workLocationLegend + '</label>',
				'<select id="' + p + '-work-location" class="form-input">',
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
			'<div class="form-group">',
			'<label for="' + p + '-reason" class="form-label">' + L.reason + ' <span class="form-required" aria-hidden="true">*</span></label>',
			'<textarea id="' + p + '-reason" class="form-textarea" rows="3" minlength="10" maxlength="2000" required aria-required="true"></textarea>',
			'<p class="form-help">' + L.reasonHelp + '</p>',
			'</div>',
			'<div class="' + p + '-status correction-dialog__status" aria-live="polite"></div>',
		].join('');
	}

	function bindForm(root, idPrefix, initial, t, options) {
		const p = idPrefix;
		const opts = options && typeof options === 'object' ? options : {};
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

		addTimeOptions(startHour, startMinute, true);
		addTimeOptions(endHour, endMinute, true);
		bindTimeInputs(startHour, startMinute, startHidden);
		bindTimeInputs(endHour, endMinute, endHidden);

		if (dateInput && initial.startTime) {
			dateInput.value = isoToEuropeanDate(initial.startTime);
		}
		setSelectHm(startHour, startMinute, isoToHm(initial.startTime));
		setSelectHm(endHour, endMinute, isoToHm(initial.endTime));
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

		let breakIndex = 0;
		const updateBreaksEmpty = () => {
			if (!breaksEmpty || !breaksContainer) {
				return;
			}
			breaksEmpty.hidden = breaksContainer.querySelectorAll('.clock-form-break-row').length > 0;
		};
		breaksContainer._onBreaksChange = updateBreaksEmpty;

		normalizeBreaksFromSummary(initial.breaks).forEach((b) => {
			createBreakRow(breaksContainer, breakIndex++, b, t);
		});
		updateBreaksEmpty();

		if (btnAddBreak) {
			btnAddBreak.addEventListener('click', () => {
				createBreakRow(breaksContainer, breakIndex++, null, t);
				updateBreaksEmpty();
			});
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
