(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};
	const Components = window.ArbeitszeitCheckComponents || {};
	const cfg = window.ArbeitszeitCheck || {};
	const MODAL_ID = 'time-entry-correction-modal';
	const MIN_JUSTIFICATION_LENGTH = 10;

	function resolveMinBreakSeconds(overrideMinutes) {
		const ClockForm = window.ArbeitszeitCheckClockForm;
		if (ClockForm && typeof ClockForm.resolveMinBreakSeconds === 'function') {
			return ClockForm.resolveMinBreakSeconds(overrideMinutes);
		}
		const Validation = window.ArbeitszeitCheckValidation;
		if (Validation && typeof Validation.getMinBreakMinutes === 'function' && overrideMinutes === undefined) {
			return Validation.getMinBreakMinutes() * 60;
		}
		const fromOverride = Number(overrideMinutes);
		if (Number.isFinite(fromOverride) && fromOverride > 0) {
			return Math.round(fromOverride) * 60;
		}
		const raw = Number(cfg.complianceParams?.minBreakMinutes);
		return (Number.isFinite(raw) && raw > 0 ? Math.round(raw) : 15) * 60;
	}

	let correctionFormTemplate = null;
	let wizardBound = false;
	let wizardState = null;
	let breakIndex = 0;

	function t(key, fallback) {
		const bundle = cfg.l10n || {};
		const value = bundle[key];
		return value !== undefined && value !== '' ? value : (fallback || key);
	}

	/** Plural-aware translate (Nextcloud window.n replaces %n). */
	function n(singular, plural, count, vars) {
		const c = Number.isFinite(Number(count)) ? Number(count) : 0;
		if (typeof window.n === 'function') {
			return window.n('arbeitszeitcheck', singular, plural, c, vars);
		}
		let out = String(c === 1 ? singular : plural).replace('%n', String(c));
		if (vars && typeof vars === 'object') {
			out = out.replace(/\{(\w+)\}/g, (_, name) => (
				Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : '{' + name + '}'
			));
		}
		return out;
	}

	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	function formatIsoDisplay(iso, mode) {
		if (!iso) {
			return '–';
		}
		const api = window.ArbeitszeitCheckTime;
		if (api) {
			if (mode === 'date') {
				return api.formatDate(iso) || '–';
			}
			if (mode === 'datetime') {
				const d = api.formatDate(iso);
				const tm = api.formatTime(iso);
				return d && tm ? d + ' ' + tm : (tm || d || '–');
			}
			return api.formatTime(iso) || '–';
		}
		return String(iso).slice(0, 16).replace('T', ' ');
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

	function hmOnDateToMs(dateIso, hm) {
		if (!dateIso || !hm || !/^\d{2}:\d{2}$/.test(hm)) {
			return NaN;
		}
		let ms = new Date(dateIso + 'T' + hm + ':00').getTime();
		if (Number.isNaN(ms)) {
			return NaN;
		}
		return ms;
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
		const stepRaw = parseInt(
			(window.ArbeitszeitCheck && window.ArbeitszeitCheck.timeEntryForm && window.ArbeitszeitCheck.timeEntryForm.minuteStep)
				|| (window.ArbeitszeitCheck && window.ArbeitszeitCheck.correctionMinuteStep)
				|| 5,
			10
		);
		const step = (!isNaN(stepRaw) && stepRaw >= 1 && stepRaw <= 30) ? stepRaw : 5;
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
		for (let m = 0; m < 60; m += step) {
			const opt = document.createElement('option');
			opt.value = String(m).padStart(2, '0');
			opt.textContent = opt.value;
			minuteSelect.appendChild(opt);
		}
	}

	function ensureMinuteOption(minuteSelect, minutePadded) {
		if (!minuteSelect || !minutePadded) {
			return;
		}
		const exists = Array.from(minuteSelect.options).some((o) => o.value === minutePadded);
		if (exists) {
			return;
		}
		const opt = document.createElement('option');
		opt.value = minutePadded;
		opt.textContent = minutePadded;
		minuteSelect.appendChild(opt);
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
		if (hm && hm.hour) {
			hourSelect.value = hm.hour;
		}
		if (hm && hm.minute) {
			ensureMinuteOption(minuteSelect, hm.minute);
			minuteSelect.value = hm.minute;
		}
		hourSelect.dispatchEvent(new Event('change'));
	}

	function formatBreaksList(breaks) {
		if (!Array.isArray(breaks) || breaks.length === 0) {
			return '–';
		}
		return breaks.map((b) => {
			const s = formatIsoDisplay(b.start || b.startTime, 'time');
			const e = formatIsoDisplay(b.end || b.endTime, 'time');
			return s + ' – ' + e;
		}).join('; ');
	}

	function normalizeBreaksFromSummary(breaks) {
		if (!Array.isArray(breaks)) {
			return [];
		}
		return breaks.map((b) => {
			const start = isoToHm(b.start || b.startTime);
			const end = isoToHm(b.end || b.endTime);
			if (!start.hour || !end.hour) {
				return null;
			}
			return { start: start.hour + ':' + start.minute, end: end.hour + ':' + end.minute };
		}).filter(Boolean).sort((a, b) => a.start.localeCompare(b.start));
	}

	function breaksPayloadEqual(a, b) {
		const norm = (list) => JSON.stringify(
			(list || []).map((row) => ({ start: row.start, end: row.end })).sort((x, y) => x.start.localeCompare(y.start))
		);
		return norm(a) === norm(b);
	}

	function getModalRoot() {
		return document.getElementById(MODAL_ID);
	}

	function cacheCorrectionTemplate() {
		if (correctionFormTemplate) {
			return true;
		}
		const source = document.getElementById('time-entry-correction-source');
		if (!source) {
			return false;
		}
		correctionFormTemplate = source.innerHTML;
		source.remove();
		return true;
	}

	function ensureCorrectionModal() {
		const existing = getModalRoot();
		if (existing) {
			return existing;
		}
		if (!Components.createModal || !cacheCorrectionTemplate()) {
			return null;
		}

		const modal = Components.createModal({
			id: MODAL_ID,
			title: t('correctionModalTitle'),
			content: correctionFormTemplate,
			size: 'xl',
		});

		const footer = modal.querySelector('.correction-dialog__footer');
		if (footer) {
			modal.appendChild(footer);
		}
		modal.setAttribute('aria-describedby', 'correction-dialog-desc');

		const closeBtn = modal.querySelector('.modal-close');
		if (closeBtn) {
			closeBtn.addEventListener('click', () => {
				resetWizardState(modal);
			});
		}

		if (wizardBound) {
			wizardBound = false;
			wizardState = null;
		}
		bindWizard(modal);

		return modal;
	}

	function setStatus(el, message, isError) {
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.classList.toggle('correction-dialog__status--error', !!isError);
		el.classList.toggle('inline-message--error', !!isError);
		if (message) {
			el.setAttribute('role', isError ? 'alert' : 'status');
		} else {
			el.removeAttribute('role');
		}
	}

	/**
	 * Human-readable break-policy warning for the correction dialog —
	 * the save succeeded; the notice explains what is still missing.
	 */
	function formatBreakWarning(warning) {
		const parts = [];
		if (warning && warning.window && warning.window.start && warning.window.end) {
			const w = warning.window;
			const missing = Math.max(0, (w.required_minutes || 0) - (w.covered_minutes || 0));
			parts.push(
				(window.t ? window.t('arbeitszeitcheck', 'The required break window {s}–{e} is missing {m} min.', {s: w.start, e: w.end, m: missing}) : null)
				|| `The required break window ${w.start}–${w.end} is missing ${missing} min.`
			);
		}
		if (warning && typeof warning.required === 'number' && typeof warning.actual === 'number' && warning.required > warning.actual) {
			parts.push(
				(window.t ? window.t('arbeitszeitcheck', 'Required break: {r} min — recorded: {a} min.', {r: warning.required, a: warning.actual}) : null)
				|| `Required break: ${warning.required} min — recorded: ${warning.actual} min.`
			);
		}
		if (parts.length === 0) {
			parts.push(t('correctionBreakWarning', 'The saved times do not fully meet the break rules.'));
		}
		return parts.join(' ');
	}

	function parseEntrySummary(btn) {
		const raw = btn.getAttribute('data-entry-summary');
		const parsed = Utils.parseAttributeJson ? Utils.parseAttributeJson(raw) : null;
		if (Utils.isTimeEntryClockSummary && Utils.isTimeEntryClockSummary(parsed)) {
			return parsed;
		}
		return null;
	}

	function resetWizardState(modal) {
		const root = modal || getModalRoot();
		if (!root || !wizardState) {
			return;
		}
		const btnSubmit = root.querySelector('#correction-wizard-submit');
		if (btnSubmit) {
			btnSubmit.disabled = false;
			btnSubmit.removeAttribute('aria-busy');
		}
		if (wizardState.resetForm) {
			wizardState.resetForm();
		}
		setStatus(wizardState.statusEl, '', false);
		wizardState.originalSummary = null;
	}

	function initDatepicker(modal) {
		const dateInput = modal.querySelector('#correction-date');
		const dpApi = window.ArbeitszeitCheckDatepicker;
		if (!dateInput || !dpApi || typeof dpApi.initializeDatepicker !== 'function') {
			return null;
		}
		if (dateInput.dataset.datepickerInit === '1' || dateInput.dataset.datepickerInit === 'true') {
			return null;
		}
		return dpApi.initializeDatepicker(dateInput, { openOnFocus: false });
	}

	function bindWizard(modal) {
		if (wizardBound || !modal) {
			return;
		}
		wizardBound = true;

		const form = modal.querySelector('#time-entry-correction-form');
		const btnCancel = modal.querySelector('#correction-dialog-cancel');
		const btnSubmit = modal.querySelector('#correction-wizard-submit');
		const statusEl = modal.querySelector('#correction-dialog-status');
		const currentSummary = modal.querySelector('#correction-current-summary');
		const dateInput = modal.querySelector('#correction-date');
		const btnToday = modal.querySelector('#correction-date-today');
		const startHour = modal.querySelector('#correction-start-hour');
		const startMinute = modal.querySelector('#correction-start-minute');
		const startHidden = modal.querySelector('#correction-start-time');
		const endHour = modal.querySelector('#correction-end-hour');
		const endMinute = modal.querySelector('#correction-end-minute');
		const endHidden = modal.querySelector('#correction-end-time');
		const breaksContainer = modal.querySelector('#correction-breaks-container');
		const breaksEmptyHint = modal.querySelector('#correction-breaks-empty');
		const btnAddBreak = modal.querySelector('#correction-add-break');
		const justification = modal.querySelector('#correction-justification');
		const justificationCount = modal.querySelector('#correction-justification-count');
		const workLocationSelect = modal.querySelector('#correction-work-location');
		const entryIdInput = modal.querySelector('#correction-entry-id');

		addTimeOptions(startHour, startMinute, false);
		addTimeOptions(endHour, endMinute, false);
		bindTimeInputs(startHour, startMinute, startHidden);
		bindTimeInputs(endHour, endMinute, endHidden);

		const api = cfg.apiUrl || {};

		function updateJustificationCount() {
			if (!justificationCount || !justification) {
				return;
			}
			const len = justification.value.trim().length;
			const remaining = Math.max(0, MIN_JUSTIFICATION_LENGTH - len);
			if (len >= MIN_JUSTIFICATION_LENGTH) {
				justificationCount.textContent = t('correctionJustificationReady').replace('{count}', String(len));
				justificationCount.classList.remove('correction-dialog__char-count--warn');
				justificationCount.classList.add('correction-dialog__char-count--ok');
			} else {
				justificationCount.textContent = n(
					'1 more character needed ({count} of {min})',
					'%n more characters needed ({count} of {min})',
					remaining,
					{ count: len, min: MIN_JUSTIFICATION_LENGTH }
				);
				justificationCount.classList.add('correction-dialog__char-count--warn');
				justificationCount.classList.remove('correction-dialog__char-count--ok');
			}
		}

		function updateBreaksEmptyState() {
			if (!breaksContainer || !breaksEmptyHint) {
				return;
			}
			const hasRows = breaksContainer.querySelectorAll('.break-entry').length > 0;
			breaksEmptyHint.hidden = hasRows;
		}

		function formatWorkLocationLabel(raw) {
			const s = typeof raw === 'string' ? raw : '';
			const builtin = {
				home: t('workLocationHome', 'Home office'),
				external: t('workLocationExternal', 'External appointment'),
				office: t('workLocationOffice', 'Office'),
			};
			if (s === '') {
				return t('workLocationUnspecified', 'Not specified');
			}
			const catalog = window.ArbeitszeitCheckWorkLocationCatalog;
			// Catalog label wins; built-in translation next; raw code last —
			// unknown codes never render blank.
			return catalog ? catalog.labelFor(s, builtin[s] || null) : (builtin[s] || s);
		}

		function fillCurrentSummary(summary) {
			if (!currentSummary) {
				return;
			}
			const rows = [
				[t('correctionLabelDate'), formatIsoDisplay(summary.startTime, 'date')],
				[t('correctionLabelStart'), formatIsoDisplay(summary.startTime, 'time')],
				[t('correctionLabelEnd'), formatIsoDisplay(summary.endTime, 'time')],
				[t('correctionLabelBreaks'), formatBreaksList(summary.breaks)],
				[t('correctionLabelWorkLocation'), formatWorkLocationLabel(summary.workLocation)],
				[t('correctionLabelDescription'), summary.description || '–'],
			];
			currentSummary.innerHTML = rows.map(([label, val]) => {
				return '<tr><th scope="row">' + escapeHtml(label) + '</th><td>' + escapeHtml(val) + '</td></tr>';
			}).join('');
		}

		function createBreakRow(breakData, index) {
			const entry = document.createElement('div');
			entry.className = 'break-entry';
			entry.setAttribute('data-break-index', String(index));
			const breakLabel = t('correctionBreakNumber').replace('{number}', String(index + 1));
			entry.innerHTML = [
				'<div class="time-pair-matrix__grid time-pair-matrix__grid--row">',
				'<div class="form-group">',
				'<label class="form-label" id="correction-break-' + index + '-start-label">',
				escapeHtml(breakLabel) + ' — ' + escapeHtml(t('correctionLabelStart')),
				'</label>',
				'<div class="time-input-group correction-break-start-group" role="group" aria-labelledby="correction-break-' + index + '-start-label">',
				'<select class="form-input time-hour correction-break-start-hour" data-break-index="' + index + '" aria-label="' + escapeHtml(t('correctionBreakStartHour')) + '"></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select class="form-input time-minute correction-break-start-minute" data-break-index="' + index + '" aria-label="' + escapeHtml(t('correctionBreakStartMinute')) + '"></select>',
				'<input type="hidden" class="correction-break-start-hidden" data-break-index="' + index + '" value="">',
				'</div>',
				'</div>',
				'<div class="form-group">',
				'<label class="form-label" id="correction-break-' + index + '-end-label">',
				escapeHtml(breakLabel) + ' — ' + escapeHtml(t('correctionLabelEnd')),
				'</label>',
				'<div class="time-input-group correction-break-end-group" role="group" aria-labelledby="correction-break-' + index + '-end-label">',
				'<select class="form-input time-hour correction-break-end-hour" data-break-index="' + index + '" aria-label="' + escapeHtml(t('correctionBreakEndHour')) + '"></select>',
				'<span class="time-separator" aria-hidden="true">:</span>',
				'<select class="form-input time-minute correction-break-end-minute" data-break-index="' + index + '" aria-label="' + escapeHtml(t('correctionBreakEndMinute')) + '"></select>',
				'<input type="hidden" class="correction-break-end-hidden" data-break-index="' + index + '" value="">',
				'</div>',
				'</div>',
				'<div class="time-pair-matrix__action">',
				'<button type="button" class="btn btn--sm btn--danger correction-break-remove" data-break-index="' + index + '" aria-label="' + escapeHtml(t('correctionRemoveBreak')) + '">',
				escapeHtml(t('correctionRemove')),
				'</button>',
				'</div>',
				'</div>',
			].join('');

			const sh = entry.querySelector('.correction-break-start-hour');
			const sm = entry.querySelector('.correction-break-start-minute');
			const eh = entry.querySelector('.correction-break-end-hour');
			const em = entry.querySelector('.correction-break-end-minute');
			const shHidden = entry.querySelector('.correction-break-start-hidden');
			const ehHidden = entry.querySelector('.correction-break-end-hidden');
			addTimeOptions(sh, sm, true);
			addTimeOptions(eh, em, true);
			bindTimeInputs(sh, sm, shHidden);
			bindTimeInputs(eh, em, ehHidden);
			const startHm = isoToHm(breakData && (breakData.start || breakData.startTime));
			const endHm = isoToHm(breakData && (breakData.end || breakData.endTime));
			setSelectHm(sh, sm, startHm);
			setSelectHm(eh, em, endHm);
			return entry;
		}

		function renderBreaks(breaks) {
			if (!breaksContainer) {
				return;
			}
			breaksContainer.innerHTML = '';
			breakIndex = 0;
			const rows = Array.isArray(breaks) && breaks.length > 0 ? breaks : [];
			rows.forEach((b) => {
				breaksContainer.appendChild(createBreakRow(b, breakIndex));
				breakIndex += 1;
			});
			updateBreaksEmptyState();
		}

		function collectBreaks() {
			if (!breaksContainer) {
				return [];
			}
			const breaks = [];
			breaksContainer.querySelectorAll('.break-entry').forEach((entry) => {
				const startHidden = entry.querySelector('.correction-break-start-hidden');
				const endHidden = entry.querySelector('.correction-break-end-hidden');
				const start = startHidden ? startHidden.value : '';
				const end = endHidden ? endHidden.value : '';
				if (start && end && /^\d{2}:\d{2}$/.test(start) && /^\d{2}:\d{2}$/.test(end)) {
					breaks.push({ start: start, end: end });
				}
			});
			return breaks;
		}

		function collectPartialBreakRows() {
			if (!breaksContainer) {
				return 0;
			}
			let partial = 0;
			breaksContainer.querySelectorAll('.break-entry').forEach((entry) => {
				const sh = entry.querySelector('.correction-break-start-hour');
				const sm = entry.querySelector('.correction-break-start-minute');
				const eh = entry.querySelector('.correction-break-end-hour');
				const em = entry.querySelector('.correction-break-end-minute');
				const hasStart = sh && sh.value && sm && sm.value;
				const hasEnd = eh && eh.value && em && em.value;
				if ((hasStart && !hasEnd) || (!hasStart && hasEnd)) {
					partial += 1;
				}
			});
			return partial;
		}

		function updateHiddenTimes() {
			if (startHour && startMinute && startHidden) {
				startHidden.value = (startHour.value && startMinute.value) ? startHour.value + ':' + startMinute.value : '';
			}
			if (endHour && endMinute && endHidden) {
				endHidden.value = (endHour.value && endMinute.value) ? endHour.value + ':' + endMinute.value : '';
			}
			breaksContainer && breaksContainer.querySelectorAll('.break-entry').forEach((entry) => {
				const sh = entry.querySelector('.correction-break-start-hour');
				const sm = entry.querySelector('.correction-break-start-minute');
				const eh = entry.querySelector('.correction-break-end-hour');
				const em = entry.querySelector('.correction-break-end-minute');
				const shH = entry.querySelector('.correction-break-start-hidden');
				const ehH = entry.querySelector('.correction-break-end-hidden');
				if (sh && sm && shH) {
					shH.value = (sh.value && sm.value) ? sh.value + ':' + sm.value : '';
				}
				if (eh && em && ehH) {
					ehH.value = (eh.value && em.value) ? eh.value + ':' + em.value : '';
				}
			});
		}

		function validateBreaks(dateIso, startHm, endHm) {
			const { startMs, endMs } = workEndMs(dateIso, startHm, endHm);
			if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
				return t('correctionErrorValidTimes');
			}

			const partial = collectPartialBreakRows();
			if (partial > 0) {
				return t('correctionErrorPartialBreak');
			}

			const breaks = collectBreaks();
			if (breaks.length === 0) {
				return null;
			}

			const intervals = [];
			for (let i = 0; i < breaks.length; i++) {
				const b = breaks[i];
				let bStart = hmOnDateToMs(dateIso, b.start);
				let bEnd = hmOnDateToMs(dateIso, b.end);
				if (Number.isNaN(bStart) || Number.isNaN(bEnd)) {
					return t('correctionErrorValidBreakTimes');
				}
				if (bEnd <= bStart) {
					bEnd += 86400000;
				}
				const durationSec = (bEnd - bStart) / 1000;
				const minBreakSeconds = resolveMinBreakSeconds(
					wizardState && wizardState.minBreakMinutes !== undefined
						? wizardState.minBreakMinutes
						: undefined
				);
				if (durationSec < minBreakSeconds) {
					return t('correctionErrorBreakMinDuration');
				}
				if (bStart < startMs || bEnd > endMs) {
					return t('correctionErrorBreakWithinWork');
				}
				intervals.push({ start: bStart, end: bEnd, index: i });
			}

			intervals.sort((a, b) => a.start - b.start);
			for (let j = 1; j < intervals.length; j++) {
				if (intervals[j].start < intervals[j - 1].end) {
					return t('correctionErrorBreakOverlap');
				}
			}

			const Validation = window.ArbeitszeitCheckValidation || window.ArbeitszeitCheck?.Validation;
			if (Validation && typeof Validation.evaluateBreakCompliance === 'function') {
				const portions = intervals.map((iv) => Math.round((iv.end - iv.start) / 60000));
				const netWorkingHours = Math.max(0, (endMs - startMs) / 3600000
					- portions.reduce((s, m) => s + m, 0) / 60);
				const evalResult = Validation.evaluateBreakCompliance(portions, netWorkingHours);
				if (!evalResult.ok) {
					return evalResult.splitInvalid
						? t('correctionErrorBreakSplitInvalid')
						: (evalResult.message || t('correctionErrorBreakSplitInvalid'));
				}
			}
			return null;
		}

		function hasProposedChanges(summary) {
			if (!summary) {
				return true;
			}
			const dateIso = convertEuropeanToIso(dateInput ? dateInput.value.trim() : '');
			const origDateIso = convertEuropeanToIso(isoToEuropeanDate(summary.startTime));
			const startHm = startHidden ? startHidden.value : '';
			const endHm = endHidden ? endHidden.value : '';
			const origStart = isoToHm(summary.startTime);
			const origEnd = isoToHm(summary.endTime);
			const origStartHm = origStart.hour ? origStart.hour + ':' + origStart.minute : '';
			const origEndHm = origEnd.hour ? origEnd.hour + ':' + origEnd.minute : '';

			const workChanged = dateIso !== origDateIso || startHm !== origStartHm || endHm !== origEndHm;
			const proposedBreaks = collectBreaks();
			const originalBreaks = normalizeBreaksFromSummary(summary.breaks);
			const breaksChanged = proposedBreaks.length > 0 && !breaksPayloadEqual(proposedBreaks, originalBreaks);
			// '__clear' proposes removing the mark; '' means keep current;
			// NULL/legacy originals stay null so picking "office" on an
			// unmarked legacy entry still registers as a real change.
			const proposedLocation = workLocationSelect ? workLocationSelect.value : '';
			const origLocation = typeof summary.workLocation === 'string' && summary.workLocation !== ''
				? summary.workLocation
				: null;
			const locationChanged = proposedLocation === '__clear'
				? origLocation !== null
				: (proposedLocation !== '' && proposedLocation !== origLocation);

			return workChanged || breaksChanged || locationChanged;
		}

		function validateForm() {
			updateHiddenTimes();
			const dateIso = convertEuropeanToIso(dateInput ? dateInput.value.trim() : '');
			const startHm = startHidden ? startHidden.value : '';
			const endHm = endHidden ? endHidden.value : '';

			if (!dateIso) {
				setStatus(statusEl, t('correctionErrorValidDate'), true);
				if (dateInput) {
					dateInput.focus();
				}
				return false;
			}
			if (!startHm || !endHm) {
				setStatus(statusEl, t('correctionErrorStartEndRequired'), true);
				if (!startHm && startHour) {
					startHour.focus();
				} else if (endHour) {
					endHour.focus();
				}
				return false;
			}

			const { startMs, endMs } = workEndMs(dateIso, startHm, endHm);
			if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
				setStatus(statusEl, t('correctionErrorValidTimes'), true);
				return false;
			}
			if (endMs <= startMs) {
				setStatus(statusEl, t('correctionErrorEndAfterStart'), true);
				return false;
			}

			const breakError = validateBreaks(dateIso, startHm, endHm);
			if (breakError) {
				setStatus(statusEl, breakError, true);
				const firstBreak = breaksContainer && breaksContainer.querySelector('.correction-break-start-hour');
				if (firstBreak) {
					firstBreak.focus();
				}
				return false;
			}

			if (!hasProposedChanges(wizardState && wizardState.originalSummary)) {
				setStatus(statusEl, t('correctionErrorNoChanges'), true);
				if (dateInput) {
					dateInput.focus();
				}
				return false;
			}

			const text = justification ? justification.value.trim() : '';
			if (text.length < MIN_JUSTIFICATION_LENGTH) {
				setStatus(statusEl, t('correctionErrorJustificationMin'), true);
				if (justification) {
					justification.focus();
				}
				return false;
			}

			setStatus(statusEl, '', false);
			return true;
		}

		function resetFormFields() {
			if (dateInput) {
				dateInput.value = '';
			}
			if (justification) {
				justification.value = '';
			}
			updateJustificationCount();
			setSelectHm(startHour, startMinute, { hour: '09', minute: '00' });
			setSelectHm(endHour, endMinute, { hour: '17', minute: '00' });
			if (workLocationSelect) {
				workLocationSelect.value = '';
			}
			if (breaksContainer) {
				breaksContainer.innerHTML = '';
			}
			breakIndex = 0;
			updateBreaksEmptyState();
		}

		wizardState = {
			statusEl,
			originalSummary: null,
			minBreakMinutes: undefined,
			resetForm: resetFormFields,
			openWizard(summary) {
				const modalEl = ensureCorrectionModal();
				if (!modalEl) {
					Messaging.showError(t('correctionErrorOpenDialog'));
					return;
				}

				wizardState.originalSummary = summary;
				wizardState.minBreakMinutes = summary && summary.minBreakMinutes !== undefined
					? summary.minBreakMinutes
					: undefined;

				if (entryIdInput) {
					entryIdInput.value = String(summary.id || '');
				}
				fillCurrentSummary(summary);
				if (dateInput) {
					dateInput.value = isoToEuropeanDate(summary.startTime);
				}
				const datePicker = initDatepicker(modalEl);
				setSelectHm(startHour, startMinute, isoToHm(summary.startTime));
				setSelectHm(endHour, endMinute, isoToHm(summary.endTime));
				renderBreaks(summary.breaks);
				if (justification) {
					justification.value = '';
				}
				updateJustificationCount();
				setStatus(statusEl, '', false);

				Components.openModal(MODAL_ID);
				if (datePicker && typeof datePicker.close === 'function') {
					datePicker.close();
				}
			},
		};

		if (btnToday && dateInput) {
			btnToday.addEventListener('click', () => {
				const timeApi = window.ArbeitszeitCheckTime;
				if (timeApi && typeof timeApi.todayYmd === 'function') {
					const ymd = timeApi.todayYmd();
					const dp = window.ArbeitszeitCheckDatepicker;
					dateInput.value = dp && dp.convertISOToEuropean ? dp.convertISOToEuropean(ymd) : ymd;
				} else {
					const now = new Date();
					const pad = (n) => String(n).padStart(2, '0');
					dateInput.value = pad(now.getDate()) + '.' + pad(now.getMonth() + 1) + '.' + now.getFullYear();
				}
			});
		}

		if (justification) {
			justification.addEventListener('input', updateJustificationCount);
			updateJustificationCount();
		}

		if (btnCancel) {
			btnCancel.addEventListener('click', () => {
				Components.closeModal(modal);
				resetWizardState(modal);
			});
		}

		if (btnAddBreak && breaksContainer) {
			btnAddBreak.addEventListener('click', () => {
				breaksContainer.appendChild(createBreakRow({}, breakIndex));
				breakIndex += 1;
				updateBreaksEmptyState();
				const lastRow = breaksContainer.querySelector('.break-entry:last-child .correction-break-start-hour');
				if (lastRow) {
					lastRow.focus();
				}
			});
			breaksContainer.addEventListener('click', (e) => {
				const target = e.target;
				if (target && target.classList.contains('correction-break-remove')) {
					const item = target.closest('.break-entry');
					if (item) {
						item.remove();
						updateBreaksEmptyState();
					}
				}
			});
		}

		function submitCorrectionRequest(e) {
			e.preventDefault();
			if (!validateForm()) {
				return;
			}
			const id = entryIdInput ? entryIdInput.value : '';
			const url = (api.requestCorrection || '').replace('__ID__', encodeURIComponent(id));
			const body = {
				justification: justification ? justification.value.trim() : '',
				date: convertEuropeanToIso(dateInput ? dateInput.value.trim() : ''),
				startTime: startHidden ? startHidden.value : '',
				endTime: endHidden ? endHidden.value : '',
			};
			const proposedLocation = workLocationSelect ? workLocationSelect.value : '';
			const origLocation = wizardState.originalSummary
				&& typeof wizardState.originalSummary.workLocation === 'string'
				&& wizardState.originalSummary.workLocation !== ''
				? wizardState.originalSummary.workLocation
				: null;
			if (proposedLocation === '__clear') {
				if (origLocation !== null) {
					body.workLocation = null;
				}
			} else if (proposedLocation !== '' && proposedLocation !== origLocation) {
				body.workLocation = proposedLocation;
			}
			const breaks = collectBreaks();
			const originalBreaks = wizardState.originalSummary
				? normalizeBreaksFromSummary(wizardState.originalSummary.breaks)
				: [];
			if (breaks.length > 0 && !breaksPayloadEqual(breaks, originalBreaks)) {
				body.breaks = breaks;
			}
			if (btnSubmit) {
				btnSubmit.disabled = true;
				btnSubmit.setAttribute('aria-busy', 'true');
			}
			setStatus(statusEl, t('correctionSubmitting'), false);

			Utils.ajax(url, {
				method: 'POST',
				data: body,
				onSuccess: (data) => {
					if (data.success) {
						const postSaveWarnings = [];
						if (data.break_warning) {
							postSaveWarnings.push(formatBreakWarning(data.break_warning));
						}
						if (typeof data.billing_warning === 'string' && data.billing_warning !== '') {
							postSaveWarnings.push(data.billing_warning);
						}
						if (postSaveWarnings.length > 0) {
							// Saved with a warning: keep the dialog open,
							// announce inline (role=status), reload on close so the
							// list reflects the new state. Never a dead end.
							correctionReloadOnClose = true;
							setStatus(statusEl, t('correctionSubmitSuccess') + ' ' + postSaveWarnings.join(' '), false);
							Messaging.showSuccess(t('correctionSubmitSuccess'));
							if (btnSubmit) {
								btnSubmit.disabled = true;
								btnSubmit.removeAttribute('aria-busy');
							}
							if (btnCancel) {
								btnCancel.textContent = t('correctionClose', 'Close');
								btnCancel.focus();
							}
							return;
						}
						Components.closeModal(modal);
						resetWizardState(modal);
						Messaging.showSuccess(t('correctionSubmitSuccess'));
						window.location.reload();
					} else {
						const errMsg = data.error || t('correctionSubmitError');
						setStatus(statusEl, errMsg, true);
						Messaging.showError(errMsg);
						if (btnSubmit) {
							btnSubmit.disabled = false;
							btnSubmit.removeAttribute('aria-busy');
						}
					}
				},
				onError: (err) => {
					const errMsg = err?.error || t('correctionSubmitError');
					setStatus(statusEl, errMsg, true);
					Messaging.showError(errMsg);
					if (btnSubmit) {
						btnSubmit.disabled = false;
						btnSubmit.removeAttribute('aria-busy');
					}
				},
			});
		}

		if (form) {
			form.addEventListener('submit', submitCorrectionRequest);
		}
	}

	let correctionReloadOnClose = false;

	function init() {
		if (window.ArbeitszeitCheckWorkLocationCatalog) {
			window.ArbeitszeitCheckWorkLocationCatalog.prefetch();
		}
		cacheCorrectionTemplate();

		window.addEventListener('modal-close', (e) => {
			if (!e.detail || e.detail.modalId !== MODAL_ID) {
				return;
			}
			wizardBound = false;
			wizardState = null;
			if (correctionReloadOnClose) {
				correctionReloadOnClose = false;
				window.location.reload();
			}
		});

		document.querySelectorAll('.btn-request-correction').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const summary = parseEntrySummary(btn);
				if (!summary) {
					Messaging.showError(t('correctionErrorLoadEntry', 'Could not load the stored times for this entry. Please reload the page and try again.'));
					return;
				}
				const m = ensureCorrectionModal();
				if (!m || !wizardState) {
					Messaging.showError(t('correctionErrorOpenDialog'));
					return;
				}
				requestAnimationFrame(() => {
					wizardState.openWizard(summary);
				});
			});
		});

		const api = cfg.apiUrl || {};
		document.querySelectorAll('.btn-cancel-correction').forEach((btn) => {
			btn.addEventListener('click', async (e) => {
				e.preventDefault();
				e.stopPropagation();
				const id = btn.getAttribute('data-entry-id');
				const url = (api.cancelCorrection || '').replace('__ID__', encodeURIComponent(id));
				if (!url) {
					return;
				}
				const confirmMsg = t('confirmCancelCorrection');
				const confirmed = await Utils.confirmDestructiveAction({
					title: t('correctionWithdrawTitle', 'Withdraw correction'),
					message: confirmMsg,
					confirmLabel: t('correctionRemove', 'Withdraw'),
					variant: 'destructive',
				});
				if (!confirmed) {
					return;
				}
				Utils.ajax(url, {
					method: 'POST',
					data: {},
					onSuccess: (data) => {
						if (data.success) {
							Messaging.showSuccess(t('correctionWithdrawn'));
							window.location.reload();
						} else {
							Messaging.showError(data.error || t('correctionWithdrawError'));
						}
					},
					onError: (err) => {
						Messaging.showError(err?.error || t('correctionWithdrawError'));
					},
				});
			});
		});
	}

	document.addEventListener('DOMContentLoaded', init);
})();
