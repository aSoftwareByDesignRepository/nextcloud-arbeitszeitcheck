/**
 * Datepicker utility for arbeitszeitcheck app
 * CSP-compliant datepicker with dd.mm.yyyy format (European)
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

function parseDDMMYYYYToDate(val) {
	if (!val || !/^\d{2}\.\d{2}\.\d{4}$/.test(val)) return null;
	var p = val.split('.');
	return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
}

/**
 * Initialize datepicker on input (dd.mm.yyyy format, calendar popup)
 * @param {HTMLElement|string} input - Input element or selector
 * @param {Object} options - { maxDate, minDate, getInitialMonth: () => Date|null }
 *   getInitialMonth: when opening, if provided and returns a Date, the calendar shows that month
 * @returns {Object} Datepicker instance
 */
function initializeDatepicker(input, options = {}) {
	const element = typeof input === 'string' ? document.querySelector(input) : input;
	if (!element) {
		console.error('[ArbeitszeitCheck] Datepicker: input not found');
		return null;
	}

	const t = (s) => (typeof window !== 'undefined' && window.t ? window.t('arbeitszeitcheck', s) : s);
	const monthNames = [t('January'), t('February'), t('March'), t('April'), t('May'), t('June'),
		t('July'), t('August'), t('September'), t('October'), t('November'), t('December')];
	const dayNames = [t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat'), t('Sun')];

	let selectedDate = parseDDMMYYYYToDate(element.value);
	let currentDate = selectedDate ? new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1) : new Date();
	currentDate.setHours(0, 0, 0, 0);

	let calendarOpen = false;
	let calendarElement = null;

	function closeCalendar() {
		if (calendarElement && calendarElement.parentNode) {
			calendarElement.parentNode.removeChild(calendarElement);
			calendarOpen = false;
			calendarElement = null;
		}
	}

	function renderCalendarInto(container) {
		const header = container.querySelector('.arbeitszeitcheck-datepicker-header');
		const cal = container.querySelector('.arbeitszeitcheck-datepicker-calendar');
		if (!header || !cal) return;

		const monthYear = header.querySelector('.arbeitszeitcheck-datepicker-monthyear');
		if (monthYear) monthYear.textContent = monthNames[currentDate.getMonth()] + ' ' + currentDate.getFullYear();

		cal.innerHTML = '';
		const dayHeader = document.createElement('div');
		dayHeader.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:8px;';
		dayNames.forEach(function (d) {
			const c = document.createElement('div');
			c.textContent = d;
			c.style.cssText = 'text-align:center;font-weight:600;font-size:12px;color:var(--color-text-maxcontrast);padding:4px;';
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
			dayCell.style.cssText = 'padding:8px;border:none;background:transparent;cursor:pointer;border-radius:4px;color:var(--color-main-text);';

			if (date.getMonth() !== currentDate.getMonth()) dayCell.style.opacity = '0.3';
			if (date.getTime() === today.getTime()) {
				dayCell.style.fontWeight = 'bold';
				dayCell.style.background = 'var(--color-primary-light, var(--color-primary-element))';
			}
			if (selectedDate && date.getTime() === selectedDate.getTime()) {
				dayCell.style.background = 'var(--color-primary-element)';
				dayCell.style.color = 'var(--color-primary-element-text)';
			}

			const disabled = (options.minDate && date < options.minDate) || (options.maxDate && date > options.maxDate);
			if (disabled) {
				dayCell.style.opacity = '0.3';
				dayCell.style.cursor = 'not-allowed';
				dayCell.setAttribute('aria-disabled', 'true');
				dayCell.setAttribute('tabindex', '-1');
			} else {
				dayCell.addEventListener('click', function () {
					selectedDate = new Date(date);
					const d = String(selectedDate.getDate()).padStart(2, '0');
					const m = String(selectedDate.getMonth() + 1).padStart(2, '0');
					const y = selectedDate.getFullYear();
					element.value = d + '.' + m + '.' + y;
					element.dispatchEvent(new Event('change', { bubbles: true }));
					closeCalendar();
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
			daysGrid.appendChild(dayCell);
		}
		cal.appendChild(daysGrid);
	}

	function openCalendar() {
		if (calendarOpen) return;

		/* Re-read input value in case it was updated externally */
		selectedDate = parseDDMMYYYYToDate(element.value);
		if (selectedDate) {
			currentDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
		} else if (typeof options.getInitialMonth === 'function') {
			var ref = options.getInitialMonth();
			if (ref && ref instanceof Date && !isNaN(ref.getTime())) {
				currentDate = new Date(ref.getFullYear(), ref.getMonth(), 1);
			} else {
				currentDate = new Date();
			}
		} else {
			currentDate = new Date();
		}
		currentDate.setHours(0, 0, 0, 0);

		const container = document.createElement('div');
		container.className = 'arbeitszeitcheck-datepicker';
		container.style.cssText = 'position:fixed;z-index:10000;background:var(--color-main-background);border:1px solid var(--color-border);border-radius:8px;padding:12px;box-shadow:var(--arbeitszeitcheck-shadow-md, 0 4px 12px var(--color-border));min-width:280px;';

		const header = document.createElement('div');
		header.className = 'arbeitszeitcheck-datepicker-header';
		header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;';

		const prevBtn = document.createElement('button');
		prevBtn.type = 'button';
		prevBtn.innerHTML = '‹';
		prevBtn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:var(--color-main-text);';
		prevBtn.setAttribute('aria-label', t('Previous month'));

		const monthYear = document.createElement('div');
		monthYear.className = 'arbeitszeitcheck-datepicker-monthyear';
		monthYear.style.cssText = 'font-weight:600;color:var(--color-main-text);';

		const nextBtn = document.createElement('button');
		nextBtn.type = 'button';
		nextBtn.innerHTML = '›';
		nextBtn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;padding:4px 8px;color:var(--color-main-text);';
		nextBtn.setAttribute('aria-label', t('Next month'));

		const cal = document.createElement('div');
		cal.className = 'arbeitszeitcheck-datepicker-calendar';

		header.appendChild(prevBtn);
		header.appendChild(monthYear);
		header.appendChild(nextBtn);
		container.appendChild(header);
		container.appendChild(cal);

		prevBtn.addEventListener('click', function () {
			currentDate.setMonth(currentDate.getMonth() - 1);
			renderCalendarInto(container);
		});
		nextBtn.addEventListener('click', function () {
			currentDate.setMonth(currentDate.getMonth() + 1);
			renderCalendarInto(container);
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
		const spaceBelow = window.innerHeight - rect.bottom;
		const showAbove = spaceBelow < calHeight && rect.top > calHeight;
		let top;
		if (showAbove) {
			top = Math.max(8, rect.top - calHeight - 4);
		} else {
			top = Math.min(window.innerHeight - calHeight - 8, rect.bottom + 4);
			if (top < 8) top = 8;
		}
		container.style.top = top + 'px';
		/* Keep within viewport horizontally */
		let left = rect.left;
		const calWidth = container.offsetWidth || 280;
		if (left + calWidth > window.innerWidth) left = window.innerWidth - calWidth - 8;
		if (left < 8) left = 8;
		container.style.left = left + 'px';

		setTimeout(function () {
			function closeOnOutside(e) {
				if (!calendarElement || !calendarElement.parentNode) {
					document.removeEventListener('click', closeOnOutside);
					return;
				}
				const inside = calendarElement.contains(e.target);
				const trigger = element.parentNode && (element.parentNode.contains(e.target) || e.target === element);
				if (!inside && !trigger) {
					closeCalendar();
					document.removeEventListener('click', closeOnOutside);
				}
			}
			document.addEventListener('click', closeOnOutside);
		}, 100);
	}

	element.setAttribute('readonly', 'readonly');
	element.setAttribute('autocomplete', 'off');
	element.readOnly = true;

	element.addEventListener('keydown', function (e) {
		if (e.key !== 'Tab' && e.key !== 'Escape' && e.key !== 'Enter') {
			e.preventDefault();
			openCalendar();
		}
	});
	element.addEventListener('focus', function () { openCalendar(); });
	element.addEventListener('click', function () { openCalendar(); });
	element.addEventListener('paste', function (e) { e.preventDefault(); });

	const wrapper = document.createElement('div');
	wrapper.style.cssText = 'position:relative;display:inline-block;width:100%;';
	element.parentNode.insertBefore(wrapper, element);
	wrapper.appendChild(element);

	const toggleBtn = document.createElement('button');
	toggleBtn.type = 'button';
	toggleBtn.innerHTML = '📅';
	toggleBtn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;padding:4px 8px;';
	toggleBtn.setAttribute('aria-label', t('Open calendar'));
	toggleBtn.addEventListener('click', function (e) {
		e.preventDefault();
		e.stopPropagation();
		openCalendar();
	});
	wrapper.appendChild(toggleBtn);

	return { open: openCalendar, close: closeCalendar };
}

(function () {
	'use strict';
	if (typeof window !== 'undefined') {
		window.ArbeitszeitCheckDatepicker = {
			initializeDatepicker: initializeDatepicker,
			convertEuropeanToISO: convertEuropeanToISO,
			convertISOToEuropean: convertISOToEuropean
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

		function initAll() {
			document.querySelectorAll('.datepicker-input').forEach(function (el) {
				if (el.dataset.datepickerInit) return;
				el.dataset.datepickerInit = '1';
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
				initializeDatepicker(el, opts);
			});
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initAll);
		} else {
			initAll();
		}
	}
})();
