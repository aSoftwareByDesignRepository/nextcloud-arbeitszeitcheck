/**
 * Admin notification settings.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const Utils = window.ArbeitszeitCheckUtils || {};
	const Messaging = window.ArbeitszeitCheckMessaging || {};

	function $(selector, context) {
		if (Utils.$) {
			return Utils.$(selector, context);
		}
		const root = context || document;
		return root.querySelector(selector);
	}

	function normalizeRecipients(raw) {
		const parts = String(raw || '')
			.split(',')
			.map((entry) => entry.trim().toLowerCase())
			.filter((entry) => entry.length > 0);
		const unique = [];
		const seen = new Set();
		parts.forEach((entry) => {
			if (!seen.has(entry)) {
				seen.add(entry);
				unique.push(entry);
			}
		});
		return unique;
	}

	function collectMatrix(form, matrixMeta) {
		const matrix = {};
		(matrixMeta.absenceTypes || []).forEach((type) => {
			const typeKey = String(type.key || '');
			if (typeKey === '') {
				return;
			}
			matrix[typeKey] = {};
			(matrixMeta.eventTypes || []).forEach((event) => {
				const eventKey = String(event.key || '');
				if (eventKey === '') {
					return;
				}
				const selector = `input[name="matrix[${typeKey}][${eventKey}]"]`;
				const input = form.querySelector(selector);
				matrix[typeKey][eventKey] = !!(input && input.checked);
			});
		});
		return matrix;
	}

	function setLiveMessage(liveRegion, message, type) {
		if (!liveRegion) {
			return;
		}
		liveRegion.textContent = message || '';
		liveRegion.classList.remove('admin-notifications-live--error', 'admin-notifications-live--success');
		liveRegion.setAttribute('role', type === 'error' ? 'alert' : 'status');
		if (type === 'error') {
			liveRegion.classList.add('admin-notifications-live--error');
		} else if (type === 'success') {
			liveRegion.classList.add('admin-notifications-live--success');
		}
		// Do not scrollIntoView: announcements must not yank the viewport away
		// from the control the admin just used.
	}

	function bindDependentBlock(toggle, blockId) {
		const block = document.getElementById(blockId);
		if (!toggle || !block) {
			return;
		}
		const sync = function () {
			const on = !!toggle.checked;
			block.setAttribute('data-settings-disabled', on ? 'false' : 'true');
			// Keep controls enabled for FormData/DOM reads so Save cannot wipe sibling
			// thresholds / payout prefs with server defaults when the master toggle is off.
			// Interaction is blocked via CSS pointer-events + aria-disabled.
			block.querySelectorAll('input, textarea, select, button').forEach((el) => {
				if (el === toggle) {
					return;
				}
				el.disabled = false;
				el.setAttribute('aria-disabled', on ? 'false' : 'true');
				if (!on) {
					el.setAttribute('tabindex', '-1');
				} else {
					el.removeAttribute('tabindex');
				}
			});
		};
		toggle.addEventListener('change', sync);
		sync();
	}

	/**
	 * Bachus Q2: anniversary mode reuses the month field as "months after anniversary"
	 * and hides the calendar day control.
	 */
	function bindVacationYearCarryoverUi(form) {
		const radios = form.querySelectorAll('input[name="vacationYearMode"]');
		if (!radios.length) {
			return;
		}
		const monthLabel = document.getElementById('vacationCarryoverExpiryMonth-label');
		const dayWrap = document.getElementById('vacationCarryoverExpiryDay-wrap');
		const dayInput = document.getElementById('vacationCarryoverExpiryDay');
		const calendarIntro = document.getElementById('vacation-carryover-expiry-intro');
		const anniversaryIntro = document.getElementById('vacation-carryover-expiry-intro-anniversary');
		const calendarHelp = document.getElementById('vacation-carryover-expiry-help');
		const anniversaryHelp = document.getElementById('vacation-carryover-expiry-help-anniversary');
		const subsection = form.querySelector('[data-vacation-year-mode]');
		const missingHire = document.getElementById('vacation-year-missing-hire');
		const missingHireAckWrap = document.getElementById('vacation-year-missing-hire-ack-wrap');
		const missingHireAck = document.getElementById('vacationYearMissingHireAcknowledged');

		const sync = function () {
			const checked = form.querySelector('input[name="vacationYearMode"]:checked');
			const anniversary = !!(checked && checked.value === 'anniversary');
			if (subsection) {
				subsection.setAttribute('data-vacation-year-mode', anniversary ? 'anniversary' : 'calendar');
			}
			if (monthLabel) {
				const next = anniversary
					? monthLabel.getAttribute('data-label-anniversary')
					: monthLabel.getAttribute('data-label-calendar');
				if (next) {
					monthLabel.textContent = next;
				}
			}
			if (dayWrap) {
				dayWrap.hidden = anniversary;
			}
			if (dayInput) {
				dayInput.required = !anniversary;
				// Keep enabled so FormData still submits the stored day for calendar mode round-trips.
			}
			if (calendarIntro) {
				calendarIntro.hidden = anniversary;
			}
			if (anniversaryIntro) {
				anniversaryIntro.hidden = !anniversary;
			}
			if (calendarHelp) {
				calendarHelp.hidden = anniversary;
			}
			if (anniversaryHelp) {
				anniversaryHelp.hidden = !anniversary;
			}
			const missingCount = missingHire
				? parseInt(missingHire.getAttribute('data-missing-count') || '0', 10)
				: 0;
			if (missingHire) {
				missingHire.hidden = !(anniversary && missingCount > 0);
			}
			if (missingHireAckWrap) {
				// Always offer ack when hire dates are missing (needed on calendar→anniversary save).
				missingHireAckWrap.hidden = !(missingCount > 0);
			}
			if (missingHireAck && !anniversary) {
				missingHireAck.checked = false;
			}
		};

		radios.forEach((radio) => {
			radio.addEventListener('change', sync);
		});
		sync();
	}

	function collectPremiumPolicy(form) {
		const nightStartEl = form.querySelector('#premium-night-start');
		const nightEndEl = form.querySelector('#premium-night-end');
		const nightWindows = {
			at: { start: '22:00', end: '05:00' },
			de: { start: '23:00', end: '06:00' },
		};
		const presetHint = form.getAttribute('data-premium-night-preset') || 'at';
		const nightFallback = nightWindows[presetHint] || nightWindows.at;
		const nightStart = (nightStartEl && String(nightStartEl.value || '').trim()) || nightFallback.start;
		const nightEnd = (nightEndEl && String(nightEndEl.value || '').trim()) || nightFallback.end;
		const stackingEl = form.querySelector('#premium-stacking');
		const holidayEl = form.querySelector('#premium-holiday-policy');
		let stacking = stackingEl ? String(stackingEl.value || 'max_single_rate') : 'max_single_rate';
		if (['max_single_rate', 'additive_rates', 'tagged_multi'].indexOf(stacking) === -1) {
			stacking = 'max_single_rate';
		}
		let holidayPolicy = holidayEl ? String(holidayEl.value || 'treat_as_sunday') : 'treat_as_sunday';
		if (holidayPolicy !== 'treat_as_sunday' && holidayPolicy !== 'ignore') {
			holidayPolicy = 'treat_as_sunday';
		}
		const defs = [
			{ id: 'overtime_base', label: 'Overtime above daily target', applies_to: 'hours_above_daily_or_weekly_threshold', threshold_ref: 'model_net_daily', onSel: '#premium-cat-ot-on', rateSel: '#premium-cat-ot-rate' },
			{ id: 'sunday', label: 'Sunday', applies_to: 'weekday', weekdays: ['sun'], onSel: '#premium-cat-sun-on', rateSel: '#premium-cat-sun-rate' },
			{ id: 'saturday', label: 'Saturday', applies_to: 'weekday', weekdays: ['sat'], onSel: '#premium-cat-sat-on', rateSel: '#premium-cat-sat-rate' },
			{ id: 'night', label: 'Night', applies_to: 'time_window', window_start: nightStart, window_end: nightEnd, onSel: '#premium-cat-night-on', rateSel: '#premium-cat-night-rate' },
		];
		const categories = [];
		defs.forEach((def) => {
			const on = form.querySelector(def.onSel);
			const rateEl = form.querySelector(def.rateSel);
			const pct = rateEl ? Number(rateEl.value) : 0;
			const rate = Number.isFinite(pct) ? Math.max(0, Math.min(300, pct)) / 100 : 0;
			const row = {
				id: def.id,
				label: def.label,
				rate: rate,
				enabled: !!(on && on.checked),
				applies_to: def.applies_to,
			};
			if (def.weekdays) {
				row.weekdays = def.weekdays;
			}
			if (def.window_start) {
				row.window_start = def.window_start;
				row.window_end = def.window_end;
			}
			if (def.threshold_ref) {
				row.threshold_ref = def.threshold_ref;
			}
			categories.push(row);
		});
		return {
			version: 1,
			currency_mode: 'hours_only',
			stacking: stacking,
			holiday_policy: holidayPolicy,
			categories: categories,
		};
	}

	function collectDatevLohnartPremiumMap(form) {
		const defs = [
			{ id: 'overtime_base', sel: '#premium-cat-ot-datev' },
			{ id: 'sunday', sel: '#premium-cat-sun-datev' },
			{ id: 'saturday', sel: '#premium-cat-sat-datev' },
			{ id: 'night', sel: '#premium-cat-night-datev' },
		];
		const map = {};
		defs.forEach((def) => {
			const el = form.querySelector(def.sel);
			const code = el ? String(el.value || '').trim() : '';
			if (code !== '') {
				map[def.id] = code;
			}
		});
		return map;
	}

	function applyPremiumPreset(form, kind) {
		const rates = {
			simple: { ot: 50, sun: 100, sat: 50, night: 50, nightStart: '22:00', nightEnd: '05:00', satOn: false },
			at: { ot: 50, sun: 100, sat: 50, night: 50, nightStart: '22:00', nightEnd: '05:00', satOn: true },
			de: { ot: 50, sun: 100, sat: 50, night: 50, nightStart: '23:00', nightEnd: '06:00', satOn: true },
			blank: { ot: 0, sun: 0, sat: 0, night: 0, nightStart: '22:00', nightEnd: '05:00', satOn: false },
			custom: { ot: 50, sun: 100, sat: 50, night: 50, nightStart: '22:00', nightEnd: '05:00', satOn: false },
		};
		const r = rates[kind] || rates.simple;
		form.setAttribute('data-premium-night-preset', kind === 'de' ? 'de' : 'at');
		const blank = kind === 'blank';
		const map = [
			['#premium-cat-ot-on', '#premium-cat-ot-rate', r.ot, !blank],
			['#premium-cat-sun-on', '#premium-cat-sun-rate', r.sun, !blank],
			['#premium-cat-sat-on', '#premium-cat-sat-rate', r.sat, !blank && !!r.satOn],
			['#premium-cat-night-on', '#premium-cat-night-rate', r.night, !blank],
		];
		map.forEach(([onSel, rateSel, pct, on]) => {
			const onEl = form.querySelector(onSel);
			const rateEl = form.querySelector(rateSel);
			if (onEl) {
				onEl.checked = !!on;
			}
			if (rateEl) {
				rateEl.value = String(pct);
			}
		});
		const nightStart = form.querySelector('#premium-night-start');
		const nightEnd = form.querySelector('#premium-night-end');
		if (nightStart) {
			nightStart.value = r.nightStart;
		}
		if (nightEnd) {
			nightEnd.value = r.nightEnd;
		}
		const stacking = form.querySelector('#premium-stacking');
		if (stacking) {
			stacking.value = 'max_single_rate';
		}
		const holiday = form.querySelector('#premium-holiday-policy');
		if (holiday) {
			holiday.value = 'treat_as_sunday';
		}
	}

	function setPremiumModeChip(form, mode) {
		const chips = form.querySelectorAll('[data-premium-mode]');
		chips.forEach((chip) => {
			const active = chip.getAttribute('data-premium-mode') === mode;
			chip.setAttribute('aria-checked', active ? 'true' : 'false');
			chip.classList.toggle('is-active', active);
		});
		const picker = form.querySelector('#premium-template-picker');
		if (picker) {
			if (mode === 'template') {
				picker.removeAttribute('hidden');
			} else {
				picker.setAttribute('hidden', 'hidden');
			}
		}
		form.setAttribute('data-premium-mode', mode);
	}

	function bindPremiumUi(form, onDirty) {
		const enabled = form.querySelector('#premiumSurchargesEnabled');
		const panel = form.querySelector('#premium-surcharges-panel');
		const example = form.querySelector('#premium-example');
		const sunOn = form.querySelector('#premium-cat-sun-on');
		const sunRate = form.querySelector('#premium-cat-sun-rate');
		const exampleOff = panel
			? String(panel.getAttribute('data-premium-example-off') || 'Example: turn on Sunday to see a sample.')
			: 'Example: turn on Sunday to see a sample.';
		const exampleOnTpl = panel
			? String(panel.getAttribute('data-premium-example-on') || 'Example: Sunday 2 h → 2.0 h @ __PCT__%')
			: 'Example: Sunday 2 h → 2.0 h @ __PCT__%';

		function updateExample() {
			if (!example) {
				return;
			}
			const on = !sunOn || sunOn.checked;
			const pct = sunRate ? Math.max(0, Math.min(300, Number(sunRate.value) || 0)) : 100;
			if (!on) {
				example.textContent = exampleOff;
				return;
			}
			example.textContent = exampleOnTpl.replace('__PCT__', String(pct));
		}

		const sync = () => {
			if (!panel) {
				return;
			}
			if (enabled && enabled.checked) {
				panel.removeAttribute('hidden');
			} else {
				panel.setAttribute('hidden', 'hidden');
			}
			updateExample();
		};
		if (enabled) {
			enabled.addEventListener('change', () => {
				if (enabled.checked && !form.getAttribute('data-premium-mode')) {
					// First enable in this session → Bachus Simple defaults (OT+Sun+Night).
					applyPremiumPreset(form, 'simple');
					setPremiumModeChip(form, 'simple');
				}
				sync();
				if (typeof onDirty === 'function') {
					onDirty();
				}
			});
			sync();
		}

		form.querySelectorAll('[data-premium-mode]').forEach((chip) => {
			chip.addEventListener('click', () => {
				const mode = chip.getAttribute('data-premium-mode') || 'simple';
				setPremiumModeChip(form, mode);
				if (mode === 'simple') {
					applyPremiumPreset(form, 'simple');
				} else if (mode === 'custom') {
					// Keep current rates editable; do not wipe (granny can tweak).
					setPremiumModeChip(form, 'custom');
				}
				updateExample();
				if (typeof onDirty === 'function') {
					onDirty();
				}
			});
		});

		if (sunOn) {
			sunOn.addEventListener('change', () => {
				setPremiumModeChip(form, 'custom');
				updateExample();
			});
		}
		if (sunRate) {
			sunRate.addEventListener('input', () => {
				setPremiumModeChip(form, 'custom');
				updateExample();
			});
			sunRate.addEventListener('change', () => {
				setPremiumModeChip(form, 'custom');
				updateExample();
			});
		}
		form.querySelectorAll('[data-premium-preset]').forEach((btn) => {
			btn.addEventListener('click', () => {
				applyPremiumPreset(form, btn.getAttribute('data-premium-preset') || 'at');
				setPremiumModeChip(form, 'template');
				updateExample();
				if (typeof onDirty === 'function') {
					onDirty();
				}
			});
		});
		['#premium-cat-ot-on', '#premium-cat-ot-rate', '#premium-cat-sat-on', '#premium-cat-sat-rate',
			'#premium-cat-night-on', '#premium-cat-night-rate'].forEach((sel) => {
			const el = form.querySelector(sel);
			if (!el) {
				return;
			}
			el.addEventListener('change', () => setPremiumModeChip(form, 'custom'));
			el.addEventListener('input', () => setPremiumModeChip(form, 'custom'));
		});
		updateExample();
	}

	function initForm(form) {
		const saveButton = form.querySelector('button[type="submit"]')
			|| document.getElementById(form.id ? form.id.replace(/-form$/, '-save') : '')
			|| form.querySelector('.azc-btn--primary');
		const recipientsField = form.querySelector('#hrRecipients');
		const overtimeRecipientsField = form.querySelector('#overtimeRecipients');
		const liveRegion = form.querySelector('.admin-notifications-live, .azc-admin-policy-live')
			|| document.getElementById(form.id ? form.id.replace(/-form$/, '-live') : '');
		const apiUrl = window.ArbeitszeitCheck && window.ArbeitszeitCheck.adminNotificationsApiUrl;
		const l10n = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n) || {};
		const matrixMeta = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.notificationMatrixMeta) || { absenceTypes: [], eventTypes: [] };
		const hasHr = !!form.querySelector('#hrNotificationsEnabled');
		const hasTraffic = !!form.querySelector('#overtimeTrafficLightEnabled');
		const hasBank = !!form.querySelector('#overtimeBankEnabled');
		const hasPremium = !!form.querySelector('#premiumSurchargesEnabled');
		const hasVacation = !!form.querySelector('input[name="vacationYearMode"]');
		const hasReminders = !!form.querySelector('#missingClockInRemindersEnabled');
		const hasCalendarEmail = !!form.querySelector('#sendIcalApprovedAbsences');
		let formDirty = false;
		let saving = false;

		if (!apiUrl) {
			return;
		}

		const markDirty = function () {
			if (!saving) {
				formDirty = true;
			}
		};
		form.addEventListener('input', markDirty);
		form.addEventListener('change', markDirty);
		window.addEventListener('beforeunload', function (event) {
			if (formDirty && !saving) {
				event.preventDefault();
				event.returnValue = '';
			}
		});

		if (hasPremium) {
			bindPremiumUi(form, markDirty);
		}
		if (hasHr) {
			bindDependentBlock(form.querySelector('#hrNotificationsEnabled'), 'hr-notification-settings');
		}
		if (hasTraffic) {
			bindDependentBlock(form.querySelector('#overtimeTrafficLightEnabled'), 'overtime-trafficlight-settings');
		}
		if (hasBank) {
			bindDependentBlock(form.querySelector('#overtimeBankEnabled'), 'overtime-bank-settings');
		}
		if (hasVacation) {
			bindVacationYearCarryoverUi(form);
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			const isChecked = function (value) {
				return value === 'on' || value === '1' || value === 1 || value === true;
			};
			const domChecked = function (selector) {
				const el = form.querySelector(selector);
				return !!(el && el.checked);
			};
			const domValue = function (selector, fallback) {
				const el = form.querySelector(selector);
				if (!el) {
					return fallback;
				}
				return String(el.value ?? fallback);
			};
			const formData = new FormData(form);
			const payload = {
				policyScope: String(form.getAttribute('data-policy-scope') || 'notifications'),
			};

			if (hasHr) {
				const enabled = domChecked('#hrNotificationsEnabled');
				const recipients = normalizeRecipients(recipientsField ? recipientsField.value : '');
				if (enabled && recipients.length === 0) {
					const msg = l10n.invalidRecipients || 'Please enter at least one valid recipient email address.';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					if (recipientsField) {
						recipientsField.focus();
					}
					return;
				}
				// Prefer hrNotificationsEnabled; keep enabled as legacy alias for older clients.
				payload.hrNotificationsEnabled = enabled;
				payload.enabled = enabled;
				payload.recipients = recipients;
				payload.matrix = collectMatrix(form, matrixMeta);
			}

			if (hasTraffic) {
				const overtimeTrafficLightEnabled = domChecked('#overtimeTrafficLightEnabled');
				const overtimeRecipients = normalizeRecipients(overtimeRecipientsField ? overtimeRecipientsField.value : '');
				const overtimeYellowOver = Number(domValue('#overtimeYellowOver', '5').replace(',', '.'));
				const overtimeRedOver = Number(domValue('#overtimeRedOver', '15').replace(',', '.'));
				const overtimeYellowUnder = Number(domValue('#overtimeYellowUnder', '5').replace(',', '.'));
				const overtimeRedUnder = Number(domValue('#overtimeRedUnder', '15').replace(',', '.'));
				if (overtimeTrafficLightEnabled && overtimeRecipients.length === 0) {
					const msg = l10n.invalidBalanceTrafficLightRecipients || 'Please enter at least one valid balance traffic light recipient email address (overtime/undertime).';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					if (overtimeRecipientsField) {
						overtimeRecipientsField.focus();
					}
					return;
				}
				if (!Number.isFinite(overtimeYellowOver) || !Number.isFinite(overtimeRedOver) || !Number.isFinite(overtimeYellowUnder) || !Number.isFinite(overtimeRedUnder)) {
					const msg = l10n.invalidThresholdValues || 'Threshold values must be valid numbers.';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					return;
				}
				if (overtimeYellowOver > overtimeRedOver || overtimeYellowUnder > overtimeRedUnder) {
					const msg = l10n.invalidThresholdOrder || 'Yellow thresholds must be less than or equal to red thresholds.';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					return;
				}
				payload.overtimeTrafficLightEnabled = overtimeTrafficLightEnabled;
				payload.overtimeRecipients = overtimeRecipients;
				payload.overtimeMatrix = {
					over: {
						yellow: domChecked('input[name="overtimeMatrix[over][yellow]"]'),
						red: domChecked('input[name="overtimeMatrix[over][red]"]'),
					},
					under: {
						yellow: domChecked('input[name="overtimeMatrix[under][yellow]"]'),
						red: domChecked('input[name="overtimeMatrix[under][red]"]'),
					},
				};
				payload.overtimeYellowOver = overtimeYellowOver;
				payload.overtimeRedOver = overtimeRedOver;
				payload.overtimeYellowUnder = overtimeYellowUnder;
				payload.overtimeRedUnder = overtimeRedUnder;
			}

			if (hasBank) {
				const bankYellowPct = parseInt(domValue('#overtimeBankYellowPercent', '80'), 10);
				const bankRedPct = parseInt(domValue('#overtimeBankRedPercent', '95'), 10);
				if (bankYellowPct > bankRedPct) {
					const msg = l10n.invalidBankFillOrder || 'Bank fill yellow percent must be less than or equal to red percent.';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					form.querySelector('#overtimeBankYellowPercent')?.focus();
					return;
				}
				payload.overtimeBankEnabled = domChecked('#overtimeBankEnabled');
				payload.overtimeBankMaxHours = Number(domValue('#overtimeBankMaxHours', '100').replace(',', '.'));
				payload.overtimeBankYellowPercent = bankYellowPct;
				payload.overtimeBankRedPercent = bankRedPct;
				payload.overtimePayoutNotifyInApp = domChecked('#overtimePayoutNotifyInApp');
				payload.overtimePayoutNotifyEmail = domChecked('#overtimePayoutNotifyEmail');
				payload.overtimeBlockMonthClosurePendingPayout = domChecked('#overtimeBlockMonthClosurePendingPayout');
			}

			if (hasReminders) {
				payload.missingClockInRemindersEnabled = isChecked(formData.get('missingClockInRemindersEnabled'));
			}

			if (hasCalendarEmail) {
				payload.sendIcalApprovedAbsences = isChecked(formData.get('sendIcalApprovedAbsences'));
				payload.sendIcalToSubstitute = isChecked(formData.get('sendIcalToSubstitute'));
				payload.sendIcalToManagers = isChecked(formData.get('sendIcalToManagers'));
				payload.sendEmailSubstitutionRequest = isChecked(formData.get('sendEmailSubstitutionRequest'));
				payload.sendEmailSubstituteApprovedToEmployee = isChecked(formData.get('sendEmailSubstituteApprovedToEmployee'));
				payload.sendEmailSubstituteApprovedToManager = isChecked(formData.get('sendEmailSubstituteApprovedToManager'));
			}

			if (hasVacation) {
				const vacationCarryoverMaxDaysVal = String(formData.get('vacationCarryoverMaxDays') || '').trim();
				if (vacationCarryoverMaxDaysVal !== '') {
					const parsedMax = Number(vacationCarryoverMaxDaysVal.replace(',', '.'));
					const maxEl = form.querySelector('#vacationCarryoverMaxDays');
					const statusEl = document.getElementById('vacation-unit-status');
					const hoursMode = (maxEl && maxEl.getAttribute('data-unit') === 'hours')
						|| (statusEl && statusEl.getAttribute('data-current-unit') === 'hours');
					const ceiling = hoursMode ? 4000 : 366;
					if (!Number.isFinite(parsedMax) || parsedMax < 0 || parsedMax > ceiling) {
						const msg = hoursMode
							? (l10n.invalidCarryoverMaxHours || 'Maximum carryover hours must be empty (unlimited) or between 0 and 4000')
							: (l10n.invalidCarryoverMaxDays || 'Maximum carryover days must be empty (unlimited) or between 0 and 366');
						Messaging.showError(msg);
						setLiveMessage(liveRegion, msg, 'error');
						maxEl?.focus();
						return;
					}
				}
				const yearModeEl = form.querySelector('input[name="vacationYearMode"]:checked');
				const yearMode = yearModeEl ? String(yearModeEl.value || 'calendar') : 'calendar';
				const missingHireEl = document.getElementById('vacation-year-missing-hire');
				const missingHireAckEl = document.getElementById('vacationYearMissingHireAcknowledged');
				const missingHireN = missingHireEl
					? parseInt(missingHireEl.getAttribute('data-missing-count') || '0', 10)
					: 0;
				if (yearMode === 'anniversary' && missingHireN > 0 && !(missingHireAckEl && missingHireAckEl.checked)) {
					const msg = l10n.vacationYearMissingHireAckRequired
						|| 'Confirm that people without a hire date will have no vacation until a start date is set.';
					Messaging.showError(msg);
					setLiveMessage(liveRegion, msg, 'error');
					if (missingHireAckEl) {
						missingHireAckEl.focus();
					}
					return;
				}
				payload.vacationCarryoverExpiryMonth = parseInt(String(formData.get('vacationCarryoverExpiryMonth') || ''), 10);
				payload.vacationCarryoverExpiryDay = parseInt(String(formData.get('vacationCarryoverExpiryDay') || ''), 10);
				payload.vacationCarryoverMaxDays = vacationCarryoverMaxDaysVal;
				payload.vacationRolloverEnabled = isChecked(formData.get('vacationRolloverEnabled'));
				payload.vacationRolloverIncludeUnusedAnnual = isChecked(formData.get('vacationRolloverIncludeUnusedAnnual'));
				payload.vacationProrationMethod = String(formData.get('vacationProrationMethod') || 'twelfths');
				payload.vacationYearMode = String(formData.get('vacationYearMode') || 'calendar');
				payload.vacationYearMissingHireAcknowledged = isChecked(formData.get('vacationYearMissingHireAcknowledged'));
			}

			if (hasPremium) {
				payload.premiumSurchargesEnabled = isChecked(formData.get('premiumSurchargesEnabled'));
				payload.premiumPolicy = collectPremiumPolicy(form);
				payload.datevLohnartPremiumMap = collectDatevLohnartPremiumMap(form);
			}

			saving = true;
			if (saveButton) {
				saveButton.disabled = true;
				saveButton.setAttribute('aria-busy', 'true');
			}
			setLiveMessage(liveRegion, '', null);

			const busyRetryCodes = {
				VAC_YEAR_MODE_BUSY: true,
				PREMIUM_POLICY_BUSY: true,
				VAC_UNIT_MIGRATE_IN_PROGRESS: true,
			};
			const maxBusyRetries = 2;
			const busyRetryDelayMs = 700;

			const finishSaving = function () {
				saving = false;
				if (saveButton) {
					saveButton.disabled = false;
					saveButton.removeAttribute('aria-busy');
				}
			};

			const handleSaveSuccess = function (response) {
				finishSaving();
				if (response && response.success) {
					formDirty = false;
					const msg = response.message || l10n.notificationsSaved || 'Settings updated successfully';
					Messaging.showSuccess(msg);
					if (hasHr && recipientsField && Array.isArray(payload.recipients)) {
						recipientsField.value = payload.recipients.join(', ');
					}
					if (hasTraffic && overtimeRecipientsField && Array.isArray(payload.overtimeRecipients)) {
						overtimeRecipientsField.value = payload.overtimeRecipients.join(', ');
					}
					setLiveMessage(liveRegion, msg, 'success');
					return;
				}
				const errorMessage = (response && response.error) || l10n.failedToSaveNotifications || 'Failed to save settings';
				Messaging.showError(errorMessage);
				setLiveMessage(liveRegion, errorMessage, 'error');
			};

			const handleSaveError = function (error, attempt) {
				const code = (error && error.data && error.data.code)
					|| (error && error.code)
					|| '';
				if (busyRetryCodes[code] && attempt < maxBusyRetries) {
					const retryMsg = l10n.settingsBusyRetrying
						|| 'Still busy — trying again…';
					setLiveMessage(liveRegion, retryMsg, null);
					window.setTimeout(function () {
						postSave(attempt + 1);
					}, busyRetryDelayMs * (attempt + 1));
					return;
				}
				finishSaving();
				const errorMessage = (error && error.error)
					|| (error && error.data && error.data.error)
					|| l10n.failedToSaveNotifications
					|| 'Failed to save settings';
				Messaging.showError(errorMessage);
				setLiveMessage(liveRegion, errorMessage, 'error');
			};

			const postSave = function (attempt) {
				Utils.ajax(apiUrl, {
					method: 'POST',
					data: payload,
					onSuccess: handleSaveSuccess,
					onError: function (error) {
						handleSaveError(error, attempt);
					},
				});
			};

			postSave(0);
		});
	}

	function init() {
		const forms = document.querySelectorAll('form.admin-policy-settings-form, #admin-notifications-form');
		if (!forms.length) {
			return;
		}
		forms.forEach((form) => {
			if (form.dataset.policyBoundBound === '1') {
				return;
			}
			form.dataset.policyBoundBound = '1';
			initForm(form);
		});
	}

	(function initVacationUnitMigration() {
		// Own l10n binding — this IIFE is outside init(); do not close over init()'s const.
		const l10n = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n) || {};
		const btnApply = document.getElementById('btn-vacation-unit-apply');
		const statusEl = document.getElementById('vacation-unit-migrate-status');
		const errorEl = document.getElementById('vacation-unit-migrate-error');
		const hoursInput = document.getElementById('vacationHoursPerDay');
		const confirmInput = document.getElementById('vacationUnitClientConfirmed');
		const statusCurrent = document.getElementById('vacation-unit-status');
		const btnBanss = document.getElementById('btn-vacation-hours-use-banss');
		const currentUnit = (statusCurrent && statusCurrent.getAttribute('data-current-unit')) || 'days';
		if (!btnApply) {
			return;
		}
		const migrateUrl = (window.ArbeitszeitCheck && window.ArbeitszeitCheck.apiUrl && window.ArbeitszeitCheck.apiUrl.migrateVacationUnit)
			|| (typeof OC !== 'undefined' && OC.generateUrl
				? OC.generateUrl('/apps/arbeitszeitcheck/api/admin/vacation-unit/migrate')
				: '/apps/arbeitszeitcheck/api/admin/vacation-unit/migrate');

		function setMigrateError(msg) {
			if (!errorEl) {
				return;
			}
			if (!msg) {
				errorEl.hidden = true;
				errorEl.textContent = '';
				return;
			}
			errorEl.hidden = false;
			errorEl.textContent = msg;
		}

		function formatWithFactor(template, hoursPerDay) {
			const hoursLabel = Number.isFinite(hoursPerDay)
				? (Math.round(hoursPerDay * 100) / 100).toString()
				: '8';
			return String(template || '').replace(/%s/g, hoursLabel);
		}

		function readHoursPerDay() {
			return hoursInput
				? Number(String(hoursInput.value || '8').replace(',', '.'))
				: 8;
		}

		function runMigrate(targetUnit) {
			if (btnApply && btnApply.getAttribute('aria-busy') === 'true') {
				return;
			}
			setMigrateError('');
			if (statusEl) {
				statusEl.textContent = l10n.vacationUnitMigrating || 'Converting vacation unit…';
			}
			if (btnApply) {
				btnApply.disabled = true;
				btnApply.setAttribute('aria-busy', 'true');
				btnApply.setAttribute('aria-disabled', 'true');
			}
			const hoursPerDay = readHoursPerDay();
			const clientConfirmed = confirmInput ? !!confirmInput.checked : false;
			Utils.ajax(migrateUrl, {
				method: 'POST',
				data: {
					targetUnit: targetUnit,
					hoursPerDay: hoursPerDay,
					clientConfirmed: clientConfirmed,
				},
				onSuccess: function (response) {
					if (response && response.success) {
						const msg = response.message || l10n.vacationUnitMigrated || 'Vacation unit converted successfully.';
						if (statusEl) {
							statusEl.textContent = msg;
						}
						Messaging.showSuccess(msg);
						window.setTimeout(function () {
							window.location.reload();
						}, 800);
						return;
					}
					const err = (response && response.error) || l10n.vacationUnitMigrateFailed || 'Could not convert vacation unit.';
					setMigrateError(err);
					if (statusEl) {
						statusEl.textContent = '';
					}
					Messaging.showError(err);
					if (btnApply) {
						btnApply.removeAttribute('aria-busy');
						syncApplyGate();
					}
				},
				onError: function (error) {
					const err = (error && error.error) || l10n.vacationUnitMigrateFailed || 'Could not convert vacation unit.';
					setMigrateError(err);
					if (statusEl) {
						statusEl.textContent = '';
					}
					Messaging.showError(err);
					if (btnApply) {
						btnApply.removeAttribute('aria-busy');
						syncApplyGate();
					}
				},
			});
		}

		function selectedUnit() {
			const checked = document.querySelector('input[name="vacationUnitChoice"]:checked');
			return checked ? checked.value : currentUnit;
		}

		function syncApplyGate() {
			if (!btnApply) {
				return;
			}
			const target = selectedUnit();
			const needConfirm = target === 'hours' && currentUnit !== 'hours';
			const confirmed = confirmInput ? !!confirmInput.checked : false;
			const blocked = needConfirm && !confirmed;
			// Keep Apply enabled so assistive tech can activate it; validate on click (WCAG).
			btnApply.disabled = false;
			btnApply.removeAttribute('aria-disabled');
			if (confirmInput) {
				confirmInput.setAttribute('aria-invalid', blocked ? 'true' : 'false');
			}
			if (statusEl) {
				if (blocked) {
					statusEl.textContent = l10n.vacationUnitNeedClientConfirm
						|| 'Tick the Employee app confirmation checkbox before converting to hours.';
				} else if (target === currentUnit) {
					statusEl.textContent = l10n.vacationUnitFactorHint
						|| 'Same unit selected. Apply updates the hours-per-day factor only (balances stay as they are).';
				} else {
					statusEl.textContent = '';
				}
			}
			if (!blocked) {
				setMigrateError('');
			}
		}

		async function confirmAndMigrate(targetUnit) {
			const hoursPerDay = readHoursPerDay();
			if (targetUnit === currentUnit) {
				// Persist hours-per-day factor without re-converting balances.
				if (!Number.isFinite(hoursPerDay) || hoursPerDay < 0.25 || hoursPerDay > 24) {
					setMigrateError(l10n.vacationUnitHoursPerDayInvalid
						|| 'Hours per day must be between 0.25 and 24.');
					if (hoursInput) {
						hoursInput.focus();
					}
					return;
				}
				runMigrate(targetUnit);
				return;
			}
			if (!Number.isFinite(hoursPerDay) || hoursPerDay < 0.25 || hoursPerDay > 24) {
				setMigrateError(l10n.vacationUnitHoursPerDayInvalid
					|| 'Hours per day must be between 0.25 and 24.');
				if (hoursInput) {
					hoursInput.focus();
				}
				return;
			}
			if (targetUnit === 'hours') {
				if (confirmInput && !confirmInput.checked) {
					setMigrateError(l10n.vacationUnitNeedClientConfirm
						|| 'Tick the Employee app confirmation checkbox before converting to hours.');
					if (confirmInput) {
						confirmInput.setAttribute('aria-invalid', 'true');
						confirmInput.focus();
					}
					if (statusEl) {
						statusEl.textContent = '';
					}
					return;
				}
				// Fail closed when confirm API missing — never native window.confirm.
				const confirmedHours = await (window.ArbeitszeitCheckUtils?.confirmDestructiveAction?.({
					title: l10n.vacationUnitConfirmTitle || 'Convert vacation unit',
					message: formatWithFactor(
						l10n.vacationUnitConfirmHours
							|| 'Convert all open vacation balances and absences to hours using %s hours per day? This cannot be undone without converting back.',
						hoursPerDay
					),
					variant: 'danger',
					confirmLabel: l10n.vacationUnitConfirmBtn || 'Convert',
				}) ?? Promise.resolve(null));
				if (!confirmedHours) {
					return;
				}
				runMigrate('hours');
				return;
			}
			const confirmedDays = await (window.ArbeitszeitCheckUtils?.confirmDestructiveAction?.({
				title: l10n.vacationUnitConfirmTitle || 'Convert vacation unit',
				message: formatWithFactor(
					l10n.vacationUnitConfirmDays
						|| 'Convert all open vacation balances back to days using %s hours per day?',
					hoursPerDay
				),
				variant: 'danger',
				confirmLabel: l10n.vacationUnitConfirmBtn || 'Convert',
			}) ?? Promise.resolve(null));
			if (!confirmedDays) {
				return;
			}
			runMigrate('days');
		}

		if (typeof window !== 'undefined') {
			window.__ArbeitszeitCheckNotificationsVacationTestables = {
				confirmAndMigrate: confirmAndMigrate,
				runMigrate: runMigrate,
			};
		}

		if (btnBanss && hoursInput) {
			btnBanss.addEventListener('click', function () {
				const raw = btnBanss.getAttribute('data-hours') || '7.7';
				hoursInput.value = raw;
				hoursInput.dispatchEvent(new Event('input', { bubbles: true }));
				hoursInput.focus();
				if (statusEl) {
					statusEl.textContent = l10n.vacationHoursBanssApplied
						|| 'Set conversion factor to 7.7 (BANSS 38.5 ÷ 5).';
				}
			});
		}

		document.querySelectorAll('input[name="vacationUnitChoice"]').forEach(function (radio) {
			radio.addEventListener('change', syncApplyGate);
		});
		if (confirmInput) {
			confirmInput.addEventListener('change', syncApplyGate);
		}
		syncApplyGate();

		if (btnApply) {
			btnApply.addEventListener('click', function (e) {
				e.preventDefault();
				confirmAndMigrate(selectedUnit());
			});
		}
	})();

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
