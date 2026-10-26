/**
 * Datepicker utility for arbeitszeitcheck app
 * CSP-compliant datepicker with dd.mm.yyyy format (European)
 *
 * The input stays typeable: users can enter the date directly
 * (dd.mm.yyyy plus forgiving variants like "1.3.09", "01032009" or
 * ISO "2009-03-01") and the popup calendar offers month + year
 * dropdowns, so distant dates (e.g. a hire date in 2009) take a few
 * clicks instead of month-stepping.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/**
 * Convert dd.mm.yyyy to ISO (yyyy-mm-dd)
 * @param {string} dateString - Date in dd.mm.yyyy format
 * @returns {string} Date in yyyy-mm-dd format
 */
function convertEuropeanToISO(dateString) {
	if (!dateString) return '';
	if (/^\d{2}\.\d{2}\.\d{4}$/.test(dateString)) {
		const parts = dateString.split('.');
		return parts[2] + '-' + parts[1] + '-' + parts[0];
	}
	if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) return dateString;
	return dateString;
}

/**
 * Convert ISO (yyyy-mm-dd) to dd.mm.yyyy
 * @param {string} dateString - Date in yyyy-mm-dd format
 * @returns {string} Date in dd.mm.yyyy format
 */
function convertISOToEuropean(dateString) {
	if (!dateString) return '';
	if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
		const parts = dateString.split('-');
		return parts[2] + '.' + parts[1] + '.' + parts[0];
	}
	if (/^\d{2}\.\d{2}\.\d{4}$/.test(dateString)) return dateString;
	return dateString;
}

function pad2(n) {
	return String(n).padStart(2, '0');
}

function formatEuropean(date) {
	return pad2(date.getDate()) + '.' + pad2(date.getMonth() + 1) + '.' + date.getFullYear();
}

function isoOf(date) {
	return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
}

function startOfDay(date) {
	return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function parseDDMMYYYYToDate(val) {
	if (!val || !/^\d{2}\.\d{2}\.\d{4}$/.test(val)) return null;
	var p = val.split('.');
	return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
}

/**
 * Forgiving date parser for typed input.
 *
 * Accepts the canonical dd.mm.yyyy plus the variants people actually
 * type: "1.3.2009", "1.3.09" (2-digit year, pivoted around now+10y),
 * "01/03/2009", "01-03-2009", "01 03 2009", "01032009", "010309" and
 * ISO "2009-03-01". Returns null for anything else or impossible dates
 * like 31.02.2026 (checked via Date round-trip).
 *
 * @param {string} raw
 * @returns {Date|null}
 */
function parseFlexibleDate(raw) {
	if (raw === null || raw === undefined) return null;
	const v = String(raw).trim();
	if (!v) return null;

	let day = 0;
	let month = 0;
	let year = 0;
	let m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
	if (m) {
		year = parseInt(m[1], 10);
		month = parseInt(m[2], 10);
		day = parseInt(m[3], 10);
	} else {
		m = v.match(/^(\d{1,2})[./\s-](\d{1,2})[./\s-](\d{2}|\d{4})$/);
		if (m) {
			day = parseInt(m[1], 10);
			month = parseInt(m[2], 10);
			year = parseInt(m[3], 10);
		} else {
			m = v.match(/^(\d{2})(\d{2})(\d{4})$/);
			if (m) {
				day = parseInt(m[1], 10);
				month = parseInt(m[2], 10);
				year = parseInt(m[3], 10);
			} else {
				m = v.match(/^(\d{2})(\d{2})(\d{2})$/);
				if (!m) return null;
				day = parseInt(m[1], 10);
				month = parseInt(m[2], 10);
				year = parseInt(m[3], 10);
			}
		}
	}

	if (year < 100) {
		const pivot = (new Date().getFullYear() + 10) % 100;
		year = year <= pivot ? 2000 + year : 1900 + year;
	}
	if (year < 100 || year > 9999) return null;
	if (month < 1 || month > 12 || day < 1 || day > 31) return null;

	const date = new Date(year, month - 1, day);
	if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
		return null;
	}
	return date;
}

