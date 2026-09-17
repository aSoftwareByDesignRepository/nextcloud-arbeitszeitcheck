/**
 * Time entry create/edit form — working hours, breaks, compliance summary, submission.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const cfg = window.ArbeitszeitCheck || {};
	const Utils = window.ArbeitszeitCheckUtils || {};

	function t(key, fallback) {
		const bundle = cfg.l10n || {};
		const value = bundle[key];
		return value !== undefined && value !== '' ? value : (fallback || key);
	}

	function escapeHtml(value) {
		if (Utils.escapeHtml) {
			return Utils.escapeHtml(value);
		}
		const div = document.createElement('div');
		div.textContent = String(value);
		return div.innerHTML;
	}

	async function parseJsonFromTimeEntryResponse(response) {
		const text = await response.text();
		const trimmed = text.trim();
		if (trimmed.length === 0) {
			throw new Error('EMPTY_RESPONSE');
		}
		if (trimmed[0] === '<') {
			throw new Error('HTML_RESPONSE');
		}
		try {
			return JSON.parse(text);
		} catch (e) {
			throw new Error('INVALID_JSON');
		}
	}

	function formatBreakRowLabel(pattern, breakIndex) {
		return String(pattern).replace(/%1\$s/g, String(breakIndex + 1));
	}

class TimeEntryFormManager {
	constructor() {
		this.formConfig = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.timeEntryForm) || {};
		const stepRaw = parseInt(this.formConfig.minuteStep, 10);
		this.minuteStep = (!isNaN(stepRaw) && stepRaw >= 1 && stepRaw <= 30) ? stepRaw : 5;
		this.form = document.getElementById('time-entry-form');
		this.startTimeHour = document.getElementById('entry-start-time-hour');
		this.startTimeMinute = document.getElementById('entry-start-time-minute');
		this.startTimeHidden = document.getElementById('entry-start-time');
		this.endTimeHour = document.getElementById('entry-end-time-hour');
		this.endTimeMinute = document.getElementById('entry-end-time-minute');
		this.endTimeHidden = document.getElementById('entry-end-time');
		this.breaksContainer = document.getElementById('breaks-container');
		this.btnAddBreak = document.getElementById('btn-add-break');
		this.dateInput = document.getElementById('entry-date');
		this.timeSummary = document.getElementById('time-summary');
		this.summaryWorkingHours = document.getElementById('summary-working-hours');
		this.summaryBreakTime = document.getElementById('summary-break-time');
		this.complianceStatus = document.getElementById('compliance-status');
		this.descriptionTextarea = document.getElementById('entry-description');
		this.justificationTextarea = document.getElementById('entry-justification');
		this.justificationCount = document.getElementById('entry-justification-count');
		this.justificationError = document.getElementById('entry-justification-error');
		this.breakRequirementIndicator = document.getElementById('break-requirement-indicator');
		this.breakRequirementText = document.getElementById('break-requirement-text');
		this.autoBreakToggle = document.getElementById('auto-break-enabled');
		this.autoBreakToggleState = document.getElementById('auto-break-toggle-state');
		this.autoBreakPanel = document.querySelector('.auto-break-panel');

		this.breakIndex = this.formConfig.breakIndex != null ? this.formConfig.breakIndex : 1;
		this.syncBreakIndexFromDom();
		this.formSubmitted = false;
		this.userDismissedAutoBreak = false;
		this.maxWorkingHours = this.formConfig.maxDailyHours != null ? this.formConfig.maxDailyHours : 10;
		this.maxBreaks = this.formConfig.maxBreaks != null ? this.formConfig.maxBreaks : 10;
		const minJustRaw = parseInt(this.formConfig.minJustificationLength, 10);
		this.minJustificationLength = (!isNaN(minJustRaw) && minJustRaw >= 1) ? minJustRaw : 10;
		this.manualTimeEntriesRequireApproval = !!this.formConfig.manualTimeEntriesRequireApproval
			|| !!(this.justificationTextarea && this.justificationTextarea.name === 'justification');

		this.init();
	}

	init() {
		this.initAutoBreakToggleFromConfig();
		this.setupTimeInputs();
		this.setupBreakManagement();
		this.setupExistingBreakEntries();
		this.setupTodayButton();
		this.setupDateValidation();
		this.setupJustificationField();
		this.setupFormValidation();
		this.setupFormSubmission();
		this.prefillDescriptionFromQuery();
		this.prefillDateFromQuery();

		// Initial summary; auto-breaks only when the user opted in (toggle on, not dismissed).
		setTimeout(() => {
			if (this.hasFormData()) {
				if (this.shouldRunAutoBreakCalculation()) {
					this.handleAutoBreakCalculation({ notify: false });
				}
				this.updateTimeSummary();
			}
		}, 100);
	}

	/**
	 * Soft flange from MaintenanceCheck (AC-F2): ?description=MaintenanceCheck WO …
	 * Prefills only when the textarea is empty so edit forms stay authoritative.
	 */
	prefillDescriptionFromQuery() {
		if (!this.descriptionTextarea) {
			return;
		}
		if (String(this.descriptionTextarea.value || '').trim() !== '') {
			return;
		}
		try {
			const params = new URLSearchParams(window.location.search || '');
			const raw = String(params.get('description') || '').trim();
			if (raw === '') {
				return;
			}
			this.descriptionTextarea.value = raw.slice(0, 500);
			this.descriptionTextarea.dispatchEvent(new Event('input', { bubbles: true }));
		} catch (e) {
			// Ignore malformed query strings — create form stays usable.
		}
	}

	/**
	 * Four-eyes manual create: live character counter + a11y state for justification.
	 */
	setupJustificationField() {
		if (!this.justificationTextarea) {
			return;
		}
		this.justificationTouched = false;
		const update = () => this.updateJustificationCount();
		this.justificationTextarea.addEventListener('input', () => {
			this.justificationTouched = true;
			update();
		});
		this.justificationTextarea.addEventListener('blur', () => {
			this.justificationTouched = true;
			update();
		});
		update();
	}

	updateJustificationCount() {
		if (!this.justificationTextarea) {
			return;
		}
		const len = String(this.justificationTextarea.value || '').trim().length;
		const min = this.minJustificationLength;
		const remaining = Math.max(0, min - len);
		const ready = len >= min;

		if (this.justificationCount) {
			if (ready) {
				this.justificationCount.textContent = t('justificationReady')
					.replace('{count}', String(len));
				this.justificationCount.classList.remove('time-entry-form__char-count--warn');
				this.justificationCount.classList.add('time-entry-form__char-count--ok');
			} else {
				this.justificationCount.textContent = t('justificationRemaining')
					.replace('{remaining}', String(remaining))
					.replace('{count}', String(len))
					.replace('{min}', String(min));
				this.justificationCount.classList.add('time-entry-form__char-count--warn');
				this.justificationCount.classList.remove('time-entry-form__char-count--ok');
			}
		}

		if (this.justificationTouched || this.formSubmitted) {
			this.justificationTextarea.setAttribute('aria-invalid', ready ? 'false' : 'true');
		} else {
			this.justificationTextarea.setAttribute('aria-invalid', 'false');
		}
		if (ready) {
			this.clearJustificationError();
		}
	}

	clearJustificationError() {
		if (!this.justificationError) {
			return;
		}
		this.justificationError.style.display = 'none';
		this.justificationError.textContent = '';
	}

	showJustificationError(message) {
		if (!this.justificationError) {
			return;
		}
		this.justificationError.textContent = message;
		this.justificationError.style.display = 'block';
	}

	/**
	 * @returns {string}
	 */
	getJustificationValue() {
		if (!this.justificationTextarea) {
			return '';
		}
		return String(this.justificationTextarea.value || '').trim().slice(0, 2000);
	}

	/**
	 * Server contract: when four-eyes is on, justification must be ≥ min length.
	 * @returns {boolean}
	 */
	validateJustification() {
		if (!this.manualTimeEntriesRequireApproval && !this.justificationTextarea) {
			return true;
		}
		if (!this.justificationTextarea) {
			// Config says approval is required but the field is missing — fail closed.
			this.showErrorNotification(t('justificationRequired'));
			return false;
		}
		const text = this.getJustificationValue();
		if (text.length < this.minJustificationLength) {
			this.justificationTextarea.setAttribute('aria-invalid', 'true');
			this.justificationTextarea.setCustomValidity(t('justificationRequired'));
			this.showJustificationError(t('justificationRequired'));
			this.updateJustificationCount();
			this.justificationTextarea.focus();
			this.justificationTextarea.reportValidity();
			return false;
		}
		this.justificationTextarea.setCustomValidity('');
		this.justificationTextarea.setAttribute('aria-invalid', 'false');
		this.clearJustificationError();
		return true;
	}

	/**
	 * Calendar day panel: ?date=YYYY-MM-DD or ?date=dd.mm.yyyy on create form only.
	 */
	prefillDateFromQuery() {
		if (!this.dateInput || this.formConfig.mode === 'edit') {
			return;
		}
		try {
			const params = new URLSearchParams(window.location.search || '');
			const raw = String(params.get('date') || params.get('start') || '').trim();
			if (raw === '') {
				return;
			}
			let display = '';
			const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
			const de = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(raw);
			if (iso) {
				display = `${iso[3]}.${iso[2]}.${iso[1]}`;
			} else if (de) {
				display = raw;
			} else {
				return;
			}
			this.dateInput.value = display;
			this.dateInput.dispatchEvent(new Event('change', { bubbles: true }));
		} catch (e) {
			// Ignore malformed query strings — create form stays usable.
		}
	}

	/**
	 * Sync toggle with server-rendered create/edit state (opt-in on create; edit preserves stored intent).
	 */
	initAutoBreakToggleFromConfig() {
		if (!this.autoBreakToggle) {
			return;
		}
		if (typeof this.formConfig.autoBreakEnabled === 'boolean') {
			this.autoBreakToggle.checked = this.formConfig.autoBreakEnabled;
		}
		this.userDismissedAutoBreak = !this.autoBreakToggle.checked;
		this.updateAutoBreakToggleStatus();
	}

	shouldRunAutoBreakCalculation() {
		return this.autoBreakToggle
			&& this.autoBreakToggle.checked
			&& !this.userDismissedAutoBreak;
	}

	setupExistingBreakEntries() {
		if (!this.breaksContainer) {
			return;
		}
		this.breaksContainer.querySelectorAll('.break-entry').forEach((breakEntry) => {
			this.bindBreakTimeInputs(breakEntry);
		});
		this.syncBreakIndexFromDom();
	}

	/**
	 * Keep the next break row index aligned with server-rendered rows (0-based data-break-index).
	 */
	syncBreakIndexFromDom() {
		if (!this.breaksContainer) {
			return;
		}

		let maxIndex = -1;
		this.breaksContainer.querySelectorAll('.break-entry[data-break-index]').forEach((entry) => {
			const parsed = parseInt(entry.getAttribute('data-break-index'), 10);
			if (!Number.isNaN(parsed) && parsed > maxIndex) {
				maxIndex = parsed;
			}
		});

		if (maxIndex >= 0) {
			this.breakIndex = maxIndex + 1;
		}
	}

	setupTodayButton() {
		const todayBtn = document.getElementById('btn-today');
		if (!todayBtn || !this.dateInput) {
			return;
		}
		todayBtn.addEventListener('click', (e) => {
			e.preventDefault();
			const now = new Date();
			const day = String(now.getDate()).padStart(2, '0');
			const month = String(now.getMonth() + 1).padStart(2, '0');
			const year = now.getFullYear();
			this.dateInput.value = `${day}.${month}.${year}`;
			this.dateInput.setAttribute('aria-invalid', 'false');
			this.dateInput.classList.remove('form-input--error');
			this.validateDate();
			if (this.shouldRunAutoBreakCalculation()) {
				this.handleAutoBreakCalculation({ notify: false });
			}
			this.updateTimeSummary();
		});
	}

	hasFormData() {
		return this.dateInput?.value
			&& this.startTimeHidden?.value
			&& this.endTimeHidden?.value;
	}

	setupTimeInputs() {
		// Main time inputs with auto-break calculation
		if (this.startTimeHour && this.startTimeMinute && this.startTimeHidden) {
			this.bindTimeInputs(this.startTimeHour, this.startTimeMinute, this.startTimeHidden, true);
			this.bindFreeTypeInput(
				document.getElementById('entry-start-time-type'),
				this.startTimeHour,
				this.startTimeMinute,
				this.startTimeHidden,
				true
			);
		}
		if (this.endTimeHour && this.endTimeMinute && this.endTimeHidden) {
			this.bindTimeInputs(this.endTimeHour, this.endTimeMinute, this.endTimeHidden, true);
			this.bindFreeTypeInput(
				document.getElementById('entry-end-time-type'),
				this.endTimeHour,
				this.endTimeMinute,
				this.endTimeHidden,
				true
			);
		}
	}

	ensureMinuteOption(minuteSelect, minutePadded) {
		if (!minuteSelect || minutePadded === '' || minutePadded === '--') {
			return;
		}
		const exists = Array.from(minuteSelect.options).some((o) => o.value === minutePadded);
		if (!exists) {
			const opt = document.createElement('option');
			opt.value = minutePadded;
			opt.textContent = minutePadded;
			minuteSelect.appendChild(opt);
			const values = Array.from(minuteSelect.options)
				.filter((o) => o.value !== '')
				.map((o) => o.value)
				.sort();
			const keepEmpty = minuteSelect.querySelector('option[value=""]');
			minuteSelect.innerHTML = '';
			if (keepEmpty) {
				minuteSelect.appendChild(keepEmpty);
			}
			values.forEach((v) => {
				const o = document.createElement('option');
				o.value = v;
				o.textContent = v;
				minuteSelect.appendChild(o);
			});
		}
	}

	bindFreeTypeInput(typeInput, hourSelect, minuteSelect, hiddenInput, enableAutoBreak = false) {
		if (!typeInput || !hourSelect || !minuteSelect || !hiddenInput) {
			return;
		}
		if (typeInput.hasAttribute('data-bound')) {
			return;
		}
		typeInput.setAttribute('data-bound', 'true');

		const syncFromSelects = () => {
			const hour = hourSelect.value;
			const minute = minuteSelect.value;
			if (!hour || hour === '--' || !minute || minute === '--') {
				return;
			}
			typeInput.value = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
		};

		const applyTyped = () => {
			const raw = String(typeInput.value || '').trim().replace('.', ':');
			const match = raw.match(/^(\d{1,2}):(\d{1,2})$/);
			if (!match) {
				if (raw !== '') {
					typeInput.setAttribute('aria-invalid', 'true');
				}
				return;
			}
			const hourNum = parseInt(match[1], 10);
			const minuteNum = parseInt(match[2], 10);
			if (isNaN(hourNum) || hourNum < 0 || hourNum > 23 || isNaN(minuteNum) || minuteNum < 0 || minuteNum > 59) {
				typeInput.setAttribute('aria-invalid', 'true');
				return;
			}
			typeInput.setAttribute('aria-invalid', 'false');
			const hour = String(hourNum).padStart(2, '0');
			const minute = String(minuteNum).padStart(2, '0');
			hourSelect.value = hour;
			this.ensureMinuteOption(minuteSelect, minute);
			minuteSelect.value = minute;
			hiddenInput.value = `${hour}:${minute}`;
			typeInput.value = `${hour}:${minute}`;
			hourSelect.dispatchEvent(new Event('change', { bubbles: true }));
			if (enableAutoBreak) {
				this.handleAutoBreakCalculation({ notify: false });
			}
			this.updateTimeSummary();
		};

		hourSelect.addEventListener('change', syncFromSelects);
		minuteSelect.addEventListener('change', syncFromSelects);
		typeInput.addEventListener('change', applyTyped);
		typeInput.addEventListener('blur', applyTyped);
		// Apply as soon as a complete HH:MM is present (Playwright fill / paste / IME
		// often never synthesise a reliable change+blur pair on Chromium).
		typeInput.addEventListener('input', () => {
			const raw = String(typeInput.value || '').trim().replace('.', ':');
			if (/^\d{1,2}:\d{2}$/.test(raw)) {
				applyTyped();
			}
		});
		typeInput.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyTyped();
			}
		});
		syncFromSelects();
	}

	bindTimeInputs(hourSelect, minuteSelect, hiddenInput, enableAutoBreak = false) {
		if (!hourSelect || !minuteSelect || !hiddenInput) return;

		// Prevent duplicate binding
		if (hourSelect.hasAttribute('data-bound')) return;
		hourSelect.setAttribute('data-bound', 'true');

		const updateValue = () => {
			try {
				// Validate inputs
				const hour = hourSelect.value;
				const minute = minuteSelect.value;

				// Handle empty/invalid values
				if (!hour || hour === '--' || !minute || minute === '--') {
					hiddenInput.value = '';
					return;
				}

				// Ensure valid hour (0-23) and minute (0-59)
				const hourNum = parseInt(hour, 10);
				const minuteNum = parseInt(minute, 10);

				if (isNaN(hourNum) || hourNum < 0 || hourNum > 23 ||
					isNaN(minuteNum) || minuteNum < 0 || minuteNum > 59) {
					console.warn('Invalid time values:', hour, minute);
					hiddenInput.value = '';
					return;
				}

				hiddenInput.value = `${String(hourNum).padStart(2, '0')}:${String(minuteNum).padStart(2, '0')}`;

				if (enableAutoBreak) {
					this.handleAutoBreakCalculation({ notify: false });
				}
				this.updateTimeSummary();
			} catch (error) {
				console.error('Error updating time input:', error);
				hiddenInput.value = '';
			}
		};

		hourSelect.addEventListener('change', updateValue);
		minuteSelect.addEventListener('change', updateValue);

		// Initial update
		updateValue();
	}

	/**
	 * Automatically calculate and enforce required breaks from the country
	 * labour-law profile (DE ArbZG / AT AZG tiers via complianceParams).
	 */
	handleAutoBreakCalculation(options = {}) {
		const notify = options.notify !== false;

		if (!this.shouldRunAutoBreakCalculation()) {
			return;
		}

		if (!this.hasFormData()) {
			return;
		}

		try {
			const dateStr = this.convertDateFormat(this.dateInput.value);
			if (!dateStr) {
				return;
			}

			const startTime = this.startTimeHidden.value;
			const endTime = this.endTimeHidden.value;
			if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') {
				return;
			}

			const startDateTime = new Date(dateStr + 'T' + startTime);
			let endDateTime = new Date(dateStr + 'T' + endTime);

			if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
				return;
			}

			if (endDateTime < startDateTime) {
				endDateTime.setDate(endDateTime.getDate() + 1);
			}

			const workDurationMs = endDateTime - startDateTime;
			if (workDurationMs <= 0) {
				this.removeAutoAddedBreaks();
				return;
			}

			const grossHours = workDurationMs / (1000 * 60 * 60);
			const manualBreakMinutes = this.calculateManualBreakTime(dateStr);
			const validation = window.ArbeitszeitCheckValidation;
			const netWorkingHours = validation && typeof validation.netWorkingHours === 'function'
				? validation.netWorkingHours(grossHours, manualBreakMinutes)
				: Math.max(0, grossHours - manualBreakMinutes / 60);
			const requiredBreakMinutes = this.resolveRequiredBreakMinutes(netWorkingHours);

			if (requiredBreakMinutes === 0) {
				this.removeAutoAddedBreaks();
				this.updateBreakRequirementIndicator(netWorkingHours);
				return;
			}

			const shortfallMinutes = Math.max(0, requiredBreakMinutes - manualBreakMinutes);

			this.removeAutoAddedBreaks();

			if (shortfallMinutes > 0) {
				this.addAutomaticBreak(startDateTime, endDateTime, shortfallMinutes, notify);
			}

			this.updateBreakRequirementIndicator(netWorkingHours);
		} catch (error) {
			console.warn('Error in auto-break calculation:', error);
		}
	}

	/**
	 * Required break minutes for the shift, preferring the shared validation
	 * module (same complianceParams source) with the local profile fallback.
	 */
	resolveRequiredBreakMinutes(workingHours) {
		const validation = window.ArbeitszeitCheckValidation;
		if (validation && typeof validation.calculateRequiredBreakMinutes === 'function') {
			return validation.calculateRequiredBreakMinutes(workingHours);
		}
		return this.requiredBreakMinutesFromProfile(workingHours);
	}

	requiredBreakMinutesFromProfile(workingHours) {
		const tiers = (window.ArbeitszeitCheck?.complianceParams?.breakTiers || [
			{ afterHours: 6, breakMinutes: 30 },
			{ afterHours: 9, breakMinutes: 45 },
		]).slice().sort((a, b) => Number(b.afterHours) - Number(a.afterHours));
		for (const tier of tiers) {
			if (workingHours > Number(tier.afterHours)) {
				return Math.round(Number(tier.breakMinutes)) || 0;
			}
		}
		return 0;
	}

	/**
	 * Break minutes from user-entered rows only (excludes auto-generated rows).
	 */
	calculateManualBreakTime(dateStr) {
		if (!this.breaksContainer) {
			return 0;
		}

		let totalBreakMinutes = 0;
		const breakEntries = this.breaksContainer.querySelectorAll('.break-entry:not([data-auto-break])');
		const minBreakMinutes = Number(window.ArbeitszeitCheck?.complianceParams?.minBreakMinutes);
		const minBreakMs = (Number.isFinite(minBreakMinutes) && minBreakMinutes > 0 ? minBreakMinutes : 15) * 60 * 1000;

		breakEntries.forEach((breakEntry) => {
			try {
				const startHidden = breakEntry.querySelector('input.break-start-time');
				const endHidden = breakEntry.querySelector('input.break-end-time');
				if (!startHidden || !endHidden || !startHidden.value || !endHidden.value) {
					return;
				}

				let breakStart = new Date(dateStr + 'T' + startHidden.value);
				let breakEnd = new Date(dateStr + 'T' + endHidden.value);
				if (isNaN(breakStart.getTime()) || isNaN(breakEnd.getTime())) {
					return;
				}

				if (breakEnd < breakStart) {
					breakEnd.setDate(breakEnd.getDate() + 1);
				}

				const durationMs = breakEnd - breakStart;
				if (durationMs >= minBreakMs && durationMs > 0) {
					totalBreakMinutes += durationMs / (1000 * 60);
				}
			} catch (error) {
				console.warn('Error calculating manual break time:', error);
			}
		});

		return totalBreakMinutes;
	}

	/**
	 * Add an automatic break to cover the required shortfall in break minutes.
	 */
	addAutomaticBreak(startDateTime, endDateTime, shortfallMinutes, notify = true) {
		const breakStartTime = this.calculateOptimalBreakTime(startDateTime, endDateTime, shortfallMinutes);
		if (!breakStartTime) {
			return;
		}

		if (this.populateEmptyBreakRow(breakStartTime, shortfallMinutes)) {
			this.updateTimeSummary();
			return;
		}

		this.createAutoBreak(breakStartTime, shortfallMinutes);

		if (notify && window.OC && OC.Notification) {
			const loc = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n;
			const rounded = Math.round(shortfallMinutes);
			const breakText = (loc && loc['autoBreakDuration' + rounded])
				|| (rounded + ' minutes');
			const msg = t('autoBreakAddedCompliance').replace('%s', breakText);
			OC.Notification.showTemporary(msg, { type: 'info', timeout: 3000 });
		}

		this.updateTimeSummary();
	}

	/**
	 * Fill the first empty break row instead of appending another (create form default).
	 *
	 * @returns {boolean} true when an empty row was populated
	 */
	populateEmptyBreakRow(breakStartTime, breakMinutes) {
		if (!this.breaksContainer) {
			return false;
		}

		const breakEntries = this.breaksContainer.querySelectorAll('.break-entry:not([data-auto-break])');
		for (const entry of breakEntries) {
			const startHidden = entry.querySelector('input.break-start-time');
			const endHidden = entry.querySelector('input.break-end-time');
			if (startHidden && endHidden &&
				(!startHidden.value || startHidden.value === '' || startHidden.value === '00:00')) {
				this.updateAutoBreak(entry, breakStartTime, breakMinutes);
				this.bindBreakTimeInputs(entry);
				return true;
			}
		}

		return false;
	}

	/**
	 * Choose an optimal break start time within the working period.
	 * Places the break roughly in the middle of the shift while ensuring it ends before endDateTime.
	 */
	calculateOptimalBreakTime(startDateTime, endDateTime, breakMinutes) {
		const workDurationMs = endDateTime - startDateTime;
		if (workDurationMs <= 0) {
			return null;
		}

		const breakMs = breakMinutes * 60 * 1000;
		// Center the break in the working period
		const startMs = startDateTime.getTime() + Math.max(0, (workDurationMs - breakMs) / 2);
		const breakStartTime = new Date(startMs);

		const breakEndTime = new Date(breakStartTime.getTime() + breakMs);
		if (breakEndTime > endDateTime) {
			// If break would end after work, start it earlier
			breakStartTime.setTime(endDateTime.getTime() - breakMs);
		}

		return breakStartTime;
	}

	/**
	 * Create a new auto-break entry in the form for the given start time and duration.
	 */
	createAutoBreak(breakStartTime, breakMinutes) {
		if (!this.breaksContainer) {
			return;
		}

		const breakEntry = this.createBreakEntryElement(this.breakIndex);
		this.breaksContainer.appendChild(breakEntry);

		// Update the break with calculated times and mark it as auto-generated
		this.updateAutoBreak(breakEntry, breakStartTime, breakMinutes);

		this.breakIndex++;
		this.updateTimeSummary();
	}

	/**
	 * Update an existing break entry with given start time and duration and mark it as auto-generated.
	 */
	updateAutoBreak(breakEntry, breakStartTime, breakMinutes) {
		const index = breakEntry.getAttribute('data-break-index');
		if (!index) return;

		const startHidden = breakEntry.querySelector('input.break-start-time[data-break-index="' + index + '"]');
		const endHidden = breakEntry.querySelector('input.break-end-time[data-break-index="' + index + '"]');
		const startHourSelect = breakEntry.querySelector('.break-start-time-hour');
		const startMinuteSelect = breakEntry.querySelector('.break-start-time-minute');
		const endHourSelect = breakEntry.querySelector('.break-end-time-hour');
		const endMinuteSelect = breakEntry.querySelector('.break-end-time-minute');

		const startHour = String(breakStartTime.getHours()).padStart(2, '0');
		const startMinute = String(breakStartTime.getMinutes()).padStart(2, '0');
		const endTime = new Date(breakStartTime.getTime() + breakMinutes * 60 * 1000);
		const endHour = String(endTime.getHours()).padStart(2, '0');
		const endMinute = String(endTime.getMinutes()).padStart(2, '0');

		if (startHourSelect) startHourSelect.value = startHour;
		if (startMinuteSelect) startMinuteSelect.value = startMinute;
		if (endHourSelect) endHourSelect.value = endHour;
		if (endMinuteSelect) endMinuteSelect.value = endMinute;

		if (startHidden) startHidden.value = `${startHour}:${startMinute}`;
		if (endHidden) endHidden.value = `${endHour}:${endMinute}`;

		// Trigger change events so any listeners update summaries/validation
		if (startHourSelect) startHourSelect.dispatchEvent(new Event('change', { bubbles: true }));
		if (endHourSelect) endHourSelect.dispatchEvent(new Event('change', { bubbles: true }));

		// Mark as auto-generated
		breakEntry.setAttribute('data-auto-break', 'true');

		// Add a note below the auto-break row (full width under the time pair)
		const formGrid = breakEntry.querySelector('.time-pair-matrix__grid--row');
		if (formGrid && !formGrid.querySelector('.auto-break-note')) {
			const autoNote = document.createElement('p');
			autoNote.className = 'form-help auto-break-note';
			autoNote.setAttribute('role', 'status');
			const autoNoteSmall = document.createElement('small');
			autoNoteSmall.textContent = t('autoBreakNote');
			autoNote.appendChild(autoNoteSmall);
			formGrid.appendChild(autoNote);
			formGrid.classList.add('time-pair-matrix__grid--row--with-note');
		}
	}

	/**
	 * Remove all automatically added breaks (used when no break is required).
	 */
	removeAutoAddedBreaks() {
		if (!this.breaksContainer) return;
		const autoBreaks = this.breaksContainer.querySelectorAll('.break-entry[data-auto-break]');
		autoBreaks.forEach((entry) => entry.remove());
		this.updateTimeSummary();
	}

	setupBreakManagement() {
		if (!this.btnAddBreak || !this.breaksContainer) return;

		this.btnAddBreak.addEventListener('click', (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.addBreakEntry();
		});

		// Handle remove buttons (click may land on button text)
		this.breaksContainer.addEventListener('click', (e) => {
			const removeBtn = e.target.closest('.btn-remove-break');
			if (removeBtn) {
				e.preventDefault();
				this.removeBreakEntry(removeBtn);
			}
		});

		// Handle auto-break toggle
		if (this.autoBreakToggle) {
			this.updateAutoBreakToggleStatus();
			this.autoBreakToggle.addEventListener('change', () => {
				this.updateAutoBreakToggleStatus();
				if (this.autoBreakToggle.checked) {
					this.userDismissedAutoBreak = false;
					this.handleAutoBreakCalculation({ notify: true });
				} else {
					this.userDismissedAutoBreak = true;
					this.removeAutoAddedBreaks();
					if (window.OC && OC.Notification) {
						OC.Notification.showTemporary(
							t('autoBreakDisabled'),
							{ type: 'info', timeout: 2000 }
						);
					}
				}
			});
		}
	}

	/**
	 * Visible on/off label for the auto-break switch (WCAG: not color-only).
	 */
	updateAutoBreakToggleStatus() {
		if (!this.autoBreakToggle || !this.autoBreakToggleState) {
			return;
		}

		const isOn = this.autoBreakToggle.checked;
		this.autoBreakToggleState.textContent = isOn
			? t('autoBreakStateOn', 'Enabled')
			: t('autoBreakStateOff', 'Disabled');
		this.autoBreakToggleState.classList.toggle('auto-break-toggle__status--on', isOn);
		this.autoBreakToggleState.classList.toggle('auto-break-toggle__status--off', !isOn);
		this.autoBreakToggleState.classList.toggle('azc-status-pill--on', isOn);
		this.autoBreakToggleState.classList.toggle('azc-status-pill--off', !isOn);
		if (this.autoBreakPanel) {
			this.autoBreakPanel.classList.toggle('auto-break-panel--enabled', isOn);
			this.autoBreakPanel.classList.toggle('auto-break-panel--disabled', !isOn);
		}
	}

	addBreakEntry() {
		try {
			// Prevent adding too many breaks (performance and usability)
			const existingBreaks = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
			if (existingBreaks.length >= this.maxBreaks) {
				if (window.OC && OC.Notification) {
					OC.Notification.showTemporary(
						t('maxBreaksAllowed').replace(/%(\d+\$)?d/, String(this.maxBreaks)),
						{ type: 'error', timeout: 3000 }
					);
				}
				return;
			}

			const breakEntry = this.createBreakEntryElement(this.breakIndex);
			this.breaksContainer.appendChild(breakEntry);

			// Bind time inputs for the new break
			this.bindBreakTimeInputs(breakEntry);

			this.breakIndex++;
			this.updateTimeSummary();
		} catch (error) {
			console.error('Error adding break entry:', error);
		}
	}

	createBreakEntryElement(index) {
		const breakEntry = document.createElement('div');
		breakEntry.className = 'break-entry';
		breakEntry.setAttribute('data-break-index', index);

		const formGrid = document.createElement('div');
		formGrid.className = 'time-pair-matrix__grid time-pair-matrix__grid--row';

		// Start time group
		const startGroup = document.createElement('div');
		startGroup.className = 'form-group';

		const startLabel = document.createElement('label');
		startLabel.className = 'form-label time-pair-matrix__row-label';
		startLabel.id = 'break-' + index + '-start-label';
		const startIcon = document.createElement('span');
		startIcon.className = 'form-label-icon';
		startIcon.setAttribute('aria-hidden', 'true');
		if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
			startIcon.innerHTML = window.AzcCatalog.render('coffee', 'form-label-icon__svg');
		}
		const startSr = document.createElement('span');
		startSr.className = 'sr-only';
		startSr.textContent = formatBreakRowLabel(
			t('breakRowStartLabel', 'Break %1$s start'),
			index
		);
		startLabel.appendChild(startIcon);
		startLabel.appendChild(startSr);

		const startTimeGroup = document.createElement('div');
		startTimeGroup.className = 'time-input-group';
		startTimeGroup.setAttribute('role', 'group');
		startTimeGroup.setAttribute('aria-labelledby', startLabel.id);

		const startHourSelect = document.createElement('select');
		startHourSelect.className = 'form-input time-hour break-start-time-hour';
		startHourSelect.setAttribute('data-break-index', index);
		startHourSelect.setAttribute('aria-label', t('breakStartHour'));

		const startMinuteSelect = document.createElement('select');
		startMinuteSelect.className = 'form-input time-minute break-start-time-minute';
		startMinuteSelect.setAttribute('data-break-index', index);
		startMinuteSelect.setAttribute('aria-label', t('breakStartMinute'));

		const startHidden = document.createElement('input');
		startHidden.type = 'hidden';
		startHidden.className = 'break-start-time';
		startHidden.setAttribute('data-break-index', index);
		startHidden.name = `breaks[${index}][start]`;
		startHidden.value = '';

		// Add options
		this.addTimeOptions(startHourSelect, startMinuteSelect);

		const startSeparator = document.createElement('span');
		startSeparator.className = 'time-separator';
		startSeparator.setAttribute('aria-hidden', 'true');
		startSeparator.textContent = ':';

		startTimeGroup.appendChild(startHourSelect);
		startTimeGroup.appendChild(startSeparator);
		startTimeGroup.appendChild(startMinuteSelect);
		startTimeGroup.appendChild(startHidden);

		startGroup.appendChild(startLabel);
		startGroup.appendChild(startTimeGroup);

		// End time group
		const endGroup = document.createElement('div');
		endGroup.className = 'form-group';

		const endLabel = document.createElement('label');
		endLabel.className = 'form-label time-pair-matrix__row-label';
		endLabel.id = 'break-' + index + '-end-label';
		const endIcon = document.createElement('span');
		endIcon.className = 'form-label-icon';
		endIcon.setAttribute('aria-hidden', 'true');
		if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
			endIcon.innerHTML = window.AzcCatalog.render('coffee', 'form-label-icon__svg');
		}
		const endSr = document.createElement('span');
		endSr.className = 'sr-only';
		endSr.textContent = formatBreakRowLabel(
			t('breakRowEndLabel', 'Break %1$s end'),
			index
		);
		endLabel.appendChild(endIcon);
		endLabel.appendChild(endSr);

		const endTimeGroup = document.createElement('div');
		endTimeGroup.className = 'time-input-group';
		endTimeGroup.setAttribute('role', 'group');
		endTimeGroup.setAttribute('aria-labelledby', endLabel.id);

		const endHourSelect = document.createElement('select');
		endHourSelect.className = 'form-input time-hour break-end-time-hour';
		endHourSelect.setAttribute('data-break-index', index);
		endHourSelect.setAttribute('aria-label', t('breakEndHour'));

		const endMinuteSelect = document.createElement('select');
		endMinuteSelect.className = 'form-input time-minute break-end-time-minute';
		endMinuteSelect.setAttribute('data-break-index', index);
		endMinuteSelect.setAttribute('aria-label', t('breakEndMinute'));

		const endHidden = document.createElement('input');
		endHidden.type = 'hidden';
		endHidden.className = 'break-end-time';
		endHidden.setAttribute('data-break-index', index);
		endHidden.name = `breaks[${index}][end]`;
		endHidden.value = '';

		// Add options
		this.addTimeOptions(endHourSelect, endMinuteSelect);

		const endSeparator = document.createElement('span');
		endSeparator.className = 'time-separator';
		endSeparator.setAttribute('aria-hidden', 'true');
		endSeparator.textContent = ':';

		endTimeGroup.appendChild(endHourSelect);
		endTimeGroup.appendChild(endSeparator);
		endTimeGroup.appendChild(endMinuteSelect);
		endTimeGroup.appendChild(endHidden);

		endGroup.appendChild(endLabel);
		endGroup.appendChild(endTimeGroup);

		const actionCell = document.createElement('div');
		actionCell.className = 'time-pair-matrix__action';
		const removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'azc-btn azc-btn--sm azc-btn--danger btn-remove-break';
		removeBtn.setAttribute('data-break-index', index);
		removeBtn.title = t('removeBreak');
		removeBtn.setAttribute('aria-label', t('removeThisBreak'));
		removeBtn.textContent = t('remove');
		actionCell.appendChild(removeBtn);

		formGrid.appendChild(startGroup);
		formGrid.appendChild(endGroup);
		formGrid.appendChild(actionCell);
		breakEntry.appendChild(formGrid);

		return breakEntry;
	}

	addTimeOptions(hourSelect, minuteSelect) {
		// Empty option
		const emptyOption = document.createElement('option');
		emptyOption.value = '';
		emptyOption.textContent = '--';

		hourSelect.appendChild(emptyOption.cloneNode(true));
		minuteSelect.appendChild(emptyOption.cloneNode(true));

		// Hours
		for (let h = 0; h < 24; h++) {
			const option = document.createElement('option');
			option.value = String(h).padStart(2, '0');
			option.textContent = String(h).padStart(2, '0');
			hourSelect.appendChild(option);
		}

		// Minutes (5-minute steps by default; odd values added via ensureMinuteOption)
		const step = this.minuteStep || 5;
		for (let m = 0; m < 60; m += step) {
			const option = document.createElement('option');
			option.value = String(m).padStart(2, '0');
			option.textContent = String(m).padStart(2, '0');
			minuteSelect.appendChild(option);
		}
	}

	bindBreakTimeInputs(breakEntry) {
		const index = breakEntry.getAttribute('data-break-index');
		if (!index) return;

		const startHour = breakEntry.querySelector('.break-start-time-hour');
		const startMinute = breakEntry.querySelector('.break-start-time-minute');
		const startHidden = breakEntry.querySelector(`input.break-start-time[data-break-index="${index}"]`);

		const endHour = breakEntry.querySelector('.break-end-time-hour');
		const endMinute = breakEntry.querySelector('.break-end-time-minute');
		const endHidden = breakEntry.querySelector(`input.break-end-time[data-break-index="${index}"]`);

		if (startHour && startMinute && startHidden) {
			this.bindTimeInputs(startHour, startMinute, startHidden, false); // Don't trigger auto-break for break inputs
		}
		if (endHour && endMinute && endHidden) {
			this.bindTimeInputs(endHour, endMinute, endHidden, false); // Don't trigger auto-break for break inputs
		}
	}

	removeBreakEntry(button) {
		const index = button.getAttribute('data-break-index');
		const breakEntry = this.breaksContainer.querySelector(`[data-break-index="${index}"]`);
		if (!breakEntry) {
			return;
		}

		if (breakEntry.hasAttribute('data-auto-break')) {
			this.userDismissedAutoBreak = true;
		}

		breakEntry.remove();

		if (this.breaksContainer.querySelectorAll('.break-entry').length === 0) {
			this.addBreakEntry();
		}

		this.updateTimeSummary();
		this.validateTimes();
	}

	setupDateValidation() {
		if (!this.dateInput) return;

		this.dateInput.addEventListener('blur', () => {
			this.validateDate();
			if (this.shouldRunAutoBreakCalculation()) {
				this.handleAutoBreakCalculation({ notify: false });
			}
			this.updateTimeSummary();
		});
	}

	validateDate() {
		if (!this.dateInput) return true;

		const value = this.dateInput.value.trim();
		const errorContainer = document.getElementById('entry-date-error');

		// Clear previous error
		if (errorContainer) {
			errorContainer.style.display = 'none';
			errorContainer.textContent = '';
		}

		if (!value) {
			this.showDateError(t('dateRequired'));
			return false;
		}

		const result = this.parseDate(value);
		if (!result.valid) {
			const errorMsg = result.errors[0] || t('invalidDate');
			this.showDateError(errorMsg);
			return false;
		}

		// Valid date - try to fix format if needed
		if (result.date) {
			// Prevent future dates (more than 1 day in the future to account for timezone differences)
			const tomorrow = new Date();
			tomorrow.setDate(tomorrow.getDate() + 1);

			if (result.date > tomorrow) {
				this.showDateError(t('dateFuture'));
				return false;
			}

			// Prevent dates too far in the past (more than 1 year ago)
			const oneYearAgo = new Date();
			oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

			if (result.date < oneYearAgo) {
				this.showDateError(t('dateTooOld'));
				return false;
			}

			const day = String(result.date.getDate()).padStart(2, '0');
			const month = String(result.date.getMonth() + 1).padStart(2, '0');
			const year = result.date.getFullYear();
			this.dateInput.value = `${day}.${month}.${year}`;
		}

		if (this.dateInput) {
			this.dateInput.setAttribute('aria-invalid', 'false');
			this.dateInput.classList.remove('form-input--error');
		}

		return true;
	}

	showDateError(message) {
		const errorContainer = document.getElementById('entry-date-error');
		if (errorContainer) {
			const safeMessage = escapeHtml(String(message));
			const errorIcon = (window.AzcCatalog && typeof window.AzcCatalog.render === 'function')
				? window.AzcCatalog.render('triangle-alert', 'form-error__icon-svg')
				: '';
			errorContainer.style.display = 'block';
			errorContainer.innerHTML = '<div class="form-error" role="alert"><span class="form-error__icon" aria-hidden="true">' + errorIcon + '</span><div class="form-error__content"><strong>' + safeMessage + '</strong></div></div>';
		}
		if (this.dateInput) {
			this.dateInput.setAttribute('aria-invalid', 'true');
			this.dateInput.classList.add('form-input--error');
			this.dateInput.setCustomValidity(String(message));
		}
	}

	parseDate(dateStr) {
		const result = { valid: false, date: null, errors: [] };

		if (!dateStr || typeof dateStr !== 'string') {
			result.errors.push('Date is required');
			return result;
		}

		// Try dd.mm.yyyy format
		const ddmmyyyyMatch = dateStr.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
		if (ddmmyyyyMatch) {
			const day = parseInt(ddmmyyyyMatch[1], 10);
			const month = parseInt(ddmmyyyyMatch[2], 10) - 1; // JS months are 0-based
			const year = parseInt(ddmmyyyyMatch[3], 10);

			const date = new Date(year, month, day);
			if (date.getFullYear() === year && date.getMonth() === month && date.getDate() === day) {
				result.valid = true;
				result.date = date;
				return result;
			} else {
				result.errors.push('Invalid date values');
				return result;
			}
		}

		// Try yyyy-mm-dd format
		const yyyymmddMatch = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})$/);
		if (yyyymmddMatch) {
			const year = parseInt(yyyymmddMatch[1], 10);
			const month = parseInt(yyyymmddMatch[2], 10) - 1;
			const day = parseInt(yyyymmddMatch[3], 10);

			const date = new Date(year, month, day);
			if (date.getFullYear() === year && date.getMonth() === month && date.getDate() === day) {
				result.valid = true;
				result.date = date;
				return result;
				} else {
				result.errors.push('Invalid date values');
				return result;
			}
		}

		result.errors.push('Date must be in format dd.mm.yyyy');
		return result;
	}

	updateTimeSummary() {
		if (!this.timeSummary) {
			return;
		}
		this.timeSummary.style.display = 'block';

		const showSummaryPrompt = (message) => {
			if (this.summaryWorkingHours) {
				this.summaryWorkingHours.textContent = '0.00';
			}
			if (this.summaryBreakTime) {
				this.summaryBreakTime.textContent = '0.00';
			}
			if (this.complianceStatus) {
				this.complianceStatus.className = 'compliance-status warning';
				this.complianceStatus.textContent = message;
			}
			this.updateBreakRequirementIndicator(0);
		};

		if (!this.hasFormData()) {
			showSummaryPrompt(t('Please enter both start and end time.', 'Please enter both start and end time.'));
			return;
		}

		const dateStr = this.convertDateFormat(this.dateInput.value);
		if (!dateStr) {
			showSummaryPrompt(t('Please enter valid start and end times.', 'Please enter valid start and end times.'));
			return;
		}

		const startTime = this.startTimeHidden.value;
		const endTime = this.endTimeHidden.value;
		if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') {
			showSummaryPrompt(t('Please enter both start and end time.', 'Please enter both start and end time.'));
			return;
		}

		try {
			const startDateTime = new Date(dateStr + 'T' + startTime);
			let endDateTime = new Date(dateStr + 'T' + endTime);

			// Validate Date objects
			if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
				console.warn('Invalid date/time values');
				showSummaryPrompt(t('Please enter valid start and end times.', 'Please enter valid start and end times.'));
				return;
			}

			// Handle overnight work (end time next day)
			if (endDateTime < startDateTime) {
				endDateTime.setDate(endDateTime.getDate() + 1);
			}

			// Calculate breaks
			const breaks = [];
			const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
			const profileMinBreak = Number(window.ArbeitszeitCheck?.complianceParams?.minBreakMinutes);
			const minBreakDurationMs = (Number.isFinite(profileMinBreak) && profileMinBreak > 0 ? profileMinBreak : 15) * 60 * 1000;

			breakEntries.forEach((breakEntry) => {
				try {
					const breakStartInput = breakEntry.querySelector('input.break-start-time');
					const breakEndInput = breakEntry.querySelector('input.break-end-time');
					if (breakStartInput && breakEndInput && breakStartInput.value && breakEndInput.value) {
						const breakStart = new Date(dateStr + 'T' + breakStartInput.value);
						let breakEnd = new Date(dateStr + 'T' + breakEndInput.value);

						// Validate break Date objects
						if (isNaN(breakStart.getTime()) || isNaN(breakEnd.getTime())) {
							return; // Skip invalid breaks
						}

						// Handle overnight breaks
						if (breakEnd < breakStart) breakEnd.setDate(breakEnd.getDate() + 1);

						const breakDurationMs = breakEnd - breakStart;

						// Only include countable breaks (profile floor: DE/CH 15, AT 10).
						if (breakDurationMs >= minBreakDurationMs && breakDurationMs > 0) {
							breaks.push({
								start: breakStart.toISOString(),
								end: breakEnd.toISOString()
							});
						}
					}
				} catch (breakError) {
					console.warn('Error processing break:', breakError);
					// Continue processing other breaks
				}
			});

			// Calculate working duration
			const totalDurationMs = endDateTime - startDateTime;

			// Validate duration is positive
			if (totalDurationMs <= 0) {
				console.warn('Invalid work duration');
				showSummaryPrompt(t('Please enter valid start and end times.', 'Please enter valid start and end times.'));
				return;
			}

			const totalDurationHours = totalDurationMs / (1000 * 60 * 60);

			let breakDurationHours = 0;
			breaks.forEach(breakTime => {
				try {
					const breakStart = new Date(breakTime.start);
					const breakEnd = new Date(breakTime.end);

					if (!isNaN(breakStart.getTime()) && !isNaN(breakEnd.getTime())) {
						const breakMs = breakEnd - breakStart;
						if (breakMs > 0) {
							breakDurationHours += breakMs / (1000 * 60 * 60);
						}
					}
				} catch (breakCalcError) {
					console.warn('Error calculating break duration:', breakCalcError);
				}
			});

			// Ensure break time doesn't exceed work time
			breakDurationHours = Math.min(breakDurationHours, totalDurationHours);
			const workingDurationHours = Math.max(0, totalDurationHours - breakDurationHours);

			// Update summary display
			if (this.summaryWorkingHours) {
				this.summaryWorkingHours.textContent = workingDurationHours.toFixed(2);
			}
			if (this.summaryBreakTime) {
				this.summaryBreakTime.textContent = breakDurationHours.toFixed(2);
			}
			// Update compliance status (sum + AZG split patterns when configured)
			this.updateComplianceStatus(workingDurationHours, breakDurationHours, this.collectBreakPortionMinutes(dateStr));

			// Update break requirement indicator
			this.updateBreakRequirementIndicator(workingDurationHours);

		} catch (error) {
			console.warn('Error updating time summary:', error);
			showSummaryPrompt(t('Please enter valid start and end times.', 'Please enter valid start and end times.'));
		}
	}

	/**
	 * Whole-minute break lengths for AZG split checks (mirrors BreakSplitValidator input).
	 * @param {string} dateStr Y-m-d
	 * @returns {number[]}
	 */
	collectBreakPortionMinutes(dateStr) {
		const portions = [];
		if (!this.breaksContainer || !dateStr) {
			return portions;
		}
		this.breaksContainer.querySelectorAll('.break-entry').forEach((breakEntry) => {
			const breakStartInput = breakEntry.querySelector('input.break-start-time');
			const breakEndInput = breakEntry.querySelector('input.break-end-time');
			if (!breakStartInput || !breakEndInput || !breakStartInput.value || !breakEndInput.value) {
				return;
			}
			try {
				const breakStart = new Date(dateStr + 'T' + breakStartInput.value);
				let breakEnd = new Date(dateStr + 'T' + breakEndInput.value);
				if (breakEnd < breakStart) {
					breakEnd.setDate(breakEnd.getDate() + 1);
				}
				const minutes = Math.round((breakEnd - breakStart) / 60000);
				if (minutes > 0) {
					portions.push(minutes);
				}
			} catch (e) {
				// Skip incomplete/invalid rows — server still enforces on save.
			}
		});
		return portions;
	}

	updateComplianceStatus(workingHours, breakHours, portionMinutes) {
		if (!this.complianceStatus) return;

		const requiredBreakMinutes = this.resolveRequiredBreakMinutes(workingHours);
		const requiredBreakHours = requiredBreakMinutes / 60;
		const Validation = window.ArbeitszeitCheckValidation || window.ArbeitszeitCheck?.Validation;
		const portions = Array.isArray(portionMinutes) ? portionMinutes : [];
		const evalResult = Validation && typeof Validation.evaluateBreakCompliance === 'function'
			? Validation.evaluateBreakCompliance(portions, workingHours)
			: {
				ok: breakHours >= requiredBreakHours,
				requiredMinutes: requiredBreakMinutes,
				splitInvalid: false,
				message: null,
			};
		const hasRequiredBreak = evalResult.ok;

		let statusClass = 'compliant';
		let statusText = '';

		if (workingHours > this.maxWorkingHours) {
			statusClass = 'violation';
			statusText = t('complianceMaxHours');
		} else if (!hasRequiredBreak && requiredBreakHours > 0) {
			const hasAutoBreak = this.breaksContainer
				? this.breaksContainer.querySelector('.break-entry[data-auto-break]')
				: false;

			if (hasAutoBreak && this.shouldRunAutoBreakCalculation()) {
				statusClass = 'warning';
				statusText = t('complianceRecalculatingBreak');
			} else if (evalResult.splitInvalid) {
				statusClass = 'warning';
				statusText = t('complianceBreakSplitInvalid') || evalResult.message;
			} else {
				statusClass = 'warning';
				statusText = t('complianceBreakNotMet');
			}
		} else if (workingHours >= 8 && workingHours <= this.maxWorkingHours) {
			statusClass = 'warning';
			statusText = t('complianceApproachingMax');
		} else {
			const hasAutoBreak = this.breaksContainer
				? this.breaksContainer.querySelector('.break-entry[data-auto-break]')
				: false;
			const requiredMinutes = this.resolveRequiredBreakMinutes(workingHours);

			if (requiredMinutes <= 0) {
				statusText = t('complianceShortShift');
			} else {
				const autoKey = 'complianceAuto' + requiredMinutes;
				const manualKey = 'complianceManual' + requiredMinutes;
				statusText = hasAutoBreak
					? (t(autoKey) || t('complianceOk'))
					: (t(manualKey) || t('complianceOk'));
			}
		}

		const statusIconMap = { compliant: 'check', warning: 'circle-alert', violation: 'x' };
		const statusIconName = statusIconMap[statusClass] || 'info';
		let statusIconHtml = '';
		if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
			statusIconHtml = '<span class="compliance-status__icon" aria-hidden="true">'
				+ window.AzcCatalog.render(statusIconName, 'compliance-status__icon-svg') + '</span>';
		}
		this.complianceStatus.className = 'compliance-status ' + statusClass;
		this.complianceStatus.innerHTML = statusIconHtml
			+ '<span class="compliance-status__text">' + escapeHtml(String(statusText)) + '</span>';
		this.complianceStatus.setAttribute('aria-label', statusText);
	}

	convertDateFormat(dateStr) {
		if (!dateStr) return null;
		// Check if already in yyyy-mm-dd format
		if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
			return dateStr;
		}
		// Convert dd.mm.yyyy to yyyy-mm-dd
		const parts = dateStr.split('.');
		if (parts.length === 3) {
			const day = parts[0].padStart(2, '0');
			const month = parts[1].padStart(2, '0');
			const year = parts[2];
			return `${year}-${month}-${day}`;
		}
		return dateStr;
	}

	setupFormValidation() {
		// No special setup needed - validation happens on change
	}

	validateTimes() {
		// Update break time hidden inputs before validation
		if (this.breaksContainer) {
			const breakEntries = this.breaksContainer.querySelectorAll('.break-entry');
				breakEntries.forEach((breakEntry) => {
					const breakIndex = breakEntry.getAttribute('data-break-index');
					if (breakIndex === null) return;
					
					const breakStartHour = breakEntry.querySelector('.break-start-time-hour');
					const breakStartMinute = breakEntry.querySelector('.break-start-time-minute');
					const breakStartHidden = breakEntry.querySelector('input.break-start-time[data-break-index="' + breakIndex + '"]');
					
					const breakEndHour = breakEntry.querySelector('.break-end-time-hour');
					const breakEndMinute = breakEntry.querySelector('.break-end-time-minute');
					const breakEndHidden = breakEntry.querySelector('input.break-end-time[data-break-index="' + breakIndex + '"]');
					
					if (breakStartHour && breakStartMinute && breakStartHidden) {
						const startHour = breakStartHour.value || '';
						const startMinute = breakStartMinute.value || '';
						if (startHour && startMinute) {
							breakStartHidden.value = `${startHour}:${startMinute}`;
						} else {
							breakStartHidden.value = '';
						}
					}
					
					if (breakEndHour && breakEndMinute && breakEndHidden) {
						const endHour = breakEndHour.value || '';
						const endMinute = breakEndMinute.value || '';
						if (endHour && endMinute) {
							breakEndHidden.value = `${endHour}:${endMinute}`;
						} else {
							breakEndHidden.value = '';
						}
					}
				});
		}
	}

	setupFormSubmission() {
		if (!this.form) return;

		this.form.addEventListener('submit', (e) => {
			e.preventDefault();

			if (this.validateForm()) {
				this.submitForm();
			}
		});
	}

	validateForm() {
		// Prevent double submission
		if (this.formSubmitted) {
			return false;
		}

		// Always start with a clean validation state
		this.clearValidationErrors();

		// If auto-breaks are enabled, ensure they're created before validation
		if (this.shouldRunAutoBreakCalculation()) {
			this.handleAutoBreakCalculation({ notify: false });
			
			// Clear any previous validation errors on break fields
			const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
			breakEntries.forEach((breakEntry) => {
				// Only clear validation on manual break entries (not auto-generated ones)
				if (!breakEntry.hasAttribute('data-auto-break')) {
					const startHour = breakEntry.querySelector('.break-start-time-hour');
					const startMinute = breakEntry.querySelector('.break-start-time-minute');
					const endHour = breakEntry.querySelector('.break-end-time-hour');
					const endMinute = breakEntry.querySelector('.break-end-time-minute');
					
					[startHour, startMinute, endHour, endMinute].forEach((field) => {
						if (field) {
							field.setCustomValidity('');
						}
					});
				}
			});
		}

		// Only validate the absolute minimum required fields
		let isValid = true;

		// Validate date
		if (!this.validateDate()) {
			isValid = false;
		}

		// Validate start time (must have hour and minute)
		if (!this.startTimeHour?.value || !this.startTimeMinute?.value ||
			this.startTimeHour.value === '--' || this.startTimeMinute.value === '--') {
			if (this.startTimeHour) {
				this.startTimeHour.setCustomValidity(t('startTimeRequired'));
				this.startTimeHour.reportValidity();
			}
			isValid = false;
		}

		// Validate end time (must have hour and minute)
		if (!this.endTimeHour?.value || !this.endTimeMinute?.value ||
			this.endTimeHour.value === '--' || this.endTimeMinute.value === '--') {
			if (this.endTimeHour) {
				this.endTimeHour.setCustomValidity(t('endTimeRequired'));
				this.endTimeHour.reportValidity();
			}
			isValid = false;
		}

		// Validate overall work duration and break totals
		if (!this.validateWorkDuration()) {
			isValid = false;
		}

		if (!this.validateJustification()) {
			isValid = false;
		}

		return isValid;
	}

	/**
	 * Validate that the overall work duration is reasonable and that breaks
	 * do not exceed the total work time.
	 *
	 * @returns {boolean} true if the current work/break configuration is valid
	 */
	validateWorkDuration() {
		// We need a valid date and start/end times to perform this check
		const dateStr = this.convertDateFormat(this.dateInput ? this.dateInput.value : '');
		if (!dateStr || !this.startTimeHidden || !this.endTimeHidden) {
			return true;
		}

		const startTime = this.startTimeHidden.value;
		const endTime = this.endTimeHidden.value;
		if (!startTime || !endTime || startTime === '00:00' || endTime === '00:00') {
			return true;
		}

		try {
			const startDateTime = new Date(dateStr + 'T' + startTime);
			let endDateTime = new Date(dateStr + 'T' + endTime);

			if (isNaN(startDateTime.getTime()) || isNaN(endDateTime.getTime())) {
				return true;
			}

			// Handle overnight work
			if (endDateTime < startDateTime) {
				endDateTime.setDate(endDateTime.getDate() + 1);
			}

			const workDurationMs = endDateTime - startDateTime;

			if (workDurationMs <= 0) {
				if (this.endTimeHour) {
					this.endTimeHour.setCustomValidity(t('endAfterStart'));
					this.endTimeHour.reportValidity();
				}
				return false;
			}

			// Check for unreasonably short work periods (less than 15 minutes)
			const minWorkDurationMs = 15 * 60 * 1000; // 15 minutes
			if (workDurationMs < minWorkDurationMs) {
				if (this.endTimeHour) {
					this.endTimeHour.setCustomValidity(t('workMin15'));
					this.endTimeHour.reportValidity();
				}
				return false;
			}

			// Check for unreasonably long work periods (more than 16 hours)
			const maxWorkDurationMs = 16 * 60 * 60 * 1000; // 16 hours
			if (workDurationMs > maxWorkDurationMs) {
				if (this.endTimeHour) {
					this.endTimeHour.setCustomValidity(t('workMax16'));
					this.endTimeHour.reportValidity();
				}
				return false;
			}

			// Check if breaks exceed work time
			const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];
			let totalBreakMs = 0;

			breakEntries.forEach((breakEntry) => {
				const breakStartInput = breakEntry.querySelector('input.break-start-time');
				const breakEndInput = breakEntry.querySelector('input.break-end-time');

				if (breakStartInput && breakEndInput && breakStartInput.value && breakEndInput.value) {
					try {
						const breakStart = new Date(dateStr + 'T' + breakStartInput.value);
						let breakEnd = new Date(dateStr + 'T' + breakEndInput.value);
						if (breakEnd < breakStart) breakEnd.setDate(breakEnd.getDate() + 1);
						totalBreakMs += (breakEnd - breakStart);
					} catch (error) {
						// Skip invalid breaks
					}
				}
			});

			if (totalBreakMs >= workDurationMs) {
				if (this.startTimeHour) {
					this.startTimeHour.setCustomValidity(t('breaksExceedWork'));
					this.startTimeHour.reportValidity();
				}
				return false;
			}

			const netWorkingHours = Math.max(0, (workDurationMs - totalBreakMs) / (1000 * 60 * 60));
			const Validation = window.ArbeitszeitCheckValidation || window.ArbeitszeitCheck?.Validation;
			if (Validation && typeof Validation.evaluateBreakCompliance === 'function') {
				const portions = this.collectBreakPortionMinutes(dateStr);
				const evalResult = Validation.evaluateBreakCompliance(portions, netWorkingHours);
				if (!evalResult.ok) {
					const msg = evalResult.splitInvalid
						? (t('complianceBreakSplitInvalid') || evalResult.message)
						: (t('complianceBreakNotMet') || evalResult.message);
					const firstBreakSelect = this.breaksContainer
						? this.breaksContainer.querySelector('.break-entry select')
						: null;
					const target = firstBreakSelect || this.startTimeHour;
					if (target) {
						target.setCustomValidity(String(msg));
						target.reportValidity();
					}
					return false;
				}
			}

			// For part-time work (under first break tier), breaks are optional.
			const firstTierHours = (Validation && typeof Validation.getBreakTiers === 'function'
				? Validation.getBreakTiers()
				: [{ afterHours: 6 }])[0]?.afterHours ?? 6;
			if (netWorkingHours <= firstTierHours && totalBreakMs > 0) {
				console.info('Breaks added for short shift - this is allowed but not required');
			}
		} catch (error) {
			console.warn('Error validating work duration:', error);
			return false;
		}

		return true;
	}

	updateBreakRequirementIndicator(workingHours) {
		if (!this.breakRequirementIndicator || !this.breakRequirementText) return;

		if (workingHours <= 0) {
			this.breakRequirementIndicator.hidden = true;
			return;
		}

		const requiredMinutes = this.resolveRequiredBreakMinutes(workingHours);

		let requirementText = '';
		if (requiredMinutes <= 0) {
			requirementText = t('breakRequiredNone');
		} else {
			requirementText = t('breakRequired' + requiredMinutes)
				|| (requiredMinutes + ' minutes break required');
		}

		if (requirementText) {
			this.breakRequirementText.textContent = requirementText;
			this.breakRequirementIndicator.hidden = false;
		} else {
			this.breakRequirementIndicator.hidden = true;
		}
	}

	clearValidationErrors() {
		// Clear validation on break inputs
		if (this.breaksContainer) {
			const breakSelects = this.breaksContainer.querySelectorAll('select');
			breakSelects.forEach(select => {
				select.setCustomValidity('');
			});
		}

		// Clear hidden input validation
		if (this.startTimeHidden) this.startTimeHidden.setCustomValidity('');
		if (this.endTimeHidden) this.endTimeHidden.setCustomValidity('');
	}

	async submitForm() {
		const submitBtn = this.form.querySelector('button[type="submit"]');
		const originalText = submitBtn ? submitBtn.textContent : '';

		try {
			// First ensure all hidden inputs are up to date
			this.updateAllHiddenInputs();

			// Calculate and ensure auto-breaks are in place before submission
			if (this.shouldRunAutoBreakCalculation()) {
				this.ensureAutoBreaksForSubmission();
			}

			// Update hidden inputs again after auto-break calculation
			this.updateAllHiddenInputs();

			const formData = new FormData(this.form);
			const dateInputValue = formData.get('date');
			const startTimeValue = formData.get('startTime');
			const endTimeValue = formData.get('endTime');

			// Validate required fields exist
			if (!dateInputValue || !startTimeValue || !endTimeValue) {
				throw new Error('Missing required form fields');
			}

			// Validate date format
			const date = this.convertDateFormat(dateInputValue.trim());
			if (!date) {
				throw new Error('Invalid date format');
			}

			// Validate time formats
			const timeRegex = /^([01]\d|2[0-3]):([0-5]\d)$/;
			if (!timeRegex.test(startTimeValue) || !timeRegex.test(endTimeValue)) {
				throw new Error('Invalid time format');
			}

			// Prepare data for submission
			const data = {
				date: date,
				startTime: startTimeValue,
				endTime: endTimeValue,
				description: (formData.get('description') || '').trim()
			};

			if (this.manualTimeEntriesRequireApproval || this.justificationTextarea) {
				data.justification = this.getJustificationValue();
			}

			const projectField = formData.get('projectCheckProjectId');
			if (projectField !== null && String(projectField).trim() !== '') {
				data.projectCheckProjectId = String(projectField).trim();
			}

			// Process breaks - include all valid breaks (both manual and auto-generated)
			const breaks = [];
			const breakEntries = this.breaksContainer ? this.breaksContainer.querySelectorAll('.break-entry') : [];

			breakEntries.forEach((breakEntry) => {
				const breakIndex = breakEntry.getAttribute('data-break-index');
				const startValue = formData.get(`breaks[${breakIndex}][start]`);
				const endValue = formData.get(`breaks[${breakIndex}][end]`);

				// Include any break with valid start/end times
				if (startValue && endValue &&
					startValue !== '00:00' && endValue !== '00:00' &&
					startValue !== '' && endValue !== '') {
							breaks.push({
						start_time: startValue,
						end_time: endValue
							});
				}
			});

			if (breaks.length > 0) {
				data.breaks = JSON.stringify(breaks);
			}

			// Set loading state
			this.setSubmitLoadingState(submitBtn, true);

			// Submit the form with timeout
			const url = this.formConfig.submitUrl || '';

			const controller = new AbortController();
			const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

			const response = await fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) || (document.querySelector('head') && document.querySelector('head').getAttribute('data-requesttoken')) || ''
				},
				body: JSON.stringify(data),
				signal: controller.signal
			});

			clearTimeout(timeoutId);

			let result;
			try {
				result = await parseJsonFromTimeEntryResponse(response);
			} catch (parseError) {
				if (parseError && parseError.message === 'HTML_RESPONSE') {
					throw parseError;
				}
				if (parseError && (parseError.message === 'INVALID_JSON' || parseError.message === 'EMPTY_RESPONSE')) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`);
				}
				throw new Error(`HTTP ${response.status}: ${response.statusText}`);
			}

			if (!response.ok) {
				const apiErrorMessage = (result && (result.error || result.message)) || '';
				if (apiErrorMessage) {
					throw new Error(`API_ERROR:${apiErrorMessage}`);
				}
				throw new Error(`HTTP ${response.status}: ${response.statusText}`);
			}

			if (result.success) {
				// Success - show message then redirect
				const successMsg = result.message || t('savedSuccess');
				if (window.OC && OC.Notification) {
					OC.Notification.showTemporary(successMsg, {
								type: 'success',
						timeout: 3000
					});
				}

				// Redirect after a short delay to show the success message
				setTimeout(() => {
					window.location.href = this.formConfig.redirectUrl || '';
				}, 1000);

			} else {
				// Server returned success=false with a JSON body
				const errorMsg = (result.error || result.message) || t('saveError');
				this.showErrorNotification(errorMsg);
				this.resetSubmitButton(submitBtn, originalText);
			}

		} catch (error) {
			console.error('Form submission error:', error);

			let errorMsg;
			if (error.name === 'AbortError') {
				errorMsg = t('timeoutError');
			} else if (error.message.startsWith('API_ERROR:')) {
				// Detailed API error coming from backend JSON (e.g. overlap, compliance violations)
				errorMsg = error.message.substring('API_ERROR:'.length);
			} else if (error.message === 'HTML_RESPONSE') {
				errorMsg = t('htmlResponseError');
			} else if (error.message.includes('HTTP')) {
				errorMsg = t('serverError');
			} else if (error.message.includes('required form fields')) {
				errorMsg = t('missingFieldsError');
			} else if (error.message.includes('date format')) {
				errorMsg = t('invalidDateError');
			} else if (error.message.includes('time format')) {
				errorMsg = t('invalidTimesError');
			} else {
				errorMsg = t('networkError');
			}

			this.showErrorNotification(errorMsg);
			this.resetSubmitButton(submitBtn, originalText);
		}
	}

	updateAllHiddenInputs() {
		// Ensure all hidden time inputs are updated from their select elements
		if (this.startTimeHour && this.startTimeMinute && this.startTimeHidden) {
			const startHour = this.startTimeHour.value || '00';
			const startMinute = this.startTimeMinute.value || '00';
			this.startTimeHidden.value = `${startHour}:${startMinute}`;
		}

		if (this.endTimeHour && this.endTimeMinute && this.endTimeHidden) {
			const endHour = this.endTimeHour.value || '00';
			const endMinute = this.endTimeMinute.value || '00';
			this.endTimeHidden.value = `${endHour}:${endMinute}`;
		}

		// Update break hidden inputs
		if (this.breaksContainer) {
			const breakEntries = this.breaksContainer.querySelectorAll('.break-entry');
			breakEntries.forEach((breakEntry) => {
				const breakIndex = breakEntry.getAttribute('data-break-index');
				if (!breakIndex) return;

				const startHour = breakEntry.querySelector('.break-start-time-hour');
				const startMinute = breakEntry.querySelector('.break-start-time-minute');
				const startHidden = breakEntry.querySelector(`input.break-start-time[data-break-index="${breakIndex}"]`);

				const endHour = breakEntry.querySelector('.break-end-time-hour');
				const endMinute = breakEntry.querySelector('.break-end-time-minute');
				const endHidden = breakEntry.querySelector(`input.break-end-time[data-break-index="${breakIndex}"]`);

				if (startHour && startMinute && startHidden) {
					const hour = startHour.value || '00';
					const minute = startMinute.value || '00';
					startHidden.value = `${hour}:${minute}`;
				}

				if (endHour && endMinute && endHidden) {
					const hour = endHour.value || '00';
					const minute = endMinute.value || '00';
					endHidden.value = `${hour}:${minute}`;
				}
			});
		}
	}

	ensureAutoBreaksForSubmission() {
		if (!this.shouldRunAutoBreakCalculation()) {
			return;
		}
		// Reuse live calculation so submission matches what the user saw in the summary
		this.handleAutoBreakCalculation({ notify: false });
	}

	setSubmitLoadingState(submitBtn, loading) {
		if (!submitBtn) return;

		this.formSubmitted = loading;

		if (loading) {
			submitBtn.disabled = true;
			submitBtn.textContent = t('submitting');
			submitBtn.setAttribute('aria-busy', 'true');
		} else {
			submitBtn.disabled = false;
			submitBtn.removeAttribute('aria-busy');
		}
	}

	resetSubmitButton(submitBtn, originalText) {
		this.setSubmitLoadingState(submitBtn, false);
		if (submitBtn && originalText) {
						submitBtn.textContent = originalText;
		}
	}

	showErrorNotification(message) {
		if (window.AzcMessaging && typeof window.AzcMessaging.showError === 'function') {
			window.AzcMessaging.showError(message);
			return;
		}
		if (window.ArbeitszeitCheckMessaging && typeof window.ArbeitszeitCheckMessaging.showError === 'function') {
			window.ArbeitszeitCheckMessaging.showError(message);
			return;
		}
		if (window.OC && OC.Notification) {
			OC.Notification.showTemporary(message, {
				type: 'error',
				timeout: 5000
			});
			return;
		}
		const region = document.getElementById('azc-alert-region');
		if (region) {
			region.textContent = String(message);
		}
	}
}

	function initTimeEntryFormDatepickers(form) {
		const dp = window.ArbeitszeitCheckDatepicker;
		if (!dp) {
			return;
		}
		const root = form || document.getElementById('time-entry-form');
		if (!root) {
			return;
		}
		if (typeof dp.initInRoot === 'function') {
			dp.initInRoot(root);
			return;
		}
		if (typeof dp.initializeDatepicker === 'function') {
			const dateInput = document.getElementById('entry-date');
			if (dateInput && dateInput.dataset.datepickerInit !== '1') {
				dp.initializeDatepicker(dateInput, {});
			}
		}
	}

	function initTimeEntryForm() {
		const form = document.getElementById('time-entry-form');
		if (!form) {
			return;
		}
		initTimeEntryFormDatepickers(form);
		try {
			new TimeEntryFormManager();
		} catch (error) {
			console.error('Failed to initialize TimeEntryFormManager:', error);
			const errorMsg = document.createElement('div');
			errorMsg.className = 'form-error';
			const errorStrong = document.createElement('strong');
			errorStrong.textContent = t('initFailed');
			errorMsg.appendChild(errorStrong);
			form.insertBefore(errorMsg, form.firstChild);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initTimeEntryForm);
	} else {
		initTimeEntryForm();
	}

	window.ArbeitszeitCheckTimeEntryForm = {
		TimeEntryFormManager: TimeEntryFormManager,
		parseJsonFromTimeEntryResponse: parseJsonFromTimeEntryResponse,
	};
})();