function parseMinDateFromElement(el) {
	var minVal = el && el.getAttribute && el.getAttribute('data-datepicker-min');
	if (!minVal || minVal === 'today') return minVal === 'today' ? new Date() : null;
	if (/^\d{2}\.\d{2}\.\d{4}$/.test(minVal)) {
		var p = minVal.split('.');
		return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
	}
	if (/^\d{4}-\d{2}-\d{2}$/.test(minVal)) return new Date(minVal);
	return null;
}

function parseMaxDateFromElement(el) {
	var maxVal = el && el.getAttribute && el.getAttribute('data-datepicker-max');
	if (!maxVal || maxVal === 'today') return maxVal === 'today' ? new Date() : null;
	if (/^\d{2}\.\d{2}\.\d{4}$/.test(maxVal)) {
		var p = maxVal.split('.');
		return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
	}
	if (/^\d{4}-\d{2}-\d{2}$/.test(maxVal)) return new Date(maxVal);
	return null;
}

/**
 * Initialize datepicker on input (dd.mm.yyyy format, calendar popup)
 * @param {HTMLElement|string} input - Input element or selector
 * @param {Object} options - { maxDate, minDate, openOnFocus, getInitialMonth: () => Date|null }
 *   getInitialMonth: when opening, if provided and returns a Date, the calendar shows that month
 * @returns {Object} Datepicker instance
 */
function initializeDatepicker(input, options = {}) {
	const element = typeof input === 'string' ? document.querySelector(input) : input;
	if (!element) {
		console.error('[ArbeitszeitCheck] Datepicker: input not found');
		return null;
	}
	if (element.dataset.datepickerInit === '1' || element.dataset.datepickerInit === 'true') {
		return { open: function () {}, close: function () {} };
	}

	const l10n = window.ArbeitszeitCheck?.l10n || {};
	const l10nKeys = {
		'Open calendar': 'openCalendar',
		'Previous month': 'previousMonth',
		'Next month': 'nextMonth',
		'Choose a date': 'chooseDate',
		'Choose month': 'chooseMonth',
		'Choose year': 'chooseYear',
	};
	const t = function (s, args) {
		const key = l10nKeys[s];
		if (key && l10n[key]) {
			return l10n[key];
		}
		return (typeof window !== 'undefined' && window.t ? window.t('arbeitszeitcheck', s, args) : s);
	};
	const monthNames = [t('January'), t('February'), t('March'), t('April'), t('May'), t('June'),
		t('July'), t('August'), t('September'), t('October'), t('November'), t('December')];
	const dayNames = [t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat'), t('Sun')];

	let selectedDate = parseFlexibleDate(element.value);
	let currentDate = selectedDate ? new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1) : new Date();
	currentDate.setHours(0, 0, 0, 0);
	let focusedDate = selectedDate ? new Date(selectedDate) : null;

	let calendarOpen = false;
	let calendarElement = null;
	let monthSelect = null;
	let yearSelect = null;
	let errorEl = null;
	let suppressFocusOpen = false;
	let onDocKey = null;
	let onDocPointer = null;
	let onDocFocus = null;

	function fieldLocked() {
		return !!(element.disabled || element.readOnly || element.getAttribute('data-datepicker-locked') === '1');
	}

	function currentBounds() {
		var minDate = options.minDate || null;
		if (element.getAttribute && (!minDate || options.useDynamicMin)) {
			var dyn = parseMinDateFromElement(element);
			if (dyn) minDate = dyn;
		}
		var maxDate = options.maxDate || null;
		var dynMax = parseMaxDateFromElement(element);
		if (dynMax) maxDate = dynMax;
		return { min: minDate, max: maxDate };
	}

	function isDisabledDate(date) {
		var b = currentBounds();
		var d = startOfDay(date);
		if (b.min && d < startOfDay(b.min)) return true;
		if (b.max && d > startOfDay(b.max)) return true;
		return false;
	}

	function clearInvalid() {
		element.removeAttribute('aria-invalid');
		element.classList.remove('form-input--error');
		if (typeof element.setCustomValidity === 'function') {
			element.setCustomValidity('');
		}
		if (errorEl) {
			var tokens = (element.getAttribute('aria-describedby') || '')
				.split(/\s+/)
				.filter(function (token) { return token && token !== errorEl.id; });
			if (tokens.length) {
				element.setAttribute('aria-describedby', tokens.join(' '));
			} else {
				element.removeAttribute('aria-describedby');
			}
			if (errorEl.parentNode) errorEl.parentNode.removeChild(errorEl);
			errorEl = null;
		}
	}

	function markInvalid(message) {
		if (!element.id) {
			element.id = 'azc-date-' + Math.random().toString(36).slice(2);
		}
		element.setAttribute('aria-invalid', 'true');
		element.classList.add('form-input--error');
		if (typeof element.setCustomValidity === 'function') {
			element.setCustomValidity(message);
		}
		if (!errorEl) {
			errorEl = document.createElement('p');
			errorEl.className = 'field-error';
			errorEl.id = element.id + '-datepicker-error';
			errorEl.setAttribute('role', 'alert');
			var host = element.closest('.azc-datepicker-host') || element;
			host.insertAdjacentElement('afterend', errorEl);
		}
		errorEl.textContent = message;
		var tokens = (element.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
		if (tokens.indexOf(errorEl.id) === -1) {
			tokens.push(errorEl.id);
			element.setAttribute('aria-describedby', tokens.join(' '));
		}
	}

	/**
	 * Validate + normalize whatever the user typed. Returns true when the
	 * field now holds a valid in-range date (or is empty — emptiness is
	 * left to the form's own required handling).
	 */
	function commitTypedValue() {
		var raw = String(element.value || '').trim();
		if (!raw) {
			selectedDate = null;
			clearInvalid();
			return true;
		}
		var parsed = parseFlexibleDate(raw);
		if (!parsed) {
			markInvalid(t('Invalid date format. Please use dd.mm.yyyy (e.g., 15.01.2024).'));
			return false;
		}
		var b = currentBounds();
		var day = startOfDay(parsed);
		if (b.min && day < startOfDay(b.min)) {
			markInvalid(t('The date must be on or after %s.', [formatEuropean(startOfDay(b.min))]));
			return false;
		}
		if (b.max && day > startOfDay(b.max)) {
			markInvalid(t('The date must be on or before %s.', [formatEuropean(startOfDay(b.max))]));
			return false;
		}
		selectedDate = parsed;
		element.value = formatEuropean(parsed);
		clearInvalid();
		if (calendarOpen && calendarElement) {
			currentDate = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
			focusedDate = startOfDay(parsed);
			renderCalendarInto(calendarElement);
		}
		return true;
	}

	function closeCalendar(restoreFocus) {
		if (calendarElement && calendarElement.parentNode) {
			calendarElement.parentNode.removeChild(calendarElement);
		}
		calendarOpen = false;
		calendarElement = null;
		monthSelect = null;
		yearSelect = null;
		if (onDocKey) {
			document.removeEventListener('keydown', onDocKey, true);
			onDocKey = null;
		}
		if (onDocPointer) {
			document.removeEventListener('pointerdown', onDocPointer, true);
			onDocPointer = null;
		}
		if (onDocFocus) {
			document.removeEventListener('focusin', onDocFocus, true);
			onDocFocus = null;
		}
		element.setAttribute('aria-expanded', 'false');
		element.removeAttribute('aria-controls');
		if (restoreFocus && typeof element.focus === 'function') {
			/* focus() on an already-focused element fires no focus event —
			 * only suppress the reopen when the event will actually fire. */
			if (document.activeElement !== element) {
				suppressFocusOpen = true;
			}
			element.focus();
		}
	}

	function daysInMonth(year, monthIndex) {
		return new Date(year, monthIndex + 1, 0).getDate();
	}

	/** First enabled day inside the viewed month, or null. */
	function firstEnabledInView() {
		var dim = daysInMonth(currentDate.getFullYear(), currentDate.getMonth());
		for (var d = 1; d <= dim; d++) {
			var probe = new Date(currentDate.getFullYear(), currentDate.getMonth(), d);
			if (!isDisabledDate(probe)) return probe;
		}
		return null;
	}

	function focusDayButton(date) {
		if (!calendarElement) return;
		var btn = calendarElement.querySelector('[data-day="' + isoOf(date) + '"]');
		if (btn && typeof btn.focus === 'function') {
			btn.focus();
		}
	}

	function setFocusedDate(date, moveFocus) {
		focusedDate = startOfDay(date);
		if (focusedDate.getMonth() !== currentDate.getMonth()
			|| focusedDate.getFullYear() !== currentDate.getFullYear()) {
			currentDate = new Date(focusedDate.getFullYear(), focusedDate.getMonth(), 1);
		}
		if (calendarElement) {
			renderCalendarInto(calendarElement);
			if (moveFocus) focusDayButton(focusedDate);
		}
	}

	function shiftFocusedDay(days) {
		var base = focusedDate || selectedDate || new Date();
		var next = new Date(base.getFullYear(), base.getMonth(), base.getDate() + days);
		if (isDisabledDate(next)) return;
		setFocusedDate(next, true);
	}

	function shiftFocusedMonth(deltaMonths) {
		var base = focusedDate || selectedDate || new Date();
		var target = new Date(base.getFullYear(), base.getMonth() + deltaMonths, 1);
		target.setDate(Math.min(base.getDate(), daysInMonth(target.getFullYear(), target.getMonth())));
		if (isDisabledDate(target)) {
			var dim = daysInMonth(target.getFullYear(), target.getMonth());
			var found = null;
			for (var d = target.getDate(); d <= dim && !found; d++) {
				var fwd = new Date(target.getFullYear(), target.getMonth(), d);
				if (!isDisabledDate(fwd)) found = fwd;
			}
			for (var d2 = target.getDate(); d2 >= 1 && !found; d2--) {
				var back = new Date(target.getFullYear(), target.getMonth(), d2);
				if (!isDisabledDate(back)) found = back;
			}
			if (!found) return;
			target = found;
		}
		setFocusedDate(target, true);
	}

	function applyDate(date) {
		selectedDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
		element.value = formatEuropean(selectedDate);
		clearInvalid();
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function renderCalendarInto(container) {
		const cal = container.querySelector('.arbeitszeitcheck-datepicker-calendar');
		if (!cal) return;

		if (monthSelect) monthSelect.value = String(currentDate.getMonth());
		if (yearSelect) yearSelect.value = String(currentDate.getFullYear());

		cal.innerHTML = '';
		const dayHeader = document.createElement('div');
		dayHeader.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:8px;';
		dayNames.forEach(function (d) {
			const c = document.createElement('div');
			c.textContent = d;
			c.style.cssText = 'text-align:center;font-weight:600;font-size:12px;color:var(--color-text-maxcontrast);padding:4px;';
			c.setAttribute('aria-hidden', 'true');
			dayHeader.appendChild(c);
		});
		cal.appendChild(dayHeader);

		const firstDay = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
		const startDate = new Date(firstDay);
		startDate.setDate(startDate.getDate() - (firstDay.getDay() || 7) + 1);

		const today = new Date();
		today.setHours(0, 0, 0, 0);
		const daysGrid = document.createElement('div');
		daysGrid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:4px;';

		for (let i = 0; i < 42; i++) {
			const date = new Date(startDate);
			date.setDate(startDate.getDate() + i);
			const dayCell = document.createElement('button');
			dayCell.textContent = date.getDate();
			dayCell.type = 'button';
			dayCell.dataset.day = isoOf(date);
			dayCell.tabIndex = -1;
			dayCell.style.cssText = 'padding:8px;border:none;background:transparent;cursor:pointer;border-radius:4px;color:var(--color-main-text);';

			if (date.getMonth() !== currentDate.getMonth()) dayCell.style.opacity = '0.3';
			if (date.getTime() === today.getTime()) {
				dayCell.style.fontWeight = 'bold';
				dayCell.style.background = 'var(--color-primary-light, var(--color-primary-element))';
				dayCell.setAttribute('aria-current', 'date');
			}
			if (selectedDate && date.getTime() === startOfDay(selectedDate).getTime()) {
				dayCell.style.background = 'var(--color-primary-element)';
				dayCell.style.color = 'var(--color-primary-element-text)';
				dayCell.setAttribute('aria-pressed', 'true');
			}

			if (isDisabledDate(date)) {
				dayCell.style.opacity = '0.3';
				dayCell.style.cursor = 'not-allowed';
				dayCell.disabled = true;
				dayCell.setAttribute('aria-disabled', 'true');
			} else {
				dayCell.addEventListener('click', function () {
					applyDate(date);
					closeCalendar(true);
				});
				dayCell.addEventListener('mouseenter', function () {
					if (dayCell.style.background !== 'var(--color-primary-element)') {
						dayCell.style.background = 'var(--color-background-hover)';
					}
				});
				dayCell.addEventListener('mouseleave', function () {
					if (dayCell.style.background !== 'var(--color-primary-element)') dayCell.style.background = 'transparent';
				});
			}
			if (focusedDate && date.getTime() === focusedDate.getTime() && !dayCell.disabled) {
				dayCell.tabIndex = 0;
			}
			daysGrid.appendChild(dayCell);
		}
		cal.appendChild(daysGrid);
	}

	function rebuildYearOptions() {
		if (!yearSelect) return;
		var b = currentBounds();
		var now = new Date();
		var minYear = b.min ? b.min.getFullYear() : now.getFullYear() - 100;
		var maxYear = b.max ? b.max.getFullYear() : now.getFullYear() + 10;
		if (selectedDate) {
			minYear = Math.min(minYear, selectedDate.getFullYear());
			maxYear = Math.max(maxYear, selectedDate.getFullYear());
		}
		if (maxYear < minYear) maxYear = minYear;
		yearSelect.innerHTML = '';
		for (var y = minYear; y <= maxYear; y++) {
			var opt = document.createElement('option');
			opt.value = String(y);
			opt.textContent = String(y);
			yearSelect.appendChild(opt);
		}
		yearSelect.value = String(currentDate.getFullYear());
	}

	function openCalendar() {
		if (calendarOpen) return;
		if (fieldLocked()) return;

		/* Re-read input value in case it was updated externally */
		selectedDate = parseFlexibleDate(element.value);
		if (selectedDate) {
			currentDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
			focusedDate = startOfDay(selectedDate);
		} else if (typeof options.getInitialMonth === 'function') {
			var ref = options.getInitialMonth();
			if (ref && ref instanceof Date && !isNaN(ref.getTime())) {
				currentDate = new Date(ref.getFullYear(), ref.getMonth(), 1);
			} else {
				currentDate = new Date();
			}
			currentDate.setHours(0, 0, 0, 0);
			focusedDate = null;
		} else {
			currentDate = new Date();
			currentDate.setHours(0, 0, 0, 0);
			focusedDate = null;
		}
		if (!focusedDate || isDisabledDate(focusedDate)) {
			focusedDate = firstEnabledInView();
		}

		const container = document.createElement('div');
		container.className = 'arbeitszeitcheck-datepicker';
		container.id = 'azc-datepicker-popup';
		container.style.cssText = 'position:fixed;z-index:10000;background:var(--color-main-background);border:1px solid var(--color-border);border-radius:8px;padding:12px;box-shadow:var(--arbeitszeitcheck-shadow-md, 0 4px 12px var(--color-border));min-width:280px;';
		container.setAttribute('role', 'dialog');
		container.setAttribute('aria-label', t('Choose a date'));

		const header = document.createElement('div');
		header.className = 'arbeitszeitcheck-datepicker-header';
		header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:4px;';

		const prevBtn = document.createElement('button');
		prevBtn.type = 'button';
		prevBtn.innerHTML = '‹';
		prevBtn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:var(--color-main-text);flex:none;';
		prevBtn.setAttribute('aria-label', t('Previous month'));

		const nextBtn = document.createElement('button');
		nextBtn.type = 'button';
		nextBtn.innerHTML = '›';
		nextBtn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:var(--color-main-text);flex:none;';
		nextBtn.setAttribute('aria-label', t('Next month'));

		const selectStyle = 'flex:1 1 auto;min-width:0;max-width:62%;height:32px;padding:0 4px;font:inherit;font-weight:600;color:var(--color-main-text);background:var(--color-main-background);border:1px solid var(--color-border);border-radius:4px;cursor:pointer;';

		monthSelect = document.createElement('select');
		monthSelect.className = 'arbeitszeitcheck-datepicker__month-select';
		monthSelect.style.cssText = selectStyle;
		monthSelect.setAttribute('aria-label', t('Choose month'));
		monthNames.forEach(function (name, idx) {
			const opt = document.createElement('option');
			opt.value = String(idx);
			opt.textContent = name;
			monthSelect.appendChild(opt);
		});
		monthSelect.value = String(currentDate.getMonth());
		monthSelect.addEventListener('change', function () {
			var day = focusedDate ? focusedDate.getDate() : 1;
			currentDate.setMonth(parseInt(monthSelect.value, 10));
			var dim = daysInMonth(currentDate.getFullYear(), currentDate.getMonth());
			focusedDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), Math.min(day, dim));
			renderCalendarInto(container);
		});

		yearSelect = document.createElement('select');
		yearSelect.className = 'arbeitszeitcheck-datepicker__year-select';
		yearSelect.style.cssText = selectStyle;
		yearSelect.setAttribute('aria-label', t('Choose year'));
		yearSelect.addEventListener('change', function () {
			var day = focusedDate ? focusedDate.getDate() : 1;
			currentDate.setFullYear(parseInt(yearSelect.value, 10));
			var dim = daysInMonth(currentDate.getFullYear(), currentDate.getMonth());
			focusedDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), Math.min(day, dim));
			renderCalendarInto(container);
		});
		rebuildYearOptions();

		const cal = document.createElement('div');
		cal.className = 'arbeitszeitcheck-datepicker-calendar';

		header.appendChild(prevBtn);
		header.appendChild(monthSelect);
		header.appendChild(yearSelect);
		header.appendChild(nextBtn);
		container.appendChild(header);
		container.appendChild(cal);

		prevBtn.addEventListener('click', function () {
			currentDate.setMonth(currentDate.getMonth() - 1);
			if (focusedDate) {
				var dim = daysInMonth(currentDate.getFullYear(), currentDate.getMonth());
				focusedDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), Math.min(focusedDate.getDate(), dim));
			}
			renderCalendarInto(container);
		});
		nextBtn.addEventListener('click', function () {
			currentDate.setMonth(currentDate.getMonth() + 1);
			if (focusedDate) {
				var dim = daysInMonth(currentDate.getFullYear(), currentDate.getMonth());
				focusedDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), Math.min(focusedDate.getDate(), dim));
			}
			renderCalendarInto(container);
		});

		container.addEventListener('keydown', function (e) {
			/* Grid navigation only applies on day cells — arrows inside the
			 * month/year <select>s must keep their native stepping. */
			var onDay = e.target && e.target.hasAttribute && e.target.hasAttribute('data-day');
			if (!onDay) return;
			if (e.key === 'ArrowLeft') {
				e.preventDefault();
				shiftFocusedDay(-1);
			} else if (e.key === 'ArrowRight') {
				e.preventDefault();
				shiftFocusedDay(1);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				shiftFocusedDay(-7);
			} else if (e.key === 'ArrowDown') {
				e.preventDefault();
				shiftFocusedDay(7);
			} else if (e.key === 'PageUp') {
				e.preventDefault();
				shiftFocusedMonth(e.shiftKey ? -12 : -1);
			} else if (e.key === 'PageDown') {
				e.preventDefault();
				shiftFocusedMonth(e.shiftKey ? 12 : 1);
			} else if (e.key === 'Home') {
				e.preventDefault();
				var base = focusedDate || new Date();
				var back = (base.getDay() + 6) % 7;
				shiftFocusedDay(-back);
			} else if (e.key === 'End') {
				e.preventDefault();
				var baseE = focusedDate || new Date();
				var fwd = 6 - ((baseE.getDay() + 6) % 7);
				shiftFocusedDay(fwd);
			}
		});

		document.body.appendChild(container);
		calendarElement = container;
		calendarOpen = true;

		renderCalendarInto(container);

		/* Position with position:fixed (viewport-relative) so the calendar stays
		 * visible when #app-content scrolls. Use getBoundingClientRect() directly
		 * (no scrollY/scrollX) and flip above the input if there's no room below. */
		container.style.position = 'fixed';
		const rect = element.getBoundingClientRect();
		const calHeight = container.offsetHeight || 320;
		const headerOffset = (() => {
			const raw = getComputedStyle(document.body).getPropertyValue('--header-height').trim();
			const parsed = parseFloat(raw);
			return Number.isFinite(parsed) && parsed > 0 ? parsed : 50;
		})();
		const minTop = headerOffset + 8;
		const spaceBelow = window.innerHeight - rect.bottom;
		const showAbove = spaceBelow < calHeight && rect.top > calHeight;
		let top;
		if (showAbove) {
			top = Math.max(minTop, rect.top - calHeight - 4);
		} else {
			top = Math.min(window.innerHeight - calHeight - 8, rect.bottom + 4);
			if (top < minTop) {
				top = minTop;
			}
		}
		container.style.top = top + 'px';
		/* Keep within viewport horizontally */
		let left = rect.left;
		const calWidth = container.offsetWidth || 280;
		if (left + calWidth > window.innerWidth) left = window.innerWidth - calWidth - 8;
		if (left < 8) left = 8;
		container.style.left = left + 'px';

		element.setAttribute('aria-expanded', 'true');
		element.setAttribute('aria-controls', container.id);

		onDocKey = function (e) {
			if (e.key !== 'Escape') return;
			e.preventDefault();
			e.stopPropagation();
			closeCalendar(true);
		};
		document.addEventListener('keydown', onDocKey, true);

		onDocPointer = function (e) {
			if (!calendarElement) return;
			var target = e.target;
			if (!(target instanceof Node)) return;
			if (calendarElement.contains(target)) return;
			var host = element.closest('.azc-datepicker-host');
			if (host && host.contains(target)) return;
			if (target === element) return;
			closeCalendar(false);
		};
		document.addEventListener('pointerdown', onDocPointer, true);

		onDocFocus = function (e) {
			if (!calendarElement) return;
			var target = e.target;
			if (!(target instanceof Node)) return;
			if (calendarElement.contains(target)) return;
			var host = element.closest('.azc-datepicker-host');
			if (host && host.contains(target)) return;
			if (target === element) return;
			closeCalendar(false);
		};
		document.addEventListener('focusin', onDocFocus, true);
	}

	/* Typing is allowed: the field normalizes on commit (blur / change /
	 * Enter) and flags invalid values inline instead of blocking input. */
	element.removeAttribute('readonly');
	element.readOnly = false;
	element.setAttribute('autocomplete', 'off');
	element.setAttribute('inputmode', 'decimal');
	/* APG date-picker-dialog pattern: combobox owns aria-expanded/-controls. */
	element.setAttribute('role', 'combobox');
	element.setAttribute('aria-autocomplete', 'none');
	element.setAttribute('aria-haspopup', 'dialog');
	element.setAttribute('aria-expanded', 'false');

	const openOnFocus = options.openOnFocus !== false;

	element.addEventListener('focus', function () {
		if (!openOnFocus) return;
		if (suppressFocusOpen) {
			suppressFocusOpen = false;
			return;
		}
		openCalendar();
	});
	element.addEventListener('click', function () { openCalendar(); });
	element.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			if (calendarOpen) {
				e.preventDefault();
				e.stopPropagation();
				closeCalendar(true);
			}
			return;
		}
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			if (!calendarOpen) openCalendar();
			if (focusedDate) focusDayButton(focusedDate);
			return;
		}
		if (e.key === 'Enter') {
			if (commitTypedValue() && calendarOpen) {
				closeCalendar(false);
			}
			return;
		}
	});
	element.addEventListener('input', function () {
		/* User is actively fixing the field — drop the previous error so the
		 * message does not linger while they type. */
		if (errorEl) clearInvalid();
		var parsed = parseFlexibleDate(element.value);
		if (parsed && !isDisabledDate(parsed)) {
			selectedDate = parsed;
			if (calendarOpen && calendarElement) {
				currentDate = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
				focusedDate = startOfDay(parsed);
				renderCalendarInto(calendarElement);
			}
		}
	});
	element.addEventListener('change', function () {
		commitTypedValue();
	});
	element.addEventListener('blur', function () {
		commitTypedValue();
	});

	const parent = element.parentNode;
	const inDateRow = parent && (
		parent.classList.contains('form-input-wrapper--date')
		|| (parent.closest && parent.closest('.form-input-wrapper--date'))
		|| (parent.closest && parent.closest('.azc-date-range__part'))
	);
	const wrapper = document.createElement('div');
	wrapper.className = 'azc-datepicker-host' + (inDateRow ? ' azc-datepicker-host--flex' : '');
	element.parentNode.insertBefore(wrapper, element);
	wrapper.appendChild(element);

	const toggleBtn = document.createElement('button');
	toggleBtn.type = 'button';
	toggleBtn.className = 'azc-datepicker-toggle';
	if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
		toggleBtn.innerHTML = window.AzcCatalog.render('calendar', 'azc-datepicker-toggle__icon');
	} else {
		toggleBtn.textContent = '';
	}
	toggleBtn.setAttribute('aria-label', t('Open calendar'));
	toggleBtn.setAttribute('aria-haspopup', 'dialog');
	toggleBtn.addEventListener('click', function (e) {
		e.preventDefault();
		e.stopPropagation();
		if (calendarOpen) {
			closeCalendar(false);
		} else {
			openCalendar();
		}
	});
	wrapper.appendChild(toggleBtn);

	element.dataset.datepickerInit = '1';

	return { open: openCalendar, close: closeCalendar };
}

(function () {
	'use strict';
	if (typeof window !== 'undefined') {
		window.ArbeitszeitCheckDatepicker = {
			initializeDatepicker: initializeDatepicker,
			initInRoot: initInRoot,
			convertEuropeanToISO: convertEuropeanToISO,
			convertISOToEuropean: convertISOToEuropean,
			parseFlexibleDate: parseFlexibleDate
		};

		function parseDateFromAttr(val) {
			if (!val || val === 'today') return val === 'today' ? new Date() : null;
			if (/^\d{2}\.\d{2}\.\d{4}$/.test(val)) {
				var p = val.split('.');
				return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
			}
			if (/^\d{4}-\d{2}-\d{2}$/.test(val)) return new Date(val);
			return null;
		}

		function buildOptionsForElement(el) {
			var opts = {};
			var minVal = el.getAttribute('data-datepicker-min');
			var maxVal = el.getAttribute('data-datepicker-max');
			var minDate = minVal === 'today' ? new Date() : parseDateFromAttr(minVal);
			var maxDate = maxVal === 'today' ? new Date() : parseDateFromAttr(maxVal);
			if (minDate) opts.minDate = minDate;
			if (maxDate) opts.maxDate = maxDate;
			var syncId = el.getAttribute('data-datepicker-sync-month-with');
			if (syncId) {
				opts.getInitialMonth = function () {
					var other = document.getElementById(syncId);
					if (!other || !other.value) return null;
					return parseDDMMYYYYToDate(other.value);
				};
			}
			if (el.getAttribute('data-datepicker-min-sick')) opts.useDynamicMin = true;
			return opts;
		}

		function initAll() {
			document.querySelectorAll('.datepicker-input:not([data-datepicker-defer])').forEach(function (el) {
				if (el.dataset.datepickerInit === '1' || el.dataset.datepickerInit === 'true') return;
				if (el.closest('#time-entry-correction-source, [data-datepicker-defer]')) return;
				initializeDatepicker(el, buildOptionsForElement(el));
			});
		}

		/**
		 * Initialize datepickers inside a subtree (e.g. after dynamic HTML). Skips already-initialized inputs.
		 *
		 * @param {ParentNode|Document} [root]
		 */
		function initInRoot(root) {
			var scope = root && root.querySelectorAll ? root : document;
			scope.querySelectorAll('.datepicker-input:not([data-datepicker-defer])').forEach(function (el) {
				if (el.dataset.datepickerInit === '1' || el.dataset.datepickerInit === 'true') return;
				if (el.closest('#time-entry-correction-source, [data-datepicker-defer]')) return;
				initializeDatepicker(el, buildOptionsForElement(el));
			});
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initAll);
		} else {
			initAll();
		}
	}
})();
